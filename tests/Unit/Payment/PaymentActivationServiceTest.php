<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Domain\Payment\PaymentActivationService;
use App\Models\PaymentInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * 支付确认 → 订阅激活（FR-006 / constitution IV）：
 * 条件更新命中 → provision 恰好 1 次；affected=0 → false 不重复接线；
 * 事务内异常 → 支付单状态回滚（不存在半激活）。
 */
class PaymentActivationServiceTest extends TestCase
{
    private const TENANT_ID = 990102;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 20, 0, 0, 0, 'UTC'));
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-pay-act-990102',
            'slug' => 'poc-pay-act-990102',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_activate_confirms_provisions_and_writes_processed_key(): void
    {
        $invoice = $this->makeInvoice('pi_actok00000000000000001', 'pro');
        $service = app(PaymentActivationService::class);

        $result = $service->activate($invoice, '49.00000000', 'fake.settled', '{"event":"fake.settled"}');

        static::assertTrue($result);
        $row = DB::table('payment_invoices')->where('id', $invoice->getKey())->first();
        static::assertSame('confirmed', $row->status);
        static::assertNotNull($row->confirmed_at);
        // 金额经模型 decimal:8 cast 读出（生产读路径），sqlite 驱动直读会归一化丢尾零
        static::assertSame('49.00000000', (string) $invoice->refresh()->paid_amount_usdt);

        // provision 恰好 1 次：1 条 active 订阅行 + tenant 升级
        static::assertSame(1, DB::table('subscriptions')->where('tenant_id', self::TENANT_ID)->where('status', 'active')->count());
        static::assertSame('pro', DB::table('tenants')->where('id', self::TENANT_ID)->value('plan_code'));

        // 审计行：processed_key = {ref}:{event_type}，恰好 1 条
        static::assertSame(
            1,
            DB::table('payment_webhook_events')->where('processed_key', $invoice->ref.':fake.settled')->count(),
        );
    }

    public function test_replay_returns_false_without_duplicate_provision(): void
    {
        $invoice = $this->makeInvoice('pi_actreplay0000000000001', 'pro');
        $service = app(PaymentActivationService::class);

        static::assertTrue($service->activate($invoice, '49.00000000', 'fake.settled', '{}'));
        // 重放：条件更新 affected=0 → false，不重复接线
        static::assertFalse($service->activate($invoice->refresh(), '49.00000000', 'fake.settled', '{}'));

        static::assertSame(1, DB::table('subscriptions')->where('tenant_id', self::TENANT_ID)->count());
        static::assertSame(
            1,
            DB::table('payment_webhook_events')->where('processed_key', $invoice->ref.':fake.settled')->count(),
        );
        // confirmed_at 只有一次迁移（第二次未推动 updated_at 之外的变更）
        static::assertSame('confirmed', DB::table('payment_invoices')->where('id', $invoice->getKey())->value('status'));
    }

    public function test_terminal_invoice_is_not_rewired(): void
    {
        $invoice = $this->makeInvoice('pi_actterminal0000000001', 'pro', 'expired');
        $service = app(PaymentActivationService::class);

        static::assertFalse($service->activate($invoice, '49.00000000', 'fake.settled', '{}'));
        static::assertSame('expired', DB::table('payment_invoices')->where('id', $invoice->getKey())->value('status'));
        static::assertSame(0, DB::table('subscriptions')->count());
    }

    public function test_exception_rolls_back_invoice_status(): void
    {
        // plan_code 不存在 → provision 抛 RuntimeException → 整体回滚（无半激活）
        $invoice = $this->makeInvoice('pi_actrollback0000000001', 'ghost_plan');
        $service = app(PaymentActivationService::class);

        try {
            $service->activate($invoice, '49.00000000', 'fake.settled', '{}');
            static::fail('未知 plan_code 必须抛异常');
        } catch (RuntimeException) {
            // expected
        }

        static::assertSame('pending', DB::table('payment_invoices')->where('id', $invoice->getKey())->value('status'), '事务回滚后支付单回原状态');
        static::assertNull(DB::table('payment_invoices')->where('id', $invoice->getKey())->value('confirmed_at'));
        static::assertSame(0, DB::table('subscriptions')->count());
        static::assertSame(0, DB::table('payment_webhook_events')->count());
        static::assertSame('free', DB::table('tenants')->where('id', self::TENANT_ID)->value('plan_code'));
    }

    private function makeInvoice(string $ref, string $planCode, string $status = 'pending'): PaymentInvoice
    {
        DB::table('payment_invoices')->insert([
            'tenant_id' => self::TENANT_ID,
            'ref' => $ref,
            'channel_code' => 'fake',
            'plan_code' => $planCode,
            'currency' => 'USDT',
            'chain' => 'TRC20',
            'amount_usdt' => '49.00000000',
            'status' => $status,
            'expires_at' => Carbon::now('UTC')->addMinutes(30),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        /** @var PaymentInvoice $invoice */
        $invoice = PaymentInvoice::query()->withoutGlobalScopes()->where('ref', $ref)->firstOrFail();

        return $invoice;
    }
}
