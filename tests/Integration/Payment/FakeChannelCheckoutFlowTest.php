<?php

declare(strict_types=1);

namespace Tests\Integration\Payment;

use App\Domain\Billing\QuotaGuard;
use App\Domain\Payment\PaymentInvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * US1 全链路（Independent Test）：
 * free 租户 → fake 通道建单（pro）→ fake webhook 足额到账 → 支付单 confirmed
 * → 订阅 active + tenants.plan_code='pro' → 配额按 pro 级放行。
 *
 * 平台侧「确认到账 → 激活 → 配额可用」耗时 MUST ≤ 30 秒（SC-001）。
 */
class FakeChannelCheckoutFlowTest extends TestCase
{
    private const TENANT_ID = 990103;

    private string $ref = '';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 20, 0, 0, 0, 'UTC'));
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-pay-flow-990103',
            'slug' => 'poc-pay-flow-990103',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    protected function tearDown(): void
    {
        // 精确清理（先于 parent；RefreshDatabase 之外的兜底范式，constitution VI）
        try {
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

    public function test_fake_channel_checkout_full_flow(): void
    {
        // ---- ① 建单（free → pro）----
        $invoice = app(PaymentInvoiceService::class)->create(self::TENANT_ID, 'pro');
        $this->ref = $invoice->ref;
        static::assertSame('pending', (string) $invoice->status);
        static::assertSame('49.00000000', (string) $invoice->amount_usdt);
        static::assertNotEmpty($invoice->pay_address);

        // ---- ② fake 通道投递足额到账 webhook（带 HMAC 签名）----
        $payload = (string) json_encode([
            'event' => 'fake.settled',
            'ref' => $invoice->ref,
            'invoice_id' => 'fake_'.$invoice->ref,
            'paid_amount' => '49.00000000',
            'currency' => 'USDT',
        ]);
        $signature = hash_hmac('sha256', $payload, (string) config('payment.channels.fake.secret'));

        $startedAt = microtime(true);
        $response = $this->call(
            'POST',
            '/api/v1/billing/webhook/fake',
            [],
            [],
            [],
            ['HTTP_X_FAKE_SIG' => $signature, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $payload,
        );
        $elapsed = microtime(true) - $startedAt;

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('result', 'processed');

        // ---- ③ 支付单 confirmed + confirmed_at 非空 ----
        $row = DB::table('payment_invoices')->where('ref', $invoice->ref)->first();
        static::assertSame('confirmed', $row->status);
        static::assertNotNull($row->confirmed_at);
        // 金额经模型 decimal:8 cast 读出（生产读路径），sqlite 驱动直读会归一化丢尾零
        static::assertSame('49.00000000', (string) $invoice->refresh()->paid_amount_usdt);

        // ---- ④ 订阅激活：新增 1 条 active 行 + tenant 升级 ----
        static::assertSame(1, DB::table('subscriptions')->where('tenant_id', self::TENANT_ID)->where('status', 'active')->count());
        static::assertSame('pro', DB::table('tenants')->where('id', self::TENANT_ID)->value('plan_code'));

        // ---- ⑤ 配额按新套餐放行（pro 级策略 + 权益）----
        $guard = app(QuotaGuard::class);
        $guard->assertEntitled(self::TENANT_ID);
        $guard->assertStrategyAllowed(self::TENANT_ID, 'custom_dsl'); // free 不含、pro 含

        // ---- ⑥ SC-001：确认 → 激活 → 配额可用 ≤ 30 秒 ----
        static::assertLessThan(30.0, $elapsed, '确认到账 → 激活 → 配额可用必须 ≤ 30 秒（SC-001）');

        // ---- ⑦ 审计行：processed_key 恰好 1 条 ----
        static::assertSame(
            1,
            DB::table('payment_webhook_events')->where('processed_key', $invoice->ref.':fake.settled')->count(),
        );
    }
}
