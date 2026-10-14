<?php

declare(strict_types=1);

namespace Tests\Integration\Risk;

use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Jobs\RiskNotificationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * RiskNotificationJob 渠道发送矩阵集成测试（断言 8 的 sent/failed 三项离线复刻）。
 *
 * default 组：SQLite + RefreshDatabase，无 Redis 依赖——Listener 的去重/落行职责由测试
 * 直接插入 queued 行等价代替（插入即 Listener 去重通过后的产物），只验证 Job 的发送与状态回写：
 * log=sent；telegram 测试环境无 bot_token=failed；wechat_work/mail 尚未实现=failed。
 * Http::fake() 保证无外网请求。
 */
class RiskNotificationJobTest extends TestCase
{
    private const TENANT_ID = 990042;

    private const ACCOUNT_ID = 990142;

    private const INSTANCE_ID = 990242;

    /** @var string[] */
    private const CHANNELS = ['log', 'telegram', 'wechat_work', 'mail'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake();
        $this->scaffold();
    }

    public function test_job_marks_log_sent_and_unconfigured_channels_failed(): void
    {
        $eventId = $this->insertEvent();
        $this->insertQueuedRows($eventId, self::CHANNELS);

        (new RiskNotificationJob(self::TENANT_ID, $eventId, self::INSTANCE_ID, self::CHANNELS))->handle();

        static::assertSame('sent', $this->channelStatus($eventId, 'log'));
        // testing 环境未配置 TELEGRAM_BOT_TOKEN，与 mail/wechat_work 一样落 failed（旧命令三项断言）
        static::assertSame('failed', $this->channelStatus($eventId, 'wechat_work'));
        static::assertSame('failed', $this->channelStatus($eventId, 'mail'));
        static::assertSame('failed', $this->channelStatus($eventId, 'telegram'));

        $sentRow = DB::table('risk_notifications')
            ->where('circuit_breaker_event_id', $eventId)
            ->where('channel', 'log')->first();
        static::assertSame('logged', $sentRow->provider_message_id);
        static::assertNotNull($sentRow->sent_at);
    }

    public function test_job_is_idempotent_when_no_queued_row_remains(): void
    {
        $eventId = $this->insertEvent();
        $this->insertQueuedRows($eventId, ['log']);

        $job = new RiskNotificationJob(self::TENANT_ID, $eventId, self::INSTANCE_ID, ['log']);
        $job->handle();
        static::assertSame('sent', $this->channelStatus($eventId, 'log'));

        // 已无 queued 行：二次执行静默跳过，状态保持 sent
        $job->handle();
        static::assertSame('sent', $this->channelStatus($eventId, 'log'));
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function channelStatus(int $eventId, string $channel): string
    {
        return (string) DB::table('risk_notifications')
            ->where('circuit_breaker_event_id', $eventId)
            ->where('channel', $channel)
            ->value('status');
    }

    private function insertEvent(): int
    {
        $now = now();

        return (int) DB::table('circuit_breaker_events')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'strategy_instance_id' => self::INSTANCE_ID,
            'from_state' => CircuitBreaker::STATE_CLOSED,
            'to_state' => CircuitBreaker::STATE_OPEN,
            'rule_type' => 'test_rule',
            'trigger_value' => 0,
            'threshold_value' => 0,
            'action_taken' => 'test',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  string[]  $channels
     */
    private function insertQueuedRows(int $eventId, array $channels): void
    {
        $now = now();
        foreach ($channels as $channel) {
            DB::table('risk_notifications')->insert([
                'tenant_id' => self::TENANT_ID,
                'circuit_breaker_event_id' => $eventId,
                'instance_id' => self::INSTANCE_ID,
                'channel' => $channel,
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function scaffold(): void
    {
        $now = now();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-risk-990042',
            'slug' => 'poc-risk-990042',
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
