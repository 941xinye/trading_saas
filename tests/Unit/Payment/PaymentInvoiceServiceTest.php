<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Domain\Payment\Contracts\PaymentChannel;
use App\Domain\Payment\Dto\PaymentInvoiceData;
use App\Domain\Payment\Dto\PaymentInvoiceRequest;
use App\Domain\Payment\Dto\WebhookEvent;
use App\Domain\Payment\Exceptions\PaymentChannelException;
use App\Domain\Payment\PaymentInvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * 支付单服务（FR-002 / FR-011 / FR-012）：
 * ref 格式与唯一性、同套餐复用不堆叠、TTL 边界、过期清扫条件、
 * 金额字符串逐位相等、通道失败/金额不符无残留行。
 */
class PaymentInvoiceServiceTest extends TestCase
{
    private const TENANT_ID = 990101;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 20, 0, 0, 0, 'UTC'));
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-pay-inv-990101',
            'slug' => 'poc-pay-inv-990101',
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

    public function test_create_generates_ref_format_and_8dp_amount_and_ttl(): void
    {
        $service = app(PaymentInvoiceService::class);

        $invoice = $service->create(self::TENANT_ID, 'pro');

        static::assertMatchesRegularExpression('/^pi_[0-9a-z]{26}$/', $invoice->ref);
        // 金额字符串逐位相等（pro = 49 → 8 位小数，FR-011）
        static::assertSame('49.00000000', (string) $invoice->amount_usdt);
        static::assertSame('pending', (string) $invoice->status);
        static::assertSame('fake', (string) $invoice->channel_code);
        static::assertSame('fake_'.$invoice->ref, (string) $invoice->channel_invoice_id);
        static::assertNotEmpty($invoice->pay_address);
        // TTL 边界：expires_at = now + 30 分钟（config payment.invoice_ttl_minutes）
        static::assertSame(
            Carbon::now('UTC')->addMinutes(30)->toDateTimeString(),
            $invoice->expires_at->setTimezone('UTC')->toDateTimeString(),
        );

        // ref 全局唯一：第二笔（另一套餐）ref 不同
        $other = $service->create(self::TENANT_ID, 'enterprise');
        static::assertNotSame($invoice->ref, $other->ref);
        static::assertSame('199.00000000', (string) $other->amount_usdt);
    }

    public function test_same_plan_reuses_open_invoice_without_stacking(): void
    {
        $service = app(PaymentInvoiceService::class);

        $first = $service->create(self::TENANT_ID, 'pro');
        $second = $service->create(self::TENANT_ID, 'pro');

        static::assertSame($first->getKey(), $second->getKey(), '同租户同目标套餐必须复用既有非终态单（US4-3）');
        static::assertSame(1, DB::table('payment_invoices')->where('tenant_id', self::TENANT_ID)->where('plan_code', 'pro')->count());
    }

    public function test_new_invoice_allowed_after_previous_terminal(): void
    {
        $service = app(PaymentInvoiceService::class);
        $first = $service->create(self::TENANT_ID, 'pro');

        DB::table('payment_invoices')->where('id', $first->getKey())->update(['status' => 'expired']);

        $second = $service->create(self::TENANT_ID, 'pro');
        static::assertNotSame($first->getKey(), $second->getKey(), '终态单不阻塞新建');
    }

    public function test_unknown_plan_throws(): void
    {
        $service = app(PaymentInvoiceService::class);

        $this->expectException(RuntimeException::class);
        $service->create(self::TENANT_ID, 'ghost_plan');
    }

    public function test_channel_failure_leaves_no_row(): void
    {
        config()->set('payment.channels.throwing', ['driver' => ThrowingPaymentChannel::class]);
        $service = app(PaymentInvoiceService::class);

        try {
            $service->create(self::TENANT_ID, 'pro', 'throwing');
            static::fail('通道异常必须抛出');
        } catch (PaymentChannelException) {
            // expected
        }

        static::assertSame(0, DB::table('payment_invoices')->count(), '通道失败不得残留半状态单（spec Edge）');
    }

    public function test_channel_amount_mismatch_rejected_and_no_row(): void
    {
        config()->set('payment.channels.mismatch', ['driver' => MismatchedAmountChannel::class]);
        $service = app(PaymentInvoiceService::class);

        try {
            $service->create(self::TENANT_ID, 'pro', 'mismatch');
            static::fail('通道金额不符必须拒建单');
        } catch (PaymentChannelException) {
            // expected
        }

        static::assertSame(0, DB::table('payment_invoices')->count(), '金额不符拒建单后不得残留行（FR-002）');
    }

    public function test_pending_for_filters_plan_and_open_status(): void
    {
        $service = app(PaymentInvoiceService::class);
        $pro = $service->create(self::TENANT_ID, 'pro');
        $service->create(self::TENANT_ID, 'enterprise');

        static::assertSame($pro->getKey(), $service->pendingFor(self::TENANT_ID, 'pro')?->getKey());
        static::assertSame(2, DB::table('payment_invoices')->count());

        DB::table('payment_invoices')->where('id', $pro->getKey())->update(['status' => 'confirmed']);
        static::assertNull($service->pendingFor(self::TENANT_ID, 'pro'), '终态单不属于待支付');
        static::assertNotNull($service->pendingFor(self::TENANT_ID), '不限套餐时仍有其他待支付单');
    }

    public function test_expire_stale_only_sweeps_open_overdue_rows(): void
    {
        $service = app(PaymentInvoiceService::class);
        $past = Carbon::now('UTC')->subMinute();

        // 过期非终态 ×2（pending / partial）、未到期 pending ×1、已过期 confirmed ×1
        $seed = function (string $ref, string $status, Carbon $expiresAt): void {
            DB::table('payment_invoices')->insert([
                'tenant_id' => self::TENANT_ID,
                'ref' => $ref,
                'channel_code' => 'fake',
                'plan_code' => 'pro',
                'currency' => 'USDT',
                'chain' => 'TRC20',
                'amount_usdt' => '49.00000000',
                'status' => $status,
                'expires_at' => $expiresAt,
                'confirmed_at' => $status === 'confirmed' ? Carbon::now('UTC') : null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        };
        $seed('pi_stalepending00000000001', 'pending', $past);
        $seed('pi_stalepartial00000000001', 'partial', $past);
        $seed('pi_freshpending00000000001', 'pending', Carbon::now('UTC')->addMinutes(30));
        $seed('pi_staleconfirmed0000000001', 'confirmed', $past);

        $count = $service->expireStale();

        static::assertSame(2, $count, '仅清扫「非终态且已过期」');
        static::assertSame('expired', DB::table('payment_invoices')->where('ref', 'pi_stalepending00000000001')->value('status'));
        static::assertSame('expired', DB::table('payment_invoices')->where('ref', 'pi_stalepartial00000000001')->value('status'));
        static::assertSame('pending', DB::table('payment_invoices')->where('ref', 'pi_freshpending00000000001')->value('status'), '未到期单不受影响');
        static::assertSame('confirmed', DB::table('payment_invoices')->where('ref', 'pi_staleconfirmed0000000001')->value('status'), '终态单只进不退');

        // 幂等：再扫为零
        static::assertSame(0, $service->expireStale());
    }
}

/**
 * 测试 stub：建单即抛异常的通道（通道失败无残留行断言用）。
 */
final class ThrowingPaymentChannel implements PaymentChannel
{
    public function code(): string
    {
        return 'throwing';
    }

    public function createInvoice(PaymentInvoiceRequest $request): PaymentInvoiceData
    {
        throw new PaymentChannelException('模拟通道不可用');
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return false;
    }

    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        throw new PaymentChannelException('not supported');
    }
}

/**
 * 测试 stub：返回金额 ≠ 应付的通道（金额不符拒建单断言用）。
 */
final class MismatchedAmountChannel implements PaymentChannel
{
    public function code(): string
    {
        return 'mismatch';
    }

    public function createInvoice(PaymentInvoiceRequest $request): PaymentInvoiceData
    {
        return new PaymentInvoiceData(
            channelInvoiceId: 'mm_'.$request->ref,
            payAddress: 'TMISMATCH',
            payUri: null,
            amount: '0.00000001',
            expiresAt: null,
        );
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        return false;
    }

    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        throw new PaymentChannelException('not supported');
    }
}
