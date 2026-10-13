<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Risk\Breaker\BreakerStateMachine;
use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Domain\Risk\RiskGuard;
use App\Domain\Risk\Rules\ConsecutiveLossRule;
use App\Domain\Risk\Rules\MaxDailyLossRule;
use App\Domain\Risk\Rules\MaxDrawdownRule;
use App\Domain\Risk\Rules\MaxLossPerTradeRule;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\Contracts\StrategyState;
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
    ) {
    }

    /**
     * 启动指定实例集合的协程调度。
     *
     * @param int[] $instanceIds strategy_instances.id 列表
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
        if ($apiKey === null || $apiSecret === null) {
            throw new RuntimeException("Failed to decrypt exchange credentials (instance {$instanceId})");
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

        $strategy = $this->registry->make(
            (string) $strategyRow->code,
            $instanceId,
            $params,
            new StrategyContext($instanceId, $params, null, null, [], null),
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

        // 风控装配：4 Rule + Redis 熔断状态机 + strategy_orders 重放恢复
        $defaults = (array) config('risk.defaults');
        $risk = new RiskGuard(
            [
                new MaxLossPerTradeRule((string) ($defaults['max_loss_per_trade']['threshold_value'] ?? 2)),
                new MaxDailyLossRule((string) ($defaults['max_daily_loss']['threshold_value'] ?? 5)),
                new MaxDrawdownRule((string) ($defaults['max_drawdown']['threshold_value'] ?? 10)),
                new ConsecutiveLossRule((int) ($defaults['consecutive_losses']['threshold_value'] ?? 5)),
            ],
            new CircuitBreaker(new BreakerStateMachine(), $tenantId, $instanceId),
            (string) $instance->allocated_capital,
            $tenantId,
            $instanceId,
        );

        DB::table('strategy_instances')->where('id', $instanceId)->update([
            'status' => 'running',
            'started_at' => $instance->started_at ?? now(),
            'updated_at' => now(),
        ]);

        // 决策链闭包状态（仅 WS 协程串行访问）
        $recentBars = [];
        $lastDbSnapshot = 0;

        $subscription = $adapter->subscribeKlineStream(
            $symbol,
            $interval,
            function (ExchangeKline $bar) use (
                &$recentBars,
                &$lastDbSnapshot,
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

                    // shouldOpen/shouldClose 均消费内部 pending 信号，只能调用一次
                    $open = $strategy->shouldOpen($ctx);
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
     * @param array{side:string,qty:string,price:?string} $signal
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
