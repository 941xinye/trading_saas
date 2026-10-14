<?php

declare(strict_types=1);

namespace Tests\Integration\Signal;

use App\Domain\Signal\Contracts\Signal;
use App\Domain\Signal\SignalDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\TestCase;
use Throwable;

/**
 * 信号通知冷却去重测试（P5.5 步骤 3，迁自 poc:verify-ai [6/7] 共 2 项断言）。
 *
 * 同 fingerprint（symbol|interval|direction）首次 queued、15 分钟内第二次 deduped；
 * Job 由 Queue::fake 拦截。整类 @group redis。
 */
#[Group('redis')]
class SignalDispatcherDedupTest extends TestCase
{
    use ChecksRedisAvailability;

    private const TENANT_ID = 990062;

    private const SYMBOL = 'DEDUPPHPUNIT';

    private bool $redisAvailable = false;

    private string $dedupKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        Queue::fake();
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC'));

        $now = Carbon::now();
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-ai-dedup-990062',
            'slug' => 'poc-ai-dedup-990062',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->dedupKey = sprintf(
            'tenant:%d:dedup:signal:%s',
            self::TENANT_ID,
            sha1(self::SYMBOL.'|5m|long'),
        );
        Redis::connection('default')->del($this->dedupKey);
    }

    protected function tearDown(): void
    {
        if ($this->redisAvailable) {
            try {
                Redis::connection('default')->del($this->dedupKey);
            } catch (Throwable) {
                // 坑 #24：外部清理必须守卫
            }
        }
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_second_dispatch_within_cooldown_is_marked_deduped(): void
    {
        $now = Carbon::now();
        $subscriptionId = (int) DB::table('signal_subscriptions')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => 'poc-dedup-990062',
            'symbols' => json_encode([self::SYMBOL]),
            'intervals' => json_encode(['5m']),
            'ai_prompter' => 'deepseek',
            'ai_model' => null,
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(['log']),
            'active' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $signalId = (int) DB::table('signals')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'subscription_id' => $subscriptionId,
            'symbol' => self::SYMBOL,
            'interval' => '5m',
            'direction' => 'long',
            'entry_price' => '100',
            'exit_price' => '101',
            'stop_loss' => '99',
            'confidence' => '60',
            'source' => 'rule_fallback',
            'rationale' => 'poc dedup',
            'raw_payload' => null,
            'triggered_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $signal = new Signal(
            symbol: self::SYMBOL,
            interval: '5m',
            direction: 'long',
            entryPrice: '100',
            exitPrice: '101',
            stopLoss: '99',
            confidence: 60,
            rationale: 'poc dedup',
            source: 'rule_fallback',
            rawPayload: null,
            tenantId: self::TENANT_ID,
            subscriptionId: $subscriptionId,
            persistedId: $signalId,
        );

        $beforeNoteId = (int) DB::table('signal_notifications')->max('id');
        $dispatcher = app(SignalDispatcher::class);
        $dispatcher->dispatch($signal, ['log']);
        $dispatcher->dispatch($signal, ['log']);

        $rows = DB::table('signal_notifications')
            ->where('id', '>', $beforeNoteId)
            ->where('signal_id', $signalId)
            ->where('channel', 'log')
            ->orderBy('id')
            ->get();

        static::assertCount(2, $rows);
        static::assertContains($rows[0]->status, ['queued', 'sent']);
        static::assertSame('deduped', $rows[1]->status);
    }
}
