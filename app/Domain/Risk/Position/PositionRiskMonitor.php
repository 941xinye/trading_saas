<?php

declare(strict_types=1);

namespace App\Domain\Risk\Position;

use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Risk\Contracts\LiquidationPriceCalculator;
use App\Domain\Risk\Contracts\PositionRiskDecision;
use App\Domain\Risk\Contracts\PositionRiskMonitor as PositionRiskMonitorContract;
use App\Events\PositionRiskAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * 仓位级风控监控器实现。
 *
 * 三档阈值判定（config/risk.php position 块）：
 *  - marginRatio > warning_margin_ratio → 无动作
 *  - warning ≥ marginRatio > action → 预警通知（15min 去重）
 *  - action ≥ marginRatio > emergency → 减仓（reduceOnly，reduce_ratio 比例）
 *  - marginRatio ≤ emergency → 市价全平
 *
 * 单飞锁：减仓/全平前获取 Redis 锁（TTL lock_ttl），防止与网格平仓并发。
 */
class PositionRiskMonitor implements PositionRiskMonitorContract
{
    public function __construct(
        private readonly LiquidationPriceCalculator $calculator,
        private readonly PositionRiskEventRepository $events,
    ) {
    }

    public function evaluate(
        int $tenantId,
        int $instanceId,
        string $symbol,
        ?ExchangePosition $position,
        string $markPrice,
    ): ?PositionRiskDecision {
        // 无持仓 → 无风控动作
        if ($position === null) {
            return null;
        }

        $config = config('risk.position', []);
        $warning = (string) ($config['warning_margin_ratio'] ?? '0.50');
        $action = (string) ($config['action_margin_ratio'] ?? '0.20');
        $emergency = (string) ($config['emergency_margin_ratio'] ?? '0.10');

        // 维持保证金率：优先用交易所持仓的杠杆反推，否则用 config 默认值
        $mmr = $this->resolveMmr($symbol, $position->leverage);

        // 保证金率 = 距强平价的剩余缓冲比例（入场=1.0，强平=0）
        $marginRatio = $this->calculator->marginRatio(
            $position->entryPrice,
            $markPrice,
            $mmr,
            $position->leverage,
            $position->side,
        );

        $positionBefore = [
            'size' => $position->size,
            'entry_price' => $position->entryPrice,
            'unrealized_pnl' => $position->unrealizedPnl,
        ];

        // 紧急：全平
        if (bccomp($marginRatio, $emergency, 12) <= 0) {
            return $this->handleAction(
                $tenantId, $instanceId, $symbol, $position, $marginRatio,
                'emergency_close', 'margin_ratio_emergency',
                $positionBefore, $position->size, // 全平
            );
        }

        // 行动：减仓
        if (bccomp($marginRatio, $action, 12) <= 0) {
            $reduceRatio = (string) ($config['reduce_ratio'] ?? '0.50');
            $reduceQty = bcmul($position->size, $reduceRatio, 8);

            return $this->handleAction(
                $tenantId, $instanceId, $symbol, $position, $marginRatio,
                'reduce', 'margin_ratio_action',
                $positionBefore, $reduceQty,
            );
        }

        // 预警：仅通知
        if (bccomp($marginRatio, $warning, 12) <= 0) {
            return $this->handleWarning(
                $tenantId, $instanceId, $symbol, $position, $marginRatio, $positionBefore,
            );
        }

        // 安全区：无动作
        return null;
    }

