<?php

declare(strict_types=1);

namespace Tests\Integration\Payment;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * webhook 签名拒绝红线（SC-003 / FR-004）：
 * 错误签名 / 缺头 / 篡改 body 100% 拒绝（HTTP 401）、支付单状态零变更、
 * signature_valid=false 审计行 100% 留存且 raw_payload 完整。
 */
class WebhookSignatureRejectionTest extends TestCase
{
    private const TENANT_ID = 990201;

    private const REF = 'pi_sigrejection00000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 20, 0, 0, 0, 'UTC'));
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-pay-sig-990201',
            'slug' => 'poc-pay-sig-990201',
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
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_wrong_signature_rejected_with_401_and_audit_trail(): void
    {
        $payload = $this->payload();

        $response = $this->call(
            'POST',
            '/api/v1/billing/webhook/fake',
            [],
            [],
            [],
            ['HTTP_X_FAKE_SIG' => 'deadbeef', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $payload,
        );

        $response->assertStatus(401);
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('reason', 'invalid_signature');

        $this->assertZeroStateChange($payload);
    }

    public function test_missing_signature_header_rejected(): void
    {
        $payload = $this->payload();

        $response = $this->call(
            'POST',
            '/api/v1/billing/webhook/fake',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $payload,
        );

        $response->assertStatus(401);
        $this->assertZeroStateChange($payload);
    }

    public function test_tampered_body_rejected(): void
    {
        // 对 payloadA 签名，投递 payloadB（篡改 body）
        $signed = hash_hmac('sha256', $this->payload(), (string) config('payment.channels.fake.secret'));
        $tampered = (string) json_encode([
            'event' => 'fake.settled',
            'ref' => self::REF,
            'invoice_id' => 'fake_'.self::REF,
            'paid_amount' => '0.00000001',
            'currency' => 'USDT',
        ]);

        $response = $this->call(
            'POST',
            '/api/v1/billing/webhook/fake',
            [],
            [],
            [],
            ['HTTP_X_FAKE_SIG' => $signed, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $tampered,
        );

        $response->assertStatus(401);
        $this->assertZeroStateChange($tampered);
    }

    /**
     * 零状态变更 + signature_valid=false 审计行完整留存（SC-003）。
     */
    private function assertZeroStateChange(string $payload): void
    {
        static::assertSame('pending', DB::table('payment_invoices')->where('ref', self::REF)->value('status'));
        static::assertNull(DB::table('payment_invoices')->where('ref', self::REF)->value('confirmed_at'));
        static::assertSame(0, DB::table('subscriptions')->count(), '伪造签名不得触发接线');
        static::assertSame('free', DB::table('tenants')->where('id', self::TENANT_ID)->value('plan_code'));

        $audit = DB::table('payment_webhook_events')->get();
        static::assertCount(1, $audit, '每次伪造投递必须留 1 条审计行');
        static::assertSame(0, (int) $audit[0]->signature_valid);
        static::assertSame('rejected', $audit[0]->status);
        static::assertSame('invalid_signature', $audit[0]->error);
        static::assertSame($payload, $audit[0]->raw_payload, 'raw_payload 必须原样完整留存（FR-004）');
        static::assertNull($audit[0]->processed_key);
    }

    private function payload(): string
    {
        return (string) json_encode([
            'event' => 'fake.settled',
            'ref' => self::REF,
            'invoice_id' => 'fake_'.self::REF,
            'paid_amount' => '49.00000000',
            'currency' => 'USDT',
        ]);
    }
}
