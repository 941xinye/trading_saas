<?php

declare(strict_types=1);

namespace Tests\Integration\Payment;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * webhook 乱序防护与未知 ref（FR-003 / PoC B3 语义）：
 * 先 confirmed 后到 processing → 状态不回退且落 idempotent_skipped；
 * ref 不存在 / metadata.ref 缺失 / ref 格式非法 → 200 + rejected，且不影响其他支付单。
 */
class WebhookOrderingAndUnknownRefTest extends TestCase
{
    private const TENANT_ID = 990203;

    private const REF = 'pi_orderingtest00000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 20, 0, 0, 0, 'UTC'));
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-pay-ord-990203',
            'slug' => 'poc-pay-ord-990203',
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

    public function test_out_of_order_processing_after_confirmed_is_discarded(): void
    {
        // 先足额到账 → confirmed
        $settle = $this->deliver(self::REF, 'fake.settled', '49.00000000');
        $settle->assertOk();
        $settle->assertJsonPath('result', 'processed');
        static::assertSame('confirmed', DB::table('payment_invoices')->where('ref', self::REF)->value('status'));

        // 后到 processing（乱序）→ 丢弃，状态不回退
        $late = $this->deliver(self::REF, 'fake.processing', null);
        $late->assertOk();
        $late->assertJsonPath('result', 'idempotent_skipped');
        static::assertSame('confirmed', DB::table('payment_invoices')->where('ref', self::REF)->value('status'), '状态只进不退，不得回退');

        // 乱序投递留 idempotent_skipped 审计行
        static::assertSame(
            1,
            DB::table('payment_webhook_events')
                ->where('ref', self::REF)
                ->where('event_type', 'fake.processing')
                ->where('status', 'idempotent_skipped')
                ->count(),
        );
    }

    public function test_unknown_ref_rejected_without_touching_other_invoices(): void
    {
        $unknownRef = 'pi_00000000000000000000000000';

        $response = $this->deliver($unknownRef, 'fake.settled', '49.00000000');

        $response->assertOk();
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('reason', 'invoice_not_found');

        $audit = DB::table('payment_webhook_events')->where('ref', $unknownRef)->first();
        static::assertNotNull($audit);
        static::assertSame('rejected', $audit->status);
        static::assertSame('invoice_not_found', $audit->error);

        // 既有支付单不受影响
        static::assertSame('pending', DB::table('payment_invoices')->where('ref', self::REF)->value('status'));
        static::assertSame(0, DB::table('subscriptions')->count());
    }

    public function test_missing_ref_rejected(): void
    {
        $payload = (string) json_encode([
            'event' => 'fake.settled',
            'invoice_id' => 'fake_whatever',
            'paid_amount' => '49.00000000',
            'currency' => 'USDT',
        ]);

        $response = $this->deliverRaw($payload);

        $response->assertOk();
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('reason', 'invoice_not_found');

        $audit = DB::table('payment_webhook_events')->where('event_type', 'fake.settled')->latest('id')->first();
        static::assertSame('rejected', $audit->status);
        static::assertSame('invalid_ref', $audit->error);

        static::assertSame('pending', DB::table('payment_invoices')->where('ref', self::REF)->value('status'));
    }

    public function test_malformed_ref_rejected(): void
    {
        // 格式非法（非 ^pi_[0-9a-z]{26}$）：注入/超长防御（T038）
        $response = $this->deliver("pi_xxx' OR '1'='1", 'fake.settled', '49.00000000');

        $response->assertOk();
        $response->assertJsonPath('ok', false);
        $response->assertJsonPath('reason', 'invoice_not_found');

        static::assertSame('pending', DB::table('payment_invoices')->where('ref', self::REF)->value('status'));
    }

    private function deliver(string $ref, string $event, ?string $paid): TestResponse
    {
        return $this->deliverRaw((string) json_encode([
            'event' => $event,
            'ref' => $ref,
            'invoice_id' => 'fake_'.$ref,
            'paid_amount' => $paid,
            'currency' => 'USDT',
        ]));
    }

    private function deliverRaw(string $payload): TestResponse
    {
        $signature = hash_hmac('sha256', $payload, (string) config('payment.channels.fake.secret'));

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
}
