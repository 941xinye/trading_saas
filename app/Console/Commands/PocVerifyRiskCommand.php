<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Risk\Breaker\BreakerMetrics;
use App\Domain\Risk\Breaker\BreakerStateMachine;
use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Domain\Risk\RiskGuard;
use App\Domain\Risk\Rules\ConsecutiveLossRule;
use App\Domain\Risk\Rules\MaxDailyLossRule;
use App\Domain\Risk\Rules\MaxDrawdownRule;
use App\Domain\Risk\Rules\MaxLossPerTradeRule;
use App\Events\BreakerTripped;
use App\Jobs\RiskNotificationJob;
use App\Listeners\NotifyBreakerTrip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * P4 POC 离线全链路断言（12 项）：
 *  1-4. 4 维度 Rule 触发（单笔/日内/回撤/连亏）
 *  5-6. HALF_OPEN 探测：亏损 re-trip / 盈利 recover
 *  7. BreakerTripped 事件分发计数
 *  8. risk_notifications 4 渠道落库 + sent/failed
 *  9. 跨日 resetDaily
 *  10. 崩溃恢复 lastTradeDate 注入
 *  11. DedupCooldown 15 分钟去重
 *  12. risk:recover 命令幂等
 *
 * 全程使用一次性租户 poc-verify-risk，结束自动清理。
 */
class PocVerifyRiskCommand extends Command
{
    protected $signature = 'poc:verify-risk';

    protected $description = 'POC: offline end-to-end assertions for P4 advanced risk control';

    private int $pass = 0;

    private int $fail = 0;

    /** @var string[] */
    private array $failures = [];

    private int $tenantId;

    private int $instanceId;

