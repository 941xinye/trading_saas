<?php

declare(strict_types=1);

namespace Tests\Integration\Payment;

use App\Http\Controllers\Api\V1\BillingWebhookController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Support\RedisCleanup;
use Tests\TestCase;

/**
 * webhook 并发削峰（@group redis，FR-005 / constitution VI）：
 * 同 ref 并发投递 —— 持锁方 processed（confirmed_at 仅一次迁移），
 * 锁冲突方 200 + idempotent_skipped 且零状态变更。
 *
 * 以「预占锁」等价模拟并发临界区（PHP 单进程无法真并发，锁语义一致）。
 */
#[Group('redis')]
class WebhookConcurrencyTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private const TENANT_ID = 990204;

    private const REF = 'pi_concurrency000000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();

        Carbon::setTestNow(Carbon::create(2026, 10, 20, 0, 0, 0, 'UTC'));
        $this->clearLock();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-pay-conc-990204',
            'slug' => 'poc-pay-conc-990204',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        DB::table('payment_invoices')->insert([
            'tenant_id' => self::TENANT_ID,
            'ref' => self::REF,
            'channel_code' => 'fake',
            'plan_code' => 'pro',
            'currency' => 'USDT',
            'chain' => 'TRC20',
            'amount_usdt' => '49.00000000',
            'status' => 'pending',
            'expires_at' => Carbon::now('UTC')->addMinutes(30),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    protected function tearDown(): void
    {
        // 双清单飞锁键（先于 parent，constitution VI）
        try {
            $this->clearLock();
            DB::table('payment_webhook_events')->where('ref', self::REF)->delete();
            DB::table('payment_invoices')->where('tenant_id', self::TENANT_ID)->delete();
            DB::table('subscriptions')->where('tenant_id', self::TENANT_ID)->delete();
            DB::table('tenants')->where('id', self::TENANT_ID)->delete();
        } catch (\Throwable) {
            // 清理失败不影响测试结论
        }
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_concurrent_same_ref_only_processed_once(): void
    {
        $payload = (string) json_encode([
            'event' => 'fake.settled',
            'ref' => self::REF,
            'invoice_id' => 'fake_'.self::REF,
            'paid_amount' => '49.00000000',
            'currency' => 'USDT',
        ]);
        $signature = hash_hmac('sha256', $payload, (string) config('payment.channels.fake.secret'));
        $lockKey = $this->lockKey();

        // 并发方 A 已持锁（模拟另一进程临界区）→ 本方投递必须幂等跳过
        Redis::connection('default')->set($lockKey, 'concurrent-holder', 'EX', 60, 'NX');

        $conflicted = $this->deliver($payload, $signature);
        $conflicted->assertOk();
        $conflicted->assertJsonPath('ok', true);
        $conflicted->assertJsonPath('result', 'idempotent_skipped');
        static::assertSame('pending', DB::table('payment_invoices')->where('ref', self::REF)->value('status'), '锁冲突方零状态变更');
        static::assertSame(0, DB::table('subscriptions')->count());

        // 锁释放后重投 → 正常处理且 confirmed_at 仅一次迁移
        $this->cleanupRedis([$lockKey]);

        $processed = $this->deliver($payload, $signature);
        $processed->assertOk();
        $processed->assertJsonPath('result', 'processed');
        static::assertSame('confirmed', DB::table('payment_invoices')->where('ref', self::REF)->value('status'));
        static::assertSame(1, DB::table('subscriptions')->where('tenant_id', self::TENANT_ID)->where('status', 'active')->count());

        // 锁已随处理释放：同键可直接重新获取（证明 finally 释放生效）
        static::assertTrue(
            Redis::connection('default')->set($lockKey, 'probe', 'EX', 5, 'NX') === true
            || Redis::connection('default')->set($lockKey, 'probe', 'EX', 5, 'NX') === 'OK',
            '处理完成后锁必须已释放',
        );
    }

    private function deliver(string $payload, string $signature): TestResponse
    {
        return $this->call(
            'POST',
            '/api/v1/billing/webhook/fake',
            [],
            [],
            [],
            ['HTTP_X_FAKE_SIG' => $signature, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $payload,
        );
    }

    private function lockKey(): string
    {
        return sprintf(BillingWebhookController::LOCK_KEY_PATTERN, self::TENANT_ID, self::REF);
    }

    private function clearLock(): void
    {
        $this->cleanupRedis([$this->lockKey()]);
    }
}
