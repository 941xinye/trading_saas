<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Risk\Breaker\BreakerStateMachine;
use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Domain\Risk\Contracts\PositionRiskMonitor;
use App\Domain\Risk\RiskGuard;
use App\Domain\Risk\Rules\ConsecutiveLossRule;
use App\Domain\Risk\Rules\MaxDailyLossRule;
use App\Domain\Risk\Rules\MaxDrawdownRule;
use App\Domain\Risk\Rules\MaxLossPerTradeRule;
use App\Domain\Strategy\Contracts\RecoverablePendingOrders;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\Contracts\StrategyState;
use App\Domain\Strategy\Recovery\PendingOrderRecovery;
use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Swoole\Coroutine;
use Throwable;

/**
 * 策略调度器（进程 B 内的协程容器）。
 *
 * 每个策略实例一个实例协程 + 一个 WS 行情协程：
 *  - 实例协程：装配（解密凭据 → 适配器 → 策略 → 状态恢复 → 风控）→ 订阅 → 保活
 *  - WS 协程（Adapter 内部）：onBar 回调在此执行决策链
 *
 * onBar 决策链（架构 §8）：
 *  strategy.onBar → shouldOpen/shouldClose → RiskGuard.check →
 *  OrderExecutor.place → strategy.onFill → RiskGuard.recordTrade
 *
 * 关键约束：
 *  - 崩溃恢复：Redis state（每 bar）+ DB position_snapshot（每 60s）双写
 *  - 熔断只停开新仓（check('close') 永远放行）
 *  - 被风控拒绝的信号以 status=failed 的订单回调 onFill，释放策略 pending 格
 */
class StrategyRunner
{
    /** @var array<int,array{cid:int,adapter:ExchangeAdapter,subscription:string}> instanceId → 协程信息 */
    private array $coroutines = [];

    public function __construct(
        private readonly ExchangeManager $exchangeManager,
        private readonly StrategyRegistry $registry,
        private readonly OrderExecutor $executor,
        private readonly KeyResolver $keyResolver,
        private readonly AesGcmCrypt $crypt,
        private readonly PendingOrderRecovery $pendingOrderRecovery,
        private readonly PositionRiskMonitor $positionRiskMonitor,
    ) {}

    /**
     * 当前进程内正在运行的实例 ID（供进程 B 热启停比对）。
     *
     * @return int[]
     */
    public function runningInstanceIds(): array
    {
        return array_keys($this->coroutines);
    }

    /**
     * 启动指定实例集合的协程调度。
     *
     * @param  int[]  $instanceIds  strategy_instances.id 列表
     */
    public function start(array $instanceIds): void
    {
        foreach ($instanceIds as $instanceId) {
            $instanceId = (int) $instanceId;
            if (isset($this->coroutines[$instanceId])) {
                continue; // 已在运行
            }

            $cid = Coroutine::create(function () use ($instanceId): void {
                try {
                    $this->runInstance($instanceId);
                } catch (Coroutine\CancelException) {
                    return; // stop() 主动取消
                } catch (Throwable $e) {
                    Log::error('Strategy instance coroutine crashed', [
                        'instance' => $instanceId, 'error' => $e->getMessage(),
                    ]);
                    $this->markInstanceError($instanceId, $e->getMessage());
                } finally {
                    unset($this->coroutines[$instanceId]);
                }
            });

            $this->coroutines[$instanceId] = ['cid' => $cid, 'adapter' => null, 'subscription' => ''];
        }
    }

    /**
     * 停止指定实例的协程（优雅退出，保留 Redis 状态供恢复）。
     */
    public function stop(int $instanceId): void
    {
        $info = $this->coroutines[$instanceId] ?? null;
        if ($info === null) {
            return;
        }

        unset($this->coroutines[$instanceId]);
        if ($info['adapter'] !== null && $info['subscription'] !== '') {
            try {
                $info['adapter']->unsubscribeStream($info['subscription']);
            } catch (Throwable) {
                // 保活协程被 cancel 时 WS 协程会自行退出
            }
        }
        if (Coroutine::exists($info['cid'])) {
            Coroutine::cancel($info['cid']);
        }
    }

    // ------------------------------------------------------------------
    // 实例装配与运行
    // ------------------------------------------------------------------