    public function handle(): int
    {
        $this->info('==== P4 高级风控 POC 验证 ====');

        try {
            $this->provisionFixtures();

            $this->checkMaxLossPerTrade();     // 1
            $this->checkMaxDailyLoss();        // 2
            $this->checkMaxDrawdown();         // 3
            $this->checkConsecutiveLosses();   // 4
            $this->checkHalfOpenProbeLoss();   // 5
            $this->checkHalfOpenProbeGain();   // 6
            $this->checkEventDispatch();       // 7
            $this->checkRiskNotifications();   // 8
            $this->checkCrossDayReset();       // 9
            $this->checkCrashRecovery();       // 10
            $this->checkDedupCooldown();       // 11
            $this->checkRiskRecoverCommand();  // 12
        } catch (Throwable $e) {
            $this->fail++;
            $this->failures[] = '验证流程异常中断：' . $e->getMessage();
            $this->error('FATAL: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
        } finally {
            $this->cleanupFixtures();
        }

        $this->newLine();
        $this->info(sprintf('RESULT: %d/%d passed', $this->pass, $this->pass + $this->fail));
        if ($this->fail > 0) {
            $this->error('VERIFY FAILED');
            foreach ($this->failures as $f) {
                $this->line('  - ' . $f);
            }

            return self::FAILURE;
        }

        $this->info('ALL CHECKS PASSED');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // 断言助手
    // ------------------------------------------------------------------

    private function check(bool $cond, string $label, string $detail = ''): void
    {
        if ($cond) {
            $this->pass++;
            $this->line('  PASS ' . $label);
        } else {
            $this->fail++;
            $this->failures[] = $label . ($detail !== '' ? "（{$detail}）" : '');
            $this->error('  FAIL ' . $label . ($detail !== '' ? "（{$detail}）" : ''));
        }
    }

    // ------------------------------------------------------------------
    // 夹具
    // ------------------------------------------------------------------

    private function provisionFixtures(): void
    {
        $slug = 'poc-verify-risk';
        $old = DB::table('tenants')->where('slug', $slug)->first();
        if ($old !== null) {
            $this->deleteTenantCascade((int) $old->id);
        }

        $exchangeId = (int) DB::table('exchanges')->where('code', 'binance')->value('id');
        if ($exchangeId === 0) {
            $exchangeId = (int) DB::table('exchanges')->insertGetId([
                'code' => 'binance', 'name' => 'Binance',
                'base_url' => 'https://api.binance.com',
                'ws_url' => 'wss://stream.binance.com:9443/stream',
                'markets' => json_encode(['spot', 'futures']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $strategyId = (int) DB::table('strategies')->where('code', 'spot_grid')->value('id');
        if ($strategyId === 0) {
            $strategyId = (int) DB::table('strategies')->insertGetId([
                'code' => 'spot_grid', 'name' => '现货网格', 'category' => 'spot',
                'risk_profile' => 'conservative',
                'default_params' => json_encode([]), 'enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->tenantId = (int) DB::table('tenants')->insertGetId([
            'name' => $slug, 'slug' => $slug,
            'exchange_dek' => '', 'status' => 'active', 'plan_code' => 'free',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $accountId = (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => $this->tenantId,
            'exchange_id' => $exchangeId,
            'label' => 'poc-risk',
            'market' => 'spot',
            'encrypted_api_key' => 'poc',
            'encrypted_api_secret' => 'poc',
            'permissions' => json_encode(['spot']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->instanceId = (int) DB::table('strategy_instances')->insertGetId([
            'tenant_id' => $this->tenantId,
            'strategy_id' => $strategyId,
            'exchange_account_id' => $accountId,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => json_encode([]),
            'allocated_capital' => '100',
            'status' => 'stopped',
            'started_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clearBreakerRedis();
    }

    private function deleteTenantCascade(int $tenantId): void
    {
        DB::table('risk_notifications')->where('tenant_id', $tenantId)->delete();
        $eventIds = DB::table('circuit_breaker_events')->where('tenant_id', $tenantId)->pluck('id')->all();
        if ($eventIds !== []) {
            DB::table('risk_notifications')->whereIn('circuit_breaker_event_id', $eventIds)->delete();
        }
        DB::table('circuit_breaker_events')->where('tenant_id', $tenantId)->delete();
        DB::table('strategy_orders')->where('tenant_id', $tenantId)->delete();
        DB::table('strategy_instances')->where('tenant_id', $tenantId)->delete();
        DB::table('exchange_accounts')->where('tenant_id', $tenantId)->delete();
        DB::table('tenants')->where('id', $tenantId)->delete();
    }

    private function cleanupFixtures(): void
    {
        if (isset($this->tenantId)) {
            $this->clearBreakerRedis();
            $this->deleteTenantCascade($this->tenantId);
        }
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function clearBreakerRedis(): void
    {
        $key = $this->breakerKey();
        Redis::connection('default')->del($key);
        // 清理 dedup
        $dedupPattern = "tenant:{$this->tenantId}:dedup:risk_breaker:*";
        $keys = Redis::connection('default')->keys($dedupPattern);
        foreach ($keys as $k) {
            Redis::connection('default')->del($k);
        }
    }

    private function breakerKey(): string
    {
        return "tenant:{$this->tenantId}:breaker:{$this->instanceId}";
    }

    private function freshGuard(): RiskGuard
    {
        $this->clearBreakerRedis();

        return new RiskGuard(
            [
                new MaxLossPerTradeRule('2'),
                new MaxDailyLossRule('5'),
                new MaxDrawdownRule('10'),
                new ConsecutiveLossRule(5),
            ],
            new CircuitBreaker(new BreakerStateMachine(), $this->tenantId, $this->instanceId),
            '100',
            $this->tenantId,
            $this->instanceId,
        );
    }

    private function makeOrder(string $side, string $qty, string $price): ExchangeOrder
    {
        return new ExchangeOrder(
            exchangeOrderId: 'test',
            clientOrderId: '',
            symbol: 'BTCUSDT',
            side: $side,
            type: 'market',
            quantity: $qty,
            price: $price,
            stopPrice: null,
            leverage: null,
            reduceOnly: false,
            status: 'filled',
            filledQty: $qty,
            avgFillPrice: $price,
            fee: '0',
            placedAt: null,
            filledAt: null,
        );
    }

    private function expireBreaker(): void
    {
        Redis::connection('default')->hSet($this->breakerKey(), 'next_attempt_at', time() - 1);
    }

    // ------------------------------------------------------------------
    // 7. 事件分发计数
    // ------------------------------------------------------------------

    private function checkEventDispatch(): void
    {
        $this->info(PHP_EOL . '---- 7. BreakerTripped 事件分发 ----');

        $dispatchLog = [];
        Event::listen(BreakerTripped::class, function (BreakerTripped $e) use (&$dispatchLog) {
            $dispatchLog[] = $e->toState;
        });

        $guard = $this->freshGuard();
        $guard->metrics()->recordTradeWithDate('-2.5', '100', gmdate('Y-m-d'));
        $guard->check($this->instanceId, 'open'); // trip → open

        $this->expireBreaker();
        $guard->check($this->instanceId, 'open'); // attemptProbe → half_open
        $guard->attemptRecovery($this->instanceId); // recover → closed

        $trips = count(array_filter($dispatchLog, fn ($s) => $s === CircuitBreaker::STATE_OPEN));
        $recovers = count(array_filter($dispatchLog, fn ($s) => $s === CircuitBreaker::STATE_CLOSED));

        $this->check($trips === 1, "trip 事件分发 1 次（实际={$trips}）");
        $this->check($recovers === 1, "recover 事件分发 1 次（实际={$recovers}）");
    }

    // ------------------------------------------------------------------
    // 1. 单笔最大亏损
    // ------------------------------------------------------------------

    private function checkMaxLossPerTrade(): void
    {
        $this->info(PHP_EOL . '---- 1. 单笔最大亏损 max_loss_per_trade ----');

        $guard = $this->freshGuard();
        $guard->metrics()->recordTradeWithDate('-2.5', '100', gmdate('Y-m-d'));

        $decision = $guard->check($this->instanceId, 'open');

        $this->check(! $decision->allowed, 'check(open) 被拒');
        $this->check($decision->reason === 'max_loss_per_trade', "reason=max_loss_per_trade（实际={$decision->reason}）");
        $this->check($guard->getBreakerState($this->instanceId) === CircuitBreaker::STATE_OPEN, 'state=open');

        $audit = DB::table('circuit_breaker_events')
            ->where('strategy_instance_id', $this->instanceId)
            ->where('to_state', CircuitBreaker::STATE_OPEN)
            ->where('rule_type', 'max_loss_per_trade')
            ->orderByDesc('id')->first();

        $this->check($audit !== null && $audit->from_state === CircuitBreaker::STATE_CLOSED, '审计行 from=closed to=open rule=max_loss_per_trade');
    }

    // ------------------------------------------------------------------
    // 2. 日内最大亏损
    // ------------------------------------------------------------------

    private function checkMaxDailyLoss(): void
    {
        $this->info(PHP_EOL . '---- 2. 日内最大亏损 max_daily_loss ----');

        $guard = $this->freshGuard();
        // 4 笔各 -1.5 = -6%（单笔 1.5% < 2%，不触发 max_loss_per_trade）
        for ($i = 0; $i < 4; $i++) {
            $guard->metrics()->recordTradeWithDate('-1.5', '100', gmdate('Y-m-d'));
        }

        $decision = $guard->check($this->instanceId, 'open');

        $this->check(! $decision->allowed, 'check(open) 被拒');
        $this->check($decision->reason === 'max_daily_loss', "reason=max_daily_loss（实际={$decision->reason}）");

        $nextAttempt = (int) Redis::connection('default')->hGet($this->breakerKey(), 'next_attempt_at');
        $openedAt = (int) Redis::connection('default')->hGet($this->breakerKey(), 'opened_at');
        $cooldownSec = $nextAttempt - $openedAt;
        $this->check($cooldownSec === 1440 * 60, "冷却=1440 分钟（实际=" . intdiv($cooldownSec, 60) . ' 分钟）');
    }

    // ------------------------------------------------------------------
    // 3. 最大回撤
    // ------------------------------------------------------------------

    private function checkMaxDrawdown(): void
    {
        $this->info(PHP_EOL . '---- 3. 最大回撤 max_drawdown ----');

        $guard = $this->freshGuard();
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $dayBefore = gmdate('Y-m-d', time() - 2 * 86400);
        $threeDaysAgo = gmdate('Y-m-d', time() - 3 * 86400);

        // 建峰值 → 3 天各亏 4.8%（单笔 < 2%，日内 < 5%）→ 末笔小盈利重置单笔/连亏
        $guard->metrics()->recordTradeWithDate('20', '100', $threeDaysAgo);   // peak=120
        $guard->metrics()->recordTradeWithDate('-4.8', '100', $dayBefore);      // eq=115.2, dd=4%
        $guard->metrics()->recordTradeWithDate('-4.8', '100', $yesterday);      // eq=110.4, dd=8%
        $guard->metrics()->recordTradeWithDate('-4.8', '100', $today);          // eq=105.6, dd=12%
        $guard->metrics()->recordTradeWithDate('1', '100', $today);             // eq=106.6, dd=11.2%, lastLoss=0, conn=0

        $this->check($guard->metrics()->getCurrentDrawdownPct() !== '0', 'drawdown > 0（实际=' . $guard->metrics()->getCurrentDrawdownPct() . '%）');

        $decision = $guard->check($this->instanceId, 'open');

        $this->check(! $decision->allowed, 'check(open) 被拒');
        $this->check($decision->reason === 'max_drawdown', "reason=max_drawdown（实际={$decision->reason}）");
    }

    // ------------------------------------------------------------------
    // 4. 连续亏损
    // ------------------------------------------------------------------

    private function checkConsecutiveLosses(): void
    {
        $this->info(PHP_EOL . '---- 4. 连续亏损 consecutive_losses ----');

        $guard = $this->freshGuard();
        $today = gmdate('Y-m-d');
        // 5 笔各 -0.9 = -4.5%（单笔 < 2%，日内 < 5%，回撤 < 10%）
        for ($i = 0; $i < 5; $i++) {
            $guard->metrics()->recordTradeWithDate('-0.9', '100', $today);
        }

        $decision = $guard->check($this->instanceId, 'open');

        $this->check(! $decision->allowed, 'check(open) 被拒');
        $this->check($decision->reason === 'consecutive_losses', "reason=consecutive_losses（实际={$decision->reason}）");
    }

    // ------------------------------------------------------------------
    // 5. HALF_OPEN 探测亏损 → re-trip
    // ------------------------------------------------------------------

    private function checkHalfOpenProbeLoss(): void
    {
        $this->info(PHP_EOL . '---- 5. HALF_OPEN 探测亏损 → re-trip ----');

        $guard = $this->freshGuard();
        $today = gmdate('Y-m-d');

        // 买@100 建仓
        $guard->recordTrade($this->instanceId, $this->makeOrder('buy', '1', '100'));
        // 注入亏损触发 trip
        $guard->metrics()->recordTradeWithDate('-2.5', '100', $today);
        $guard->check($this->instanceId, 'open');
        $this->check($guard->getBreakerState($this->instanceId) === CircuitBreaker::STATE_OPEN, 'trip 后 state=open');

        // 冷却到期 → HALF_OPEN
        $this->expireBreaker();
        $probeDecision = $guard->check($this->instanceId, 'open');
        $this->check($guard->getBreakerState($this->instanceId) === CircuitBreaker::STATE_HALF_OPEN, '探测后 state=half_open');
        $this->check($probeDecision->allowed, '探测单放行 allowed=true');

        // 探测单亏损：卖@97 → pnl = 97-100 = -3
        $guard->recordTrade($this->instanceId, $this->makeOrder('sell', '1', '97'));

        $this->check($guard->getBreakerState($this->instanceId) === CircuitBreaker::STATE_OPEN, '探测亏损后 re-trip state=open');
    }

    // ------------------------------------------------------------------
    // 6. HALF_OPEN 探测盈利 → recover
    // ------------------------------------------------------------------

    private function checkHalfOpenProbeGain(): void
    {
        $this->info(PHP_EOL . '---- 6. HALF_OPEN 探测盈利 → recover ----');

        $guard = $this->freshGuard();
        $today = gmdate('Y-m-d');

        $guard->recordTrade($this->instanceId, $this->makeOrder('buy', '1', '100'));
        $guard->metrics()->recordTradeWithDate('-2.5', '100', $today);
        $guard->check($this->instanceId, 'open');

        $this->expireBreaker();
        $guard->check($this->instanceId, 'open'); // → HALF_OPEN

        // 探测单盈利：卖@103 → pnl = 103-100 = 3
        $guard->recordTrade($this->instanceId, $this->makeOrder('sell', '1', '103'));

        $this->check($guard->getBreakerState($this->instanceId) === CircuitBreaker::STATE_CLOSED, '探测盈利后 state=closed');

        $audit = DB::table('circuit_breaker_events')
            ->where('strategy_instance_id', $this->instanceId)
            ->where('to_state', CircuitBreaker::STATE_CLOSED)
            ->where('action_taken', 'resume_strategy')
            ->orderByDesc('id')->first();
        $this->check($audit !== null, '审计行 to=closed action=resume_strategy');
    }

    // ------------------------------------------------------------------
    // 8. risk_notifications 4 渠道
    // ------------------------------------------------------------------

    private function checkRiskNotifications(): void
    {
        $this->info(PHP_EOL . '---- 8. risk_notifications 4 渠道 ----');

        // 插入 circuit_breaker_event 行供 FK 关联
        $eventId = (int) DB::table('circuit_breaker_events')->insertGetId([
            'tenant_id' => $this->tenantId,
            'strategy_instance_id' => $this->instanceId,
            'from_state' => CircuitBreaker::STATE_CLOSED,
            'to_state' => CircuitBreaker::STATE_OPEN,
            'rule_type' => 'test_rule',
            'trigger_value' => 0,
            'threshold_value' => 0,
            'action_taken' => 'test',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = new BreakerTripped(
            $this->tenantId,
            $this->instanceId,
            CircuitBreaker::STATE_CLOSED,
            CircuitBreaker::STATE_OPEN,
            'test_rule',
            $eventId,
        );

        // 直接调 Listener（绕过队列）
        (new NotifyBreakerTrip())->handle($event);

        $rows = DB::table('risk_notifications')
            ->where('tenant_id', $this->tenantId)
            ->where('instance_id', $this->instanceId)
            ->where('circuit_breaker_event_id', $eventId)
            ->get();

        $this->check($rows->count() === 4, "4 渠道行已插入（实际=" . $rows->count() . '）');

        // 直接调 Job 发送
        $channels = ['log', 'telegram', 'wechat_work', 'mail'];
        (new RiskNotificationJob($this->tenantId, $eventId, $this->instanceId, $channels))->handle();

        $logStatus = DB::table('risk_notifications')->where('circuit_breaker_event_id', $eventId)->where('channel', 'log')->value('status');
        $wwStatus = DB::table('risk_notifications')->where('circuit_breaker_event_id', $eventId)->where('channel', 'wechat_work')->value('status');
        $mailStatus = DB::table('risk_notifications')->where('circuit_breaker_event_id', $eventId)->where('channel', 'mail')->value('status');

        $this->check($logStatus === 'sent', "log=sent（实际={$logStatus}）");
        $this->check($wwStatus === 'failed', "wechat_work=failed（实际={$wwStatus}）");
        $this->check($mailStatus === 'failed', "mail=failed（实际={$mailStatus}）");
    }

    // ------------------------------------------------------------------
    // 9. 跨日 resetDaily
    // ------------------------------------------------------------------

    private function checkCrossDayReset(): void
    {
        $this->info(PHP_EOL . '---- 9. 跨日 resetDaily ----');

        $metrics = new BreakerMetrics(peakEquity: '100');
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $today = gmdate('Y-m-d');

        // 昨日亏损
        $metrics->recordTradeWithDate('-2.5', '100', $yesterday);
        $this->check($metrics->getDailyLossPct() === '2.50000000', '昨日 dailyLossPct=2.5（实际=' . $metrics->getDailyLossPct() . '）');

        // 今日首笔 → 跨日重置
        $result = $metrics->recordTradeWithDate('-3', '100', $today);
        $this->check($result['dayChanged'] === true, '跨日 dayChanged=true');
        $this->check($metrics->getDailyLossPct() === '3.00000000', '跨日后 dailyLossPct=3（仅今日，实际=' . $metrics->getDailyLossPct() . '）');
    }

    // ------------------------------------------------------------------
    // 10. 崩溃恢复 lastTradeDate 注入
    // ------------------------------------------------------------------

    private function checkCrashRecovery(): void
    {
        $this->info(PHP_EOL . '---- 10. 崩溃恢复 lastTradeDate 注入 ----');

        $yesterday = gmdate('Y-m-d', time() - 86400);

        // 模拟 Redis 中持久化的 last_trade_date
        $this->clearBreakerRedis();
        Redis::connection('default')->hSet($this->breakerKey(), 'last_trade_date', $yesterday);

        // 构造 RiskGuard → 从 Redis 加载 lastTradeDate
        $guard = new RiskGuard(
            [
                new MaxLossPerTradeRule('2'),
                new MaxDailyLossRule('5'),
                new MaxDrawdownRule('10'),
                new ConsecutiveLossRule(5),
            ],
            new CircuitBreaker(new BreakerStateMachine(), $this->tenantId, $this->instanceId),
            '100',
            $this->tenantId,
            $this->instanceId,
        );

        $this->check($guard->metrics()->getLastTradeDate() === $yesterday, '构造时从 Redis 加载 lastTradeDate=' . $yesterday);

        // 今日首笔成交 → 跨日检测 → resetDaily → dayChanged → 写 Redis
        // buy 建仓后 sell@98.5 产生 pnl=-1.5，走 RiskGuard.recordTrade 的 dayChanged→setLastTradeDate 链路
        $guard->recordTrade($this->instanceId, $this->makeOrder('buy', '1', '100'));
        $guard->recordTrade($this->instanceId, $this->makeOrder('sell', '1', '98.5'));

        $redisDate = Redis::connection('default')->hGet($this->breakerKey(), 'last_trade_date');
        $this->check($redisDate === gmdate('Y-m-d'), '跨日后 Redis last_trade_date 已更新为今日（实际=' . $redisDate . '）');
    }

    // ------------------------------------------------------------------
    // 11. DedupCooldown
    // ------------------------------------------------------------------

    private function checkDedupCooldown(): void
    {
        $this->info(PHP_EOL . '---- 11. DedupCooldown 去重 ----');

        // 清 dedup
        $fingerprint = sha1($this->instanceId . '|dedup_test');
        Redis::connection('default')->del("tenant:{$this->tenantId}:dedup:risk_breaker:{$fingerprint}");

        $eventId = (int) DB::table('circuit_breaker_events')->insertGetId([
            'tenant_id' => $this->tenantId,
            'strategy_instance_id' => $this->instanceId,
            'from_state' => CircuitBreaker::STATE_CLOSED,
            'to_state' => CircuitBreaker::STATE_OPEN,
            'rule_type' => 'dedup_test',
            'trigger_value' => 0,
            'threshold_value' => 0,
            'action_taken' => 'test',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = new BreakerTripped(
            $this->tenantId,
            $this->instanceId,
            CircuitBreaker::STATE_CLOSED,
            CircuitBreaker::STATE_OPEN,
            'dedup_test',
            $eventId,
        );

        $listener = new NotifyBreakerTrip();

        // 第一次：插入行
        $listener->handle($event);
        $count1 = DB::table('risk_notifications')
            ->where('circuit_breaker_event_id', $eventId)->count();

        // 第二次：去重，不插新行
        $listener->handle($event);
        $count2 = DB::table('risk_notifications')
            ->where('circuit_breaker_event_id', $eventId)->count();

        $this->check($count1 > 0, "首次调用插入行（{$count1} 行）");
        $this->check($count2 === $count1, "二次调用被去重，行数不变（{$count1} → {$count2}）");
    }

    // ------------------------------------------------------------------
    // 12. risk:recover 命令幂等
    // ------------------------------------------------------------------

    private function checkRiskRecoverCommand(): void
    {
        $this->info(PHP_EOL . '---- 12. risk:recover 命令幂等 ----');

        // 置 HALF_OPEN
        $guard = $this->freshGuard();
        $guard->metrics()->recordTradeWithDate('-2.5', '100', gmdate('Y-m-d'));
        $guard->check($this->instanceId, 'open'); // trip
        $this->expireBreaker();
        $guard->check($this->instanceId, 'open'); // → HALF_OPEN

        $this->check($guard->getBreakerState($this->instanceId) === CircuitBreaker::STATE_HALF_OPEN, '前置 state=half_open');

        // 首次恢复
        $exit1 = $this->call('risk:recover', ['instanceId' => $this->instanceId]);
        $state1 = (new CircuitBreaker(new BreakerStateMachine(), $this->tenantId, $this->instanceId))->state();
        $this->check($exit1 === self::SUCCESS && $state1 === CircuitBreaker::STATE_CLOSED, 'risk:recover → closed');

        $manualAudit = DB::table('circuit_breaker_events')
            ->where('strategy_instance_id', $this->instanceId)
            ->where('action_taken', 'manual_resume')->exists();
        $this->check($manualAudit, '审计行 action=manual_resume 已写入');

        // 再次调用幂等
        $exit2 = $this->call('risk:recover', ['instanceId' => $this->instanceId]);
        $this->check($exit2 === self::SUCCESS, 'CLOSED 状态再次调用幂等返回 SUCCESS');
    }
}
