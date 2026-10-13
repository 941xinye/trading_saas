<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Risk\Breaker\BreakerMetrics;
use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Domain\Risk\Contracts\RiskDecision;
use App\Domain\Risk\Contracts\RiskGuard as RiskGuardContract;
use App\Domain\Risk\Contracts\RiskRule;
use Illuminate\Support\Facades\DB;
use bccomp;
use bcmul;
use Throwable;

/**
 * 风控守卫实现。
 *
 * 编排多个 RiskRule（单笔/日内/回撤/连亏）+ CircuitBreaker 状态机。
 * 在 Strategy.shouldOpen 与 ExchangeAdapter.placeOrder 之间拦截。
 *
 * 流程：
 *  1. check('close') 永远放行（熔断只停开新仓，不平已有持仓）
 *  2. check('open')：OPEN → 拒；HALF_OPEN → attemptProbe 放行一笔探测；
 *     CLOSED → 4 个 Rule 顺序检查，任一触发 → breaker.trip → 拒
 *  3. recordTrade() 内部维护加权成本追踪器，sell 成交时计算已实现盈亏，
 *     驱动 BreakerMetrics 4 维度；HALF_OPEN 下探测单卖出盈利 → recover，
 *     亏损 → 按当前触发的 Rule 重新 trip
 *
 * 崩溃恢复：构造时从 strategy_orders 按序重放 filled 订单，
 * 重建成本基础 + 日内亏损/连亏计数（全量重放，取值偏保守）。
 *
 * 关键约束：仅业务亏损触发熔断，API 异常不触发。
 */
class RiskGuard implements RiskGuardContract
{
    private BreakerMetrics $metrics;

    /** @var array<string,array{qty:string,costTotal:string}> 加权成本追踪（symbol → 持仓） */
    private array $positions = [];

    /**
     * @param RiskRule[]     $rules            4 维度规则集合
     * @param CircuitBreaker $breaker          熔断状态机
     * @param string         $allocatedCapital 分配资金（quote 计价）
     * @param int|null       $tenantId         传入则以 strategy_orders 重放恢复
     * @param int|null       $instanceId       同上
     */
    public function __construct(
        private readonly array $rules,
        private readonly CircuitBreaker $breaker,
        private readonly string $allocatedCapital,
        ?int $tenantId = null,
        ?int $instanceId = null,
    ) {
        $this->metrics = new BreakerMetrics(peakEquity: $allocatedCapital);

        if ($tenantId !== null && $instanceId !== null) {
            $this->restoreFromOrders($tenantId, $instanceId);
        }
    }

    public function check(int $instanceId, string $action, array $payload = []): RiskDecision
    {
        // 关键约束：熔断只停开新仓，平仓动作永远放行
        if ($action === 'close') {
            return $this->decide(true, 'close_allowed');
        }

        $state = $this->breaker->state();

        if ($state === CircuitBreaker::STATE_OPEN) {
            return $this->decide(false, 'breaker_open');
        }

        if ($state === CircuitBreaker::STATE_HALF_OPEN) {
            return $this->breaker->attemptProbe()
                ? $this->decide(true, 'probe_allowed')
                : $this->decide(false, 'probe_cooling');
        }

        // CLOSED：4 维度 Rule 顺序检查
        foreach ($this->rules as $rule) {
            $triggered = $rule->check($this->metrics);
            if ($triggered !== null) {
                $this->breaker->trip(
                    $triggered,
                    $this->metricValue($triggered),
                    (string) config("risk.defaults.{$triggered}.threshold_value", '0'),
                );

                return $this->decide(false, $triggered);
            }
        }

        return $this->decide(true, 'closed_ok');
    }

    public function recordTrade(int $instanceId, ExchangeOrder $fill): void
    {
        $pnl = $this->trackFill($fill);

        // 买入无已实现盈亏，仅累积成本；卖出驱动 4 维度指标
        if ($pnl === null) {
            return;
        }

        $this->metrics->recordTrade($pnl, $this->allocatedCapital);

        // 探测单成交回调：盈利 → 恢复 CLOSED；亏损 → 重新熔断
        if ($this->breaker->state() === CircuitBreaker::STATE_HALF_OPEN) {
            if (bccomp($pnl, '0', 12) !== -1) {
                $this->breaker->recover();

                return;
            }

            $rule = 'max_loss_per_trade';
            foreach ($this->rules as $r) {
                $triggered = $r->check($this->metrics);
                if ($triggered !== null) {
                    $rule = $triggered;
                    break;
                }
            }
            $this->breaker->trip(
                $rule,
                $this->metricValue($rule),
                (string) config("risk.defaults.{$rule}.threshold_value", '0'),
            );
        }
    }

