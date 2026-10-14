<?php

declare(strict_types=1);

namespace Tests\Integration\Risk;

use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Risk\Breaker\BreakerStateMachine;
use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Domain\Risk\RiskGuard;
use App\Domain\Risk\Rules\ConsecutiveLossRule;
use App\Domain\Risk\Rules\MaxDailyLossRule;
use App\Domain\Risk\Rules\MaxDrawdownRule;
use App\Domain\Risk\Rules\MaxLossPerTradeRule;
use App\Events\BreakerTripped;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\TestCase;
use Throwable;

/**
 * 熔断器三态流转端到端集成测试（迁移自 poc:verify-risk 断言 1-7、10、12，共 26 项）。
 *
 * SQLite + RefreshDatabase 落审计行；熔断状态/冷却/跨日时间戳走真实 Redis（@group redis，
 * Redis 不可用时 requireRedis 自动 skip）。Queue::fake() 拦截 BreakerTripped 的队列监听器，
 * 事件计数用 Event::listen 闭包就地采集（与旧命令同款写法），无任何外网请求。
 *
 * 隔离约定：固定高位租户 990040 / 账户 990140 / 实例 990240，Redis key 与真实租户不冲突；
 * tearDown 外部清理受 $redisAvailable + try/catch 双守卫且先于 parent::tearDown()（坑 #24）。
 */
#[Group('redis')]
class CircuitBreakerFlowTest extends TestCase
{
    use ChecksRedisAvailability;

    private const TENANT_ID = 990040;

    private const ACCOUNT_ID = 990140;

    private const INSTANCE_ID = 990240;