    private function runInstance(int $instanceId): void
    {
        $instance = DB::table('strategy_instances')->where('id', $instanceId)->first();
        if ($instance === null) {
            throw new RuntimeException("Strategy instance {$instanceId} not found");
        }

        $tenantId = (int) $instance->tenant_id;
        $strategyRow = DB::table('strategies')->where('id', $instance->strategy_id)->first(['code']);
        $account = DB::table('exchange_accounts')->where('id', $instance->exchange_account_id)->first();
        $exchange = DB::table('exchanges')->where('id', $account->exchange_id)->first(['code']);
        if ($strategyRow === null || $account === null || $exchange === null) {
            throw new RuntimeException("Instance {$instanceId} references missing strategy/exchange account");
        }

        // 凭据解密（KEK → DEK → API Key/Secret）
        $dek = $this->keyResolver->tenantDek($tenantId);
        $apiKey = $this->crypt->decrypt((string) $account->encrypted_api_key, $dek);
        $apiSecret = $this->crypt->decrypt((string) $account->encrypted_api_secret, $dek);
        if ((string) $account->encrypted_api_key === '' || $apiKey === null || $apiSecret === null) {
            throw new RuntimeException(
                "Instance {$instanceId}: exchange account credentials empty or undecryptable. "
                .'For testnet POC, set EXCHANGE_TESTNET_KEY/SECRET then re-run poc:testnet-setup.'
            );
        }

        $adapter = $this->exchangeManager->adapter(
            (string) $exchange->code,
            (string) $account->market,
            ['api_key' => $apiKey, 'api_secret' => $apiSecret],
        );

        $params = json_decode((string) $instance->params, true);
        $params = is_array($params) ? $params : [];
        $symbol = (string) $instance->symbol;
        $interval = (string) $instance->interval;

        // 运行期上下文参数：注入实例级事实（DSL 策略装载校验与数量换算需要），
        // 不改动持久化 params（params 以 DB 最新为准，避免把实例事实写回快照）。
        $runtimeParams = $params;
        $runtimeParams['symbol'] = $symbol;
        $runtimeParams['tenant_id'] = $tenantId;
        $runtimeParams['allocated_capital'] = (string) $instance->allocated_capital;

        $strategy = $this->registry->make(
            (string) $strategyRow->code,
            $instanceId,
            $params,
            new StrategyContext($instanceId, $runtimeParams, null, null, [], null),
        );

        // 崩溃恢复：Redis String JSON → StrategyState
        $stateKey = $this->stateKey($tenantId, $instanceId);
        $raw = Redis::connection('default')->get($stateKey);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $strategy->restoreState(StrategyState::fromArray($decoded));
            }
        }

        // 崩溃恢复增强（P5.5 步骤 1）：hydrate 后立即向交易所核实 pending 单。
        // 阻塞 REST 仅发生在启动期（CURL hook 关闭，坑 #1），订阅行情前完成；
        // filled 入账 / canceled/查无释放 / submitted 继续挂 / 网络异常保守保持阻塞。
        if ($strategy instanceof RecoverablePendingOrders) {
            $recoveryStats = $this->pendingOrderRecovery->recover(
                $strategy,
                $adapter,
                $symbol,
                $tenantId,
                $instanceId,
            );
            try {
                Redis::connection('default')->set(
                    $stateKey,
                    json_encode($strategy->getState()->toArray(), JSON_UNESCAPED_UNICODE),
                );
            } catch (Throwable $e) {
                Log::warning('Recovery state write failed', [
                    'instance' => $instanceId, 'error' => $e->getMessage(),
                ]);
            }
            Log::info('Pending order recovery finished', [
                'instance' => $instanceId] + $recoveryStats,
            );
        }

        // 风控装配：4 Rule + Redis 熔断状态机 + strategy_orders 重放恢复
        $defaults = (array) config('risk.defaults');
        $risk = new RiskGuard(
            [
                new MaxLossPerTradeRule((string) ($defaults['max_loss_per_trade']['threshold_value'] ?? 2)),
                new MaxDailyLossRule((string) ($defaults['max_daily_loss']['threshold_value'] ?? 5)),
                new MaxDrawdownRule((string) ($defaults['max_drawdown']['threshold_value'] ?? 10)),
                new ConsecutiveLossRule((int) ($defaults['consecutive_losses']['threshold_value'] ?? 5)),
            ],
            new CircuitBreaker(new BreakerStateMachine, $tenantId, $instanceId),
            (string) $instance->allocated_capital,
            $tenantId,
            $instanceId,
        );

        // 暂停态在进程重启后必须保留：只有 starting/stopped 等非暂停态推进为 running。
        // paused_by_breaker 由 Redis 熔断态拦开仓；paused_by_position_risk / paused_by_reconcile
        // 由 onBar 决策链拦开仓（平仓始终放行）。
        $pausedStatuses = ['paused_by_breaker', 'paused_by_position_risk', 'paused_by_reconcile'];
        $currentStatus = (string) $instance->status;
        $nextStatus = in_array($currentStatus, $pausedStatuses, true) ? $currentStatus : 'running';

        DB::table('strategy_instances')->where('id', $instanceId)->update([
            'status' => $nextStatus,
            'started_at' => $instance->started_at ?? now(),
            'updated_at' => now(),
        ]);

        // 决策链闭包状态（仅 WS 协程串行访问）
        $recentBars = [];
        $lastDbSnapshot = 0;
        $lastRiskEval = 0;  // 仓位风控评估节流（30s）

        $subscription = $adapter->subscribeKlineStream(
            $symbol,
            $interval,
            function (ExchangeKline $bar) use (
                &$recentBars,
                &$lastDbSnapshot,
                &$lastRiskEval,
                $strategy,
                $risk,
                $adapter,
                $instance,
                $instanceId,
                $tenantId,
                $symbol,
                $stateKey,
            ): void {
                $recentBars[] = $bar;
                if (count($recentBars) > 30) {
                    $recentBars = array_slice($recentBars, -30);
                }

                try {
                    $strategy->onBar($bar);

                    $ctx = new StrategyContext($instanceId, $strategy->getState()->params, null, null, $recentBars, null);

                    // 暂停态禁止开仓、允许平仓：paused_by_position_risk（仓位风控）
                    // 与 paused_by_reconcile（账实对账 critical）都在决策入口拦截。
                    $status = (string) DB::table('strategy_instances')->where('id', $instanceId)->value('status');
                    $pausedByRisk = in_array($status, ['paused_by_position_risk', 'paused_by_reconcile'], true);

                    // shouldOpen/shouldClose 均消费内部 pending 信号，只能调用一次
                    $open = $pausedByRisk ? null : $strategy->shouldOpen($ctx);
                    $close = $open !== null ? null : $strategy->shouldClose($ctx);
                    $signal = $open ?? $close;
                    $action = $open !== null ? 'open' : 'close';

                    if ($signal !== null) {
                        $this->executeSignal(
                            $action,
                            $signal,
                            $instance,
                            $tenantId,
                            $instanceId,
                            $symbol,
                            $adapter,
                            $strategy,
                            $risk,
                        );
                    }

                    // 仓位级风控（仅持仓时评估，30s 节流避免频繁 REST getPosition）
                    $this->evaluatePositionRisk(
                        $strategy, $adapter, $instance, $tenantId, $instanceId, $symbol,
                        $bar->close, $lastRiskEval,
                    );
                } catch (Throwable $e) {
                    Log::error('onBar decision failed', [
                        'instance' => $instanceId, 'error' => $e->getMessage(),
                    ]);
                }

                // 每 bar 写 Redis（先 Redis 后 DB，DB 失败有 Redis 兜底）
                try {
                    Redis::connection('default')->set(
                        $stateKey,
                        json_encode($strategy->getState()->toArray(), JSON_UNESCAPED_UNICODE),
                    );
                } catch (Throwable $e) {
                    Log::warning('Redis state write failed', [
                        'instance' => $instanceId, 'error' => $e->getMessage(),
                    ]);
                }

                // 每 60s 刷 DB 快照
                if (time() - $lastDbSnapshot >= (int) config('strategy.state.db_snapshot_interval', 60)) {
                    $lastDbSnapshot = time();
                    $this->flushDbSnapshot($instanceId, $strategy, $risk);
                }
            },
        );

        // 记录订阅信息供 stop() 使用
        $this->coroutines[$instanceId] = ['cid' => Coroutine::getCid(), 'adapter' => $adapter, 'subscription' => $subscription];

        // 实例协程保活：监听外部停机（DB status → stopped）
        while (true) {
            Coroutine::sleep(5);

            $status = DB::table('strategy_instances')->where('id', $instanceId)->value('status');
            if ($status === 'stopped') {
                $this->stop($instanceId);

                return;
            }
        }
    }

    /**
     * 单信号执行：attach → 风控 → 下单 → onFill → recordTrade。
     *
     * @param  array{side:string,qty:string,price:?string}  $signal
     */
    private function executeSignal(
        string $action,
        array $signal,
        object $instance,
        int $tenantId,
        int $instanceId,
        string $symbol,
        ExchangeAdapter $adapter,
        StrategyInterface $strategy,
        RiskGuard $risk,
    ): void {
        $clientOrderId = OrderExecutor::generateClientOrderId($tenantId, $instanceId);
        // 先绑定格位关联（attachClientOrderId 消费最近信号元数据），风控拒绝/下单失败都走 onFill 释放
        $strategy->attachClientOrderId($clientOrderId, (string) $signal['side'], (string) $signal['qty']);

        $decision = $risk->check($instanceId, $action);
        if (! $decision->allowed) {
            Log::info('Order blocked by risk guard', [
                'instance' => $instanceId, 'action' => $action, 'reason' => $decision->reason,
            ]);
            $strategy->onFill($this->failedFill($symbol, $signal, $clientOrderId));

            return;
        }

        $order = $this->executor->place(
            $tenantId,
            $instance,
            $adapter,
            $signal + ['client_order_id' => $clientOrderId],
        );
        if ($order === null) {
            $strategy->onFill($this->failedFill($symbol, $signal, $clientOrderId));

            return;
        }

        $strategy->onFill($order);
        $risk->recordTrade($instanceId, $order);
    }

    /**
     * 仓位级风控评估（30s 节流，仅持仓时触发）。
     *
     * reduce/emergency_close 决策构造为平仓信号走 executeSignal('close', reduceOnly=true)。
     * warning 仅通知（PositionRiskMonitor 内部已分发事件），无需下单。
     */
    private function evaluatePositionRisk(
        StrategyInterface $strategy,
        ExchangeAdapter $adapter,
        object $instance,
        int $tenantId,
        int $instanceId,
        string $symbol,
        string $markPrice,
        int &$lastRiskEval,
    ): void {
        // 仅对有 currentPositionQty 方法的策略（网格类）且有持仓时评估
        if (! method_exists($strategy, 'currentPositionQty')) {
            return;
        }
        $posQty = (string) $strategy->currentPositionQty();
        if ($posQty === '0' || $posQty === '') {
            return;
        }

        // 30s 节流
        $now = time();
        if ($now - $lastRiskEval < 30) {
            return;
        }
        $lastRiskEval = $now;

        try {
            $exchangePosition = $adapter->getPosition($symbol);
        } catch (Throwable $e) {
            Log::warning('Position risk: getPosition failed', [
                'instance' => $instanceId, 'error' => $e->getMessage(),
            ]);

            return;
        }

        $decision = $this->positionRiskMonitor->evaluate(
            $tenantId, $instanceId, $symbol, $exchangePosition, $markPrice,
        );

        if ($decision === null || ! in_array($decision->actionType, ['reduce', 'emergency_close'], true)) {
            return;
        }

        // 构造平仓信号（reduceOnly=true）
        $closeSide = $posQty > 0 ? 'sell' : 'buy'; // 多仓卖、空仓买
        $signal = [
            'side' => $closeSide,
            'qty' => $decision->orderQty ?? ltrim($posQty, '-'),
            'price' => null,
            'reduce_only' => true,
            'leverage' => $exchangePosition?->leverage ?? null,
        ];

        // 紧急全平时标记实例为 paused_by_position_risk
        if ($decision->actionType === 'emergency_close') {
            DB::table('strategy_instances')->where('id', $instanceId)->update([
                'status' => 'paused_by_position_risk',
                'updated_at' => now(),
            ]);
        }

        $this->executeSignal(
            'close',
            $signal,
            $instance,
            $tenantId,
            $instanceId,
            $symbol,
            $adapter,
            $strategy,
            new RiskGuard([], new CircuitBreaker(new BreakerStateMachine, $tenantId, $instanceId), '0', $tenantId, $instanceId),
        );
    }

    private function failedFill(string $symbol, array $signal, string $clientOrderId): ExchangeOrder
    {
        return new ExchangeOrder(
            exchangeOrderId: '0',
            clientOrderId: $clientOrderId,
            symbol: $symbol,
            side: (string) $signal['side'],
            type: 'market',
            quantity: (string) $signal['qty'],
            price: null,
            stopPrice: null,
            leverage: null,
            reduceOnly: false,
            status: 'failed',
            filledQty: '0',
            avgFillPrice: null,
            fee: null,
            placedAt: time(),
            filledAt: null,
        );
    }

    private function flushDbSnapshot(int $instanceId, StrategyInterface $strategy, RiskGuard $risk): void
    {
        try {
            $state = $strategy->getState();
            DB::table('strategy_instances')->where('id', $instanceId)->update([
                'position_snapshot' => json_encode($state->positionSnapshot, JSON_UNESCAPED_UNICODE),
                'realized_pnl' => $state->realizedPnl,
                'consecutive_losses' => $state->consecutiveLosses,
                'breaker_state' => $risk->getBreakerState($instanceId),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('DB snapshot flush failed', [
                'instance' => $instanceId, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function markInstanceError(int $instanceId, string $message): void
    {
        try {
            DB::table('strategy_instances')->where('id', $instanceId)->update([
                'status' => 'error',
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // DB 不可用时仅记日志
        }
        Log::error('Instance marked as error', ['instance' => $instanceId, 'message' => $message]);
    }

    private function stateKey(int $tenantId, int $instanceId): string
    {
        return str_replace(
            ['{tenant_id}', '{instance_id}'],
            [$tenantId, $instanceId],
            (string) config('strategy.state.key_prefix', 'tenant:{tenant_id}:strategy:{instance_id}:state'),
        );
    }
}