    public function attemptRecovery(int $instanceId): bool
    {
        if ($this->breaker->state() !== CircuitBreaker::STATE_HALF_OPEN) {
            return false;
        }

        $this->breaker->recover();

        return true;
    }

    public function getBreakerState(int $instanceId): string
    {
        return $this->breaker->state();
    }

    /**
     * 供 Runner 刷新 DB 快照用。
     */
    public function metrics(): BreakerMetrics
    {
        return $this->metrics;
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    private function decide(bool $allowed, string $reason): RiskDecision
    {
        return new RiskDecision(
            allowed: $allowed,
            reason: $reason,
            breakerState: $this->breaker->state(),
        );
    }

    private function metricValue(string $ruleType): string
    {
        return match ($ruleType) {
            'max_loss_per_trade' => $this->metrics->getLastTradeLossPct(),
            'max_daily_loss' => $this->metrics->getDailyLossPct(),
            'max_drawdown' => $this->metrics->getCurrentDrawdownPct(),
            'consecutive_losses' => (string) $this->metrics->getConsecutiveLosses(),
            default => '0',
        };
    }

    /**
     * 加权成本追踪：buy 累积成本，sell 计算已实现盈亏。
     *
     * @return string|null 已实现盈亏（sell），buy 返回 null
     */
    private function trackFill(ExchangeOrder $fill): ?string
    {
        if ($fill->status !== 'filled' && $fill->status !== 'partial') {
            return null;
        }

        $qty = $fill->filledQty !== '0' ? $fill->filledQty : $fill->quantity;
        $price = $fill->avgFillPrice ?? $fill->price ?? '0';
        if (bccomp($qty, '0', 12) !== 1 || bccomp($price, '0', 12) !== 1) {
            return null;
        }

        $pos = $this->positions[$fill->symbol] ?? ['qty' => '0', 'costTotal' => '0'];

        if ($fill->side === 'buy') {
            $pos['qty'] = bcadd($pos['qty'], $qty, 12);
            $pos['costTotal'] = bcadd($pos['costTotal'], bcmul($price, $qty, 12), 12);
            $this->positions[$fill->symbol] = $pos;

            return null;
        }

        // sell：加权平均成本 → 已实现盈亏
        if (bccomp($pos['qty'], '0', 12) !== 1) {
            return null; // 无成本基础（如恢复前数据缺失），不计盈亏
        }

        $avgCost = bcdiv($pos['costTotal'], $pos['qty'], 12);
        $sellQty = bccomp($qty, $pos['qty'], 12) === 1 ? $pos['qty'] : $qty;
        $pnl = bcsub(bcmul($price, $sellQty, 12), bcmul($avgCost, $sellQty, 12), 12);

        $pos['qty'] = bcsub($pos['qty'], $sellQty, 12);
        $pos['costTotal'] = bcsub($pos['costTotal'], bcmul($avgCost, $sellQty, 12), 12);
        if (bccomp($pos['qty'], '0', 12) !== 1) {
            $pos = ['qty' => '0', 'costTotal' => '0'];
        }
        $this->positions[$fill->symbol] = $pos;

        return $pnl;
    }

    /**
     * 从 strategy_orders 重放 filled 订单恢复成本基础与指标。
     * 全量重放（不限当日）：成本基础需要历史买单；
     * dailyLossPct/consecutiveLosses 取全历史，偏保守（可接受）。
     */
    private function restoreFromOrders(int $tenantId, int $instanceId): void
    {
        try {
            $rows = DB::table('strategy_orders')
                ->where('tenant_id', $tenantId)
                ->where('strategy_instance_id', $instanceId)
                ->whereIn('status', ['filled', 'partial'])
                ->orderBy('id')
                ->get(['side', 'symbol', 'filled_qty', 'avg_fill_price', 'price', 'quantity']);

            foreach ($rows as $row) {
                $pnl = $this->trackFill(new ExchangeOrder(
                    exchangeOrderId: '0',
                    clientOrderId: '',
                    symbol: (string) $row->symbol,
                    side: (string) $row->side,
                    type: 'market',
                    quantity: (string) $row->quantity,
                    price: $row->price !== null ? (string) $row->price : null,
                    stopPrice: null,
                    leverage: null,
                    reduceOnly: false,
                    status: (string) $row->status,
                    filledQty: (string) $row->filled_qty,
                    avgFillPrice: $row->avg_fill_price !== null ? (string) $row->avg_fill_price : null,
                    fee: '0',
                    placedAt: null,
                    filledAt: null,
                ));

                // 卖出成交重放：直接驱动指标（不走 recordTrade，避免误触熔断动作）
                if ($pnl !== null) {
                    $this->metrics->recordTrade($pnl, $this->allocatedCapital);
                }
            }
        } catch (Throwable) {
            // 恢复失败按全新实例处理（内存指标从零累计）
        }
    }
}