    /** Redis 是否真实可用；tearDown 绝不能因 Redis 不可用再抛异常（坑 #24） */
    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        Queue::fake();
        $this->scaffold();
        $this->clearRedis();
    }

    protected function tearDown(): void
    {
        // 顺序：先做可能失败的外部清理（受守卫保护），最后 parent::tearDown() 回滚事务
        if ($this->redisAvailable) {
            try {
                $this->clearRedis();
            } catch (Throwable) {
                // 清理失败不允许吞掉测试结果本身（用的是高位隔离租户 ID）
            }
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 断言 1：单笔最大亏损 → trip + 审计落库（4 项）
    // ------------------------------------------------------------------

    public function test_max_loss_per_trade_rejects_opens_and_writes_open_audit(): void
    {
        $guard = $this->freshGuard();
        $guard->metrics()->recordTradeWithDate('-2.5', '100', gmdate('Y-m-d'));

        $decision = $guard->check(self::INSTANCE_ID, 'open');

        static::assertFalse($decision->allowed);
        static::assertSame('max_loss_per_trade', $decision->reason);
        static::assertSame(CircuitBreaker::STATE_OPEN, $guard->getBreakerState(self::INSTANCE_ID));

        $audit = DB::table('circuit_breaker_events')
            ->where('strategy_instance_id', self::INSTANCE_ID)
            ->where('to_state', CircuitBreaker::STATE_OPEN)
            ->where('rule_type', 'max_loss_per_trade')
            ->orderByDesc('id')->first();

        static::assertNotNull($audit);
        static::assertSame(CircuitBreaker::STATE_CLOSED, $audit->from_state);
    }

    // ------------------------------------------------------------------
    // 断言 2：日内最大亏损 + 1440 分钟冷却（3 项）
    // ------------------------------------------------------------------

    public function test_max_daily_loss_rejects_and_sets_one_day_cooldown(): void
    {
        $guard = $this->freshGuard();
        // 4 笔各 -1.5 = -6%（单笔 1.5% < 2%，不触发 max_loss_per_trade）
        for ($i = 0; $i < 4; $i++) {
            $guard->metrics()->recordTradeWithDate('-1.5', '100', gmdate('Y-m-d'));
        }

        $decision = $guard->check(self::INSTANCE_ID, 'open');

        static::assertFalse($decision->allowed);
        static::assertSame('max_daily_loss', $decision->reason);

        $nextAttempt = (int) Redis::connection('default')->hGet($this->breakerKey(), 'next_attempt_at');
        $openedAt = (int) Redis::connection('default')->hGet($this->breakerKey(), 'opened_at');

        static::assertSame(1440 * 60, $nextAttempt - $openedAt);
    }

    // ------------------------------------------------------------------
    // 断言 3：最大回撤（3 项）
    // ------------------------------------------------------------------

    public function test_max_drawdown_rejects_after_peak_decline(): void
    {
        $guard = $this->freshGuard();
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $dayBefore = gmdate('Y-m-d', time() - 2 * 86400);
        $threeDaysAgo = gmdate('Y-m-d', time() - 3 * 86400);

        // 建峰值 → 3 天各亏 4.8%（单笔 < 2%，日内 < 5%）→ 末笔小盈利重置单笔/连亏
        $guard->metrics()->recordTradeWithDate('20', '100', $threeDaysAgo);
        $guard->metrics()->recordTradeWithDate('-4.8', '100', $dayBefore);
        $guard->metrics()->recordTradeWithDate('-4.8', '100', $yesterday);
        $guard->metrics()->recordTradeWithDate('-4.8', '100', $today);
        $guard->metrics()->recordTradeWithDate('1', '100', $today);

        static::assertNotSame(0, bccomp($guard->metrics()->getCurrentDrawdownPct(), '0', 8));

        $decision = $guard->check(self::INSTANCE_ID, 'open');

        static::assertFalse($decision->allowed);
        static::assertSame('max_drawdown', $decision->reason);
    }

    // ------------------------------------------------------------------
    // 断言 4：连续亏损（2 项）
    // ------------------------------------------------------------------

    public function test_consecutive_losses_rejects_after_five_losses(): void
    {
        $guard = $this->freshGuard();
        // 5 笔各 -0.9 = -4.5%（单笔 < 2%，日内 < 5%，回撤 < 10%）
        for ($i = 0; $i < 5; $i++) {
            $guard->metrics()->recordTradeWithDate('-0.9', '100', gmdate('Y-m-d'));
        }

        $decision = $guard->check(self::INSTANCE_ID, 'open');

        static::assertFalse($decision->allowed);
        static::assertSame('consecutive_losses', $decision->reason);
    }

    // ------------------------------------------------------------------
    // 断言 5：HALF_OPEN 探测亏损 → re-trip（4 项）
    // ------------------------------------------------------------------

    public function test_half_open_probe_loss_retrips_to_open(): void
    {
        $guard = $this->freshGuard();

        // 买@100 建仓
        $guard->recordTrade(self::INSTANCE_ID, $this->makeOrder('buy', '1', '100'));
        // 注入亏损触发 trip
        $guard->metrics()->recordTradeWithDate('-2.5', '100', gmdate('Y-m-d'));
        $guard->check(self::INSTANCE_ID, 'open');
        static::assertSame(CircuitBreaker::STATE_OPEN, $guard->getBreakerState(self::INSTANCE_ID));

        // 冷却到期 → HALF_OPEN，探测单放行
        $this->expireBreaker();
        $probeDecision = $guard->check(self::INSTANCE_ID, 'open');
        static::assertSame(CircuitBreaker::STATE_HALF_OPEN, $guard->getBreakerState(self::INSTANCE_ID));
        static::assertTrue($probeDecision->allowed);

        // 探测单亏损：卖@97 → pnl = 97-100 = -3 → re-trip
        $guard->recordTrade(self::INSTANCE_ID, $this->makeOrder('sell', '1', '97'));

        static::assertSame(CircuitBreaker::STATE_OPEN, $guard->getBreakerState(self::INSTANCE_ID));
    }

    // ------------------------------------------------------------------
    // 断言 6：HALF_OPEN 探测盈利 → recover（2 项）
    // ------------------------------------------------------------------

    public function test_half_open_probe_gain_recovers_to_closed_and_audits(): void
    {
        $guard = $this->freshGuard();

        $guard->recordTrade(self::INSTANCE_ID, $this->makeOrder('buy', '1', '100'));
        $guard->metrics()->recordTradeWithDate('-2.5', '100', gmdate('Y-m-d'));
        $guard->check(self::INSTANCE_ID, 'open');

        $this->expireBreaker();
        $guard->check(self::INSTANCE_ID, 'open'); // → HALF_OPEN

        // 探测单盈利：卖@103 → pnl = 103-100 = 3
        $guard->recordTrade(self::INSTANCE_ID, $this->makeOrder('sell', '1', '103'));

        static::assertSame(CircuitBreaker::STATE_CLOSED, $guard->getBreakerState(self::INSTANCE_ID));

        $auditExists = DB::table('circuit_breaker_events')
            ->where('strategy_instance_id', self::INSTANCE_ID)
            ->where('to_state', CircuitBreaker::STATE_CLOSED)
            ->where('action_taken', 'resume_strategy')
            ->exists();
        static::assertTrue($auditExists);
    }

    // ------------------------------------------------------------------
    // 断言 7：BreakerTripped 事件分发计数（2 项）
    // ------------------------------------------------------------------

    public function test_breaker_tripped_event_dispatches_once_per_transition(): void
    {
        $dispatchLog = [];
        Event::listen(BreakerTripped::class, function (BreakerTripped $e) use (&$dispatchLog): void {
            $dispatchLog[] = $e->toState;
        });

        $guard = $this->freshGuard();
        $guard->metrics()->recordTradeWithDate('-2.5', '100', gmdate('Y-m-d'));
        $guard->check(self::INSTANCE_ID, 'open'); // trip → open

        $this->expireBreaker();
        $guard->check(self::INSTANCE_ID, 'open'); // attemptProbe → half_open（不发事件）
        $guard->attemptRecovery(self::INSTANCE_ID); // recover → closed

        $trips = count(array_filter($dispatchLog, fn (string $s) => $s === CircuitBreaker::STATE_OPEN));
        $recovers = count(array_filter($dispatchLog, fn (string $s) => $s === CircuitBreaker::STATE_CLOSED));

        static::assertSame(1, $trips);
        static::assertSame(1, $recovers);
    }

    // ------------------------------------------------------------------
    // 断言 10：崩溃恢复 lastTradeDate 注入（2 项）
    // ------------------------------------------------------------------

    public function test_crash_recovery_loads_and_persists_last_trade_date(): void
    {
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $this->clearRedis();
        Redis::connection('default')->hSet($this->breakerKey(), 'last_trade_date', $yesterday);

        // 构造 RiskGuard → 从 Redis 加载 lastTradeDate
        $guard = $this->newGuard();

        static::assertSame($yesterday, $guard->metrics()->getLastTradeDate());

        // 今日首笔成交 → 跨日检测 → resetDaily → dayChanged → 写 Redis
        // buy 建仓后 sell@98.5 产生 pnl=-1.5，走 RiskGuard.recordTrade 的完整链路（坑 #18）
        $guard->recordTrade(self::INSTANCE_ID, $this->makeOrder('buy', '1', '100'));
        $guard->recordTrade(self::INSTANCE_ID, $this->makeOrder('sell', '1', '98.5'));

        $redisDate = Redis::connection('default')->hGet($this->breakerKey(), 'last_trade_date');
        static::assertSame(gmdate('Y-m-d'), $redisDate);
    }

    // ------------------------------------------------------------------
    // 断言 12：risk:recover 命令幂等（4 项）
    // ------------------------------------------------------------------

    public function test_risk_recover_command_closes_half_open_and_is_idempotent(): void
    {
        // 置 HALF_OPEN
        $guard = $this->freshGuard();
        $guard->metrics()->recordTradeWithDate('-2.5', '100', gmdate('Y-m-d'));
        $guard->check(self::INSTANCE_ID, 'open'); // trip
        $this->expireBreaker();
        $guard->check(self::INSTANCE_ID, 'open'); // → HALF_OPEN

        static::assertSame(
            CircuitBreaker::STATE_HALF_OPEN,
            $guard->getBreakerState(self::INSTANCE_ID),
        );

        // 首次恢复
        $exit1 = Artisan::call('risk:recover', ['instanceId' => self::INSTANCE_ID]);
        $state1 = (new CircuitBreaker(new BreakerStateMachine, self::TENANT_ID, self::INSTANCE_ID))->state();
        static::assertSame(0, $exit1);
        static::assertSame(CircuitBreaker::STATE_CLOSED, $state1);

        $manualAudit = DB::table('circuit_breaker_events')
            ->where('strategy_instance_id', self::INSTANCE_ID)
            ->where('action_taken', 'manual_resume')->exists();
        static::assertTrue($manualAudit);

        // CLOSED 状态再次调用幂等返回 SUCCESS
        $exit2 = Artisan::call('risk:recover', ['instanceId' => self::INSTANCE_ID]);
        static::assertSame(0, $exit2);
    }

    // ------------------------------------------------------------------
    // 夹具/辅助（迁移自 PocVerifyRiskCommand）
    // ------------------------------------------------------------------

    private function newGuard(): RiskGuard
    {
        return new RiskGuard(
            [
                new MaxLossPerTradeRule('2'),
                new MaxDailyLossRule('5'),
                new MaxDrawdownRule('10'),
                new ConsecutiveLossRule(5),
            ],
            new CircuitBreaker(new BreakerStateMachine, self::TENANT_ID, self::INSTANCE_ID),
            '100',
            self::TENANT_ID,
            self::INSTANCE_ID,
        );
    }

    private function freshGuard(): RiskGuard
    {
        $this->clearRedis();

        return $this->newGuard();
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
        Redis::connection('default')->hSet($this->breakerKey(), 'next_attempt_at', (string) (time() - 1));
    }

    private function breakerKey(): string
    {
        return 'tenant:'.self::TENANT_ID.':breaker:'.self::INSTANCE_ID;
    }

    private function clearRedis(): void
    {
        $redis = Redis::connection('default');
        $redis->del($this->breakerKey());
        // Queue::fake 下监听器不真跑，正常不会产生 dedup key；仍按已知 fingerprint 兜底清理
        // （不能 keys() 后再 del：phpredis 全局前缀会二次拼接，见 RiskNotificationRouteTest）。
        foreach ([
            'max_loss_per_trade',
            'max_daily_loss',
            'max_drawdown',
            'consecutive_losses',
            'recovered',
        ] as $ruleType) {
            $redis->del(
                'tenant:'.self::TENANT_ID.':dedup:risk_breaker:'
                .sha1(self::INSTANCE_ID.'|'.$ruleType),
            );
        }
    }

    private function scaffold(): void
    {
        $now = now();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-risk-990040',
            'slug' => 'poc-risk-990040',
            'exchange_dek' => 'dummy-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot', 'futures']),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid', 'name' => '现货网格', 'category' => 'spot',
            'risk_profile' => 'conservative', 'default_params' => json_encode([]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('exchange_accounts')->insert([
            'id' => self::ACCOUNT_ID,
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => $exchangeId,
            'label' => 'poc-risk',
            'market' => 'spot',
            'encrypted_api_key' => 'poc',
            'encrypted_api_secret' => 'poc',
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('strategy_instances')->insert([
            'id' => self::INSTANCE_ID,
            'tenant_id' => self::TENANT_ID,
            'strategy_id' => $strategyId,
            'exchange_account_id' => self::ACCOUNT_ID,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => json_encode([]),
            'allocated_capital' => '100',
            'status' => 'stopped',
            'started_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
