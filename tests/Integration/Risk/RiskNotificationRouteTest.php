<?php

declare(strict_types=1);

namespace Tests\Integration\Risk;

use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Events\BreakerTripped;
use App\Jobs\RiskNotificationJob;
use App\Listeners\NotifyBreakerTrip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Support\RedisCleanup;
use Tests\TestCase;
use Throwable;

/**
 * 熔断通知编排集成测试（迁移自 poc:verify-risk 断言 8、11，共 6 项）。
 *
 * - 断言 8：NotifyBreakerTrip 经 Redis DedupCooldown 后为 4 个推送渠道落 risk_notifications，
 *   RiskNotificationJob 直跑（绕过队列）后 log=sent / wechat_work=failed / mail=failed；
 * - 断言 11：同 instanceId+ruleType 的二次事件在 15 分钟去重窗口内不重复落行。
 *
 * Queue::fake() 拦截监听器自动 dispatch 的 Job；HTTP 一律 Http::fake()，无外网请求。
 *
 * @group redis（监听器去重强依赖 Redis；无 Redis 时 requireRedis 自动 skip，坑 #24 守卫同流测试）
 */
#[Group('redis')]
class RiskNotificationRouteTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private const TENANT_ID = 990041;

    private const ACCOUNT_ID = 990141;

    private const INSTANCE_ID = 990241;

    /** @var string[] config/risk.php 去掉 websocket 后应落库的 4 个推送渠道 */
    private const PUSH_CHANNELS = ['log', 'telegram', 'wechat_work', 'mail'];

    /** 本测试组用到的全部 Redis 键（逻辑名，无前缀；Redis facade 自动拼一次） */
    private const REDIS_KEYS = [
        // 熔断状态 Hash
        'tenant:{tenant_id}:breaker:{instance_id}',
        // DedupCooldown：risk_breaker notification + risk status 两类
        'tenant:{tenant_id}:dedup:risk_breaker:{fp_test_rule}',
        'tenant:{tenant_id}:dedup:risk_breaker:{fp_dedup_test}',
        'tenant:{tenant_id}:dedup:risk_status:{fp_test_rule}',
        'tenant:{tenant_id}:dedup:risk_status:{fp_dedup_test}',
    ];

    /** 按实际 tenant/instance/rule 拼装出的具体键名 */
    private array $redisKeys = [];

    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        Queue::fake();
        Http::fake();
        $this->scaffold();

        // 构建本轮要清理的具体键（坑 #25：全套连跑顺序依赖）
        $this->redisKeys = self::buildRedisKeys();
        $this->cleanupRedis($this->redisKeys);
    }

    protected function tearDown(): void
    {
        if ($this->redisAvailable) {
            try {
                $this->cleanupRedis($this->redisKeys);
            } catch (Throwable) {
                // 清理失败不允许吞掉测试结果本身（用的是高位隔离租户 ID）
            }
        }

        parent::tearDown();
    }

    /**
     * 生成本测试用到的全部逻辑名 Redis 键。
     *
     * DedupCooldown 的 fingerprint = sha1(instanceId|ruleType)，
     * risk_breaker / risk_status 两类通知各占一个 dedup 槽。
     *
     * 坑 #26：phpredis 全局前缀下 keys() 返回带前缀全名，再 del 会二次拼接删不掉；
     *        本 trait 的 cleanupRedis 按逻辑名调 Redis facade，客户端自动拼一次前缀。
     */
    private static function buildRedisKeys(): array
    {
        $tenant = self::TENANT_ID;
        $iid = self::INSTANCE_ID;
        $keys = ["tenant:{$tenant}:breaker:{$iid}"];
        foreach (['test_rule', 'dedup_test'] as $ruleType) {
            $fp = sha1("{$iid}|{$ruleType}");
            $keys[] = "tenant:{$tenant}:dedup:risk_breaker:{$fp}";
            $keys[] = "tenant:{$tenant}:dedup:risk_status:{$fp}";
        }

        return $keys;
    }

    // ------------------------------------------------------------------
    // 断言 8：4 渠道落库 + sent/failed（4 项）
    // ------------------------------------------------------------------

    public function test_listener_routes_four_channels_and_job_marks_statuses(): void
    {
        $eventId = $this->insertEvent('test_rule');
        $event = $this->makeEvent('test_rule', $eventId);

        // 直接调 Listener（绕过队列），与旧命令一致
        (new NotifyBreakerTrip)->handle($event);

        $rows = DB::table('risk_notifications')
            ->where('tenant_id', self::TENANT_ID)
            ->where('instance_id', self::INSTANCE_ID)
            ->where('circuit_breaker_event_id', $eventId)
            ->get();

        static::assertCount(4, $rows);
        // 落库顺序按 config 渠道顺序，断言统一排序后比较
        static::assertSame(
            ['log', 'mail', 'telegram', 'wechat_work'],
            $rows->pluck('channel')->sort()->values()->all(),
        );

        // 直接调 Job 发送：log 成功；测试环境无 telegram bot_token、wechat_work/mail 尚未实现 → failed
        (new RiskNotificationJob(self::TENANT_ID, $eventId, self::INSTANCE_ID, self::PUSH_CHANNELS))->handle();

        static::assertSame('sent', $this->channelStatus($eventId, 'log'));
        static::assertSame('failed', $this->channelStatus($eventId, 'wechat_work'));
        static::assertSame('failed', $this->channelStatus($eventId, 'mail'));
    }

    // ------------------------------------------------------------------
    // 断言 11：DedupCooldown 15 分钟去重（2 项）
    // ------------------------------------------------------------------

    public function test_second_trip_within_cooldown_is_deduplicated(): void
    {
        $eventId = $this->insertEvent('dedup_test');
        $event = $this->makeEvent('dedup_test', $eventId);

        $listener = new NotifyBreakerTrip;

        // 第一次：插入行
        $listener->handle($event);
        $count1 = DB::table('risk_notifications')
            ->where('circuit_breaker_event_id', $eventId)->count();
        static::assertGreaterThan(0, $count1);

        // 第二次：去重，不插新行
        $listener->handle($event);
        $count2 = DB::table('risk_notifications')
            ->where('circuit_breaker_event_id', $eventId)->count();
        static::assertSame($count1, $count2);
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function channelStatus(int $eventId, string $channel): ?string
    {
        $status = DB::table('risk_notifications')
            ->where('circuit_breaker_event_id', $eventId)
            ->where('channel', $channel)
            ->value('status');

        return $status === null ? null : (string) $status;
    }

    private function makeEvent(string $ruleType, int $eventId): BreakerTripped
    {
        return new BreakerTripped(
            self::TENANT_ID,
            self::INSTANCE_ID,
            CircuitBreaker::STATE_CLOSED,
            CircuitBreaker::STATE_OPEN,
            $ruleType,
            $eventId,
        );
    }

    private function insertEvent(string $ruleType): int
    {
        $now = now();

        return (int) DB::table('circuit_breaker_events')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'strategy_instance_id' => self::INSTANCE_ID,
            'from_state' => CircuitBreaker::STATE_CLOSED,
            'to_state' => CircuitBreaker::STATE_OPEN,
            'rule_type' => $ruleType,
            'trigger_value' => 0,
            'threshold_value' => 0,
            'action_taken' => 'test',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function scaffold(): void
    {
        $now = now();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-risk-990041',
            'slug' => 'poc-risk-990041',
            'exchange_dek' => 'dummy-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
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