    /**
     * 处理减仓/全平动作。
     */
    private function handleAction(
        int $tenantId,
        int $instanceId,
        string $symbol,
        ExchangePosition $position,
        string $marginRatio,
        string $actionType,
        string $reason,
        array $positionBefore,
        string $orderQty,
    ): PositionRiskDecision {
        // 单飞锁：防止与网格平仓并发重复下单
        $lockKey = "tenant:{$tenantId}:position_risk:{$instanceId}:lock";
        $lockTtl = (int) config('risk.position.lock_ttl', 30);

        $locked = false;
        try {
            $locked = (bool) Redis::connection('default')->set($lockKey, '1', 'NX', 'EX', $lockTtl);
        } catch (Throwable) {
            // Redis 不可用：保守不放行减仓（避免重复），但记日志
            Log::warning('Position risk lock unavailable, skipping action', [
                'instance' => $instanceId, 'action' => $actionType,
            ]);

            return new PositionRiskDecision(
                actionType: 'skipped',
                reason: 'lock_unavailable',
                marginRatio: $marginRatio,
            );
        }

        if (! $locked) {
            // 锁竞争失败：同 bar 内已有风控动作在执行
            return new PositionRiskDecision(
                actionType: 'skipped',
                reason: 'lock_contended',
                marginRatio: $marginRatio,
            );
        }

        // 写审计（pending，动作执行后由调用方更新）
        $this->events->create([
            'tenant_id' => $tenantId,
            'instance_id' => $instanceId,
            'symbol' => $symbol,
            'position_side' => $position->side,
            'action_type' => $actionType,
            'trigger_reason' => $reason,
            'margin_ratio' => $marginRatio,
            'mark_price' => $position->markPrice,
            'liquidation_price' => $position->liquidationPrice,
            'position_before' => $positionBefore,
            'status' => 'pending',
        ]);

        // 分发告警事件（通知链）
        $this->dispatchAlert($tenantId, $instanceId, $symbol, $actionType, $marginRatio, $position);

        return new PositionRiskDecision(
            actionType: $actionType,
            reason: $reason,
            marginRatio: $marginRatio,
            orderQty: $orderQty,
            reduceOnly: true,
        );
    }

    /**
     * 处理预警：仅通知 + 审计，不下单。
     */
    private function handleWarning(
        int $tenantId,
        int $instanceId,
        string $symbol,
        ExchangePosition $position,
        string $marginRatio,
        array $positionBefore,
    ): PositionRiskDecision {
        // 15min 去重：同 instanceId+warning 只通知一次
        $dedupKey = "tenant:{$tenantId}:position_risk:{$instanceId}:warned";
        $dedupTtl = (int) config('risk.position.dedup_cooldown', 900);

        try {
            $isFirst = (bool) Redis::connection('default')->set($dedupKey, '1', 'NX', 'EX', $dedupTtl);
        } catch (Throwable) {
            $isFirst = true; // Redis 不可用：不阻塞预警
        }

        if ($isFirst) {
            $this->events->create([
                'tenant_id' => $tenantId,
                'instance_id' => $instanceId,
                'symbol' => $symbol,
                'position_side' => $position->side,
                'action_type' => 'warning',
                'trigger_reason' => 'margin_ratio_warning',
                'margin_ratio' => $marginRatio,
                'mark_price' => $position->markPrice,
                'liquidation_price' => $position->liquidationPrice,
                'position_before' => $positionBefore,
                'status' => 'executed',
            ]);

            $this->dispatchAlert($tenantId, $instanceId, $symbol, 'warning', $marginRatio, $position);
        }

        return new PositionRiskDecision(
            actionType: 'warning',
            reason: 'margin_ratio_warning',
            marginRatio: $marginRatio,
        );
    }

    /**
     * 解析维持保证金率。
     * 优先从杠杆档位配置获取，否则用 config 默认值。
     */
    private function resolveMmr(string $symbol, int $leverage): string
    {
        $defaultMmr = config('risk.position.default_mmr', []);

        return (string) ($defaultMmr[$symbol] ?? '0.0050');
    }

    /**
     * 分发 PositionRiskAlert 事件（best-effort，失败不阻塞）。
     */
    private function dispatchAlert(
        int $tenantId,
        int $instanceId,
        string $symbol,
        string $actionType,
        string $marginRatio,
        ExchangePosition $position,
    ): void {
        try {
            event(new PositionRiskAlert(
                tenantId: $tenantId,
                instanceId: $instanceId,
                symbol: $symbol,
                actionType: $actionType,
                marginRatio: $marginRatio,
                markPrice: $position->markPrice,
                liquidationPrice: $position->liquidationPrice,
            ));
        } catch (Throwable $e) {
            Log::warning('PositionRiskAlert dispatch failed', [
                'instance' => $instanceId, 'error' => $e->getMessage(),
            ]);
        }
    }
}
