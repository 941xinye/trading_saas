<?php

declare(strict_types=1);

namespace Tests\Integration\Payment;

use App\Domain\Payment\PaymentInvoiceService;
use App\Http\Controllers\Api\V1\BillingWebhookController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * webhook 幂等证据链（SC-002 / FR-005）：
 * 同一 ref 重放 3 次 —— 订阅激活恰好 1 条、processed_key 计数 = 1、
 * confirmed_at 仅一次迁移、当日 meter_events 零增量；2/3 次响应 idempotent_skipped。
 *
 * 不依赖 Redis（锁为削峰件，不可用即降级；幂等正确性由条件更新 + processed_key 兜底）。
 */
class WebhookIdempotencyTest extends TestCase
{
    private const TENANT_ID = 990202;

    private string $ref = '';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 20, 0, 0, 0, 'UTC'));
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-pay-idem-990202',
            'slug' => 'poc-pay-idem-990202',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->clearLockKey();
    }

    protected function tearDown(): void
    {
        // 精确清理（先于 parent；含单飞锁键，constitution VI）
        try {
            $this->clearLockKey();
            DB::table('payment_webhook_events')->where('ref', $this->ref)->delete();
            DB::table('payment_invoices')->where('tenant_id', self::TENANT_ID)->delete();
            DB::table('subscriptions')->where('tenant_id', self::TENANT_ID)->delete();
            DB::table('tenants')->where('id', self::TENANT_ID)->delete();
        } catch (\Throwable) {
            // 清理失败不影响测试结论
        }
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_replay_three_times_is_fully_idempotent(): void
    {
        $invoice = app(PaymentInvoiceService::class)->create(self::TENANT_ID, 'pro');
        $this->ref = $invoice->ref;

        $payload = (string) json_encode([
            'event' => 'fake.settled',
            'ref' => $invoice->ref,
            'invoice_id' => 'fake_'.$invoice->ref,
            'paid_amount' => '49.00000000',
            'currency' => 'USDT',
        ]);
        $signature = hash_hmac('sha256', $payload, (string) config('payment.channels.fake.secret'));

        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $response = $this->call(
                'POST',
                '/api/v1/billing/webhook/fake',
                [],
                [],
                [],
                ['HTTP_X_FAKE_SIG' => $signature, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                $payload,
            );
            $response->assertOk();
            $results[] = $response->json('result');
        }

        static::assertSame('processed', $results[0], '首次投递必须 processed');
        static::assertSame('idempotent_skipped', $results[1], '第 2 次重放必须幂等跳过');
        static::assertSame('idempotent_skipped', $results[2], '第 3 次重放必须幂等跳过');

        // 订阅激活恰好 1 条 + tenant 升级
        static::assertSame(1, DB::table('subscriptions')->where('tenant_id', self::TENANT_ID)->where('status', 'active')->count());
        static::assertSame('pro', DB::table('tenants')->where('id', self::TENANT_ID)->value('plan_code'));

        // processed_key 计数 = 1（research R-006 可断言证据）
        static::assertSame(
            1,
            DB::table('payment_webhook_events')->where('processed_key', $invoice->ref.':fake.settled')->count(),
        );

        // confirmed_at 仅一次迁移，状态停在 confirmed
        $row = DB::table('payment_invoices')->where('ref', $invoice->ref)->first();
        static::assertSame('confirmed', $row->status);
        static::assertNotNull($row->confirmed_at);

        // 配额零副作用：meter_events 未增量
        static::assertSame(0, DB::table('meter_events')->where('tenant_id', self::TENANT_ID)->count());
    }

    private function clearLockKey(): void
    {
        if ($this->ref === '') {
            return;
        }

        try {
            Redis::connection('default')->del([
                sprintf(BillingWebhookController::LOCK_KEY_PATTERN, self::TENANT_ID, $this->ref),
            ]);
        } catch (\Throwable) {
            // Redis 不可用时无需清理（锁未生效）
        }
    }
}
