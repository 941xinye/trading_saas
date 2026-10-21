<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Api\V1\BillingWebhookController;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RedisCleanup;
use Tests\TestCase;

/**
 * 计费双租户隔离 Feature 测试（spec 007 / SC-006 / FR-010；
 * 扩展 tests/Integration/Billing/TenantScopeIsolationTest.php 范式到支付全链）。
 *
 * 双租户各自完整走一笔支付（建单 → webhook 确认 → 订阅激活），
 * 断言支付单 / 订阅 / 用量零串扰、跨租户访问 100% 拒绝（404）。
 *
 * default 组：fake 通道离线投递，不依赖 Redis（锁降级不影响幂等正确性）。
 */
class BillingTenantIsolationTest extends TestCase
{
    use RedisCleanup;

    private const TENANT_A = 990311;

    private const TENANT_B = 990312;

    private User $userA;

    private User $userB;

    /** @var string[] 本用例产生的支付单号（tearDown 精确清理用） */
    private array $refs = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 20, 8, 0, 0, 'UTC'));

        foreach ([
            [self::TENANT_A, 'poc-bill-iso-a-990311'],
            [self::TENANT_B, 'poc-bill-iso-b-990312'],
        ] as [$tenantId, $slug]) {
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'name' => $slug,
                'slug' => $slug,
                'exchange_dek' => 'test-dek',
                'status' => 'active',
                'plan_code' => 'free',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        $this->userA = User::factory()->create(['tenant_id' => self::TENANT_A, 'role' => 'tenant_admin']);
        $this->userB = User::factory()->create(['tenant_id' => self::TENANT_B, 'role' => 'tenant_admin']);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->refs as $ref) {
                DB::table('payment_webhook_events')->where('ref', $ref)->delete();
            }
            DB::table('payment_invoices')->whereIn('tenant_id', [self::TENANT_A, self::TENANT_B])->delete();
            DB::table('subscriptions')->whereIn('tenant_id', [self::TENANT_A, self::TENANT_B])->delete();
            DB::table('meter_events')->whereIn('tenant_id', [self::TENANT_A, self::TENANT_B])->delete();
            DB::table('users')->whereIn('tenant_id', [self::TENANT_A, self::TENANT_B])->delete();
            DB::table('tenants')->whereIn('id', [self::TENANT_A, self::TENANT_B])->delete();
            $this->clearLocks();
        } catch (\Throwable) {
            // 清理失败不影响测试结论
        }
        TenantContext::clear();
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_two_tenants_full_payment_flow_zero_cross_contamination(): void
    {
        // ---- 双方各自建单（A→pro 49，B→enterprise 199） ----
        Sanctum::actingAs($this->userA);
        $refA = (string) $this->postJson('/api/v1/billing/invoices', ['plan_code' => 'pro'])
            ->assertCreated()->json('data.ref');

        Sanctum::actingAs($this->userB);
        $refB = (string) $this->postJson('/api/v1/billing/invoices', ['plan_code' => 'enterprise'])
            ->assertCreated()->json('data.ref');

        $this->refs = [$refA, $refB];
        static::assertNotSame($refA, $refB);

        // ---- 双方各自完成支付（fake webhook settled） ----
        $this->postFakeSettledWebhook(self::TENANT_A, $refA, '49.00000000')->assertOk();
        $this->postFakeSettledWebhook(self::TENANT_B, $refB, '199.00000000')->assertOk();

        // ---- 订阅与套餐各自生效 ----
        static::assertSame('pro', DB::table('tenants')->where('id', self::TENANT_A)->value('plan_code'));
        static::assertSame('enterprise', DB::table('tenants')->where('id', self::TENANT_B)->value('plan_code'));
        static::assertSame(1, DB::table('subscriptions')->where('tenant_id', self::TENANT_A)->where('status', 'active')->where('plan_code', 'pro')->count());
        static::assertSame(1, DB::table('subscriptions')->where('tenant_id', self::TENANT_B)->where('status', 'active')->where('plan_code', 'enterprise')->count());

        // ---- 支付单各自 confirmed，互不可见 ----
        static::assertSame('confirmed', DB::table('payment_invoices')->where('ref', $refA)->value('status'));
        static::assertSame('confirmed', DB::table('payment_invoices')->where('ref', $refB)->value('status'));

        Sanctum::actingAs($this->userA);
        $refsA = array_column((array) $this->getJson('/api/v1/billing/invoices')->assertOk()->json('data'), 'ref');
        static::assertContains($refA, $refsA);
        static::assertNotContains($refB, $refsA);
        $this->getJson('/api/v1/billing/invoices/'.$refB)->assertNotFound();

        Sanctum::actingAs($this->userB);
        $refsB = array_column((array) $this->getJson('/api/v1/billing/invoices')->assertOk()->json('data'), 'ref');
        static::assertContains($refB, $refsB);
        static::assertNotContains($refA, $refsB);
        $this->getJson('/api/v1/billing/invoices/'.$refA)->assertNotFound();

        // ---- 幂等证据链各自独立 ----
        static::assertSame(1, DB::table('payment_webhook_events')->where('processed_key', $refA.':fake.settled')->count());
        static::assertSame(1, DB::table('payment_webhook_events')->where('processed_key', $refB.':fake.settled')->count());

        // ---- 用量零串扰：A 有 3 次 ai_call，B 的 summary 计 0 ----
        for ($i = 0; $i < 3; $i++) {
            DB::table('meter_events')->insert([
                'tenant_id' => self::TENANT_A,
                'event_type' => 'ai_call',
                'quantity' => '1',
                'frozen_amount' => '1',
                'settled_amount' => '1',
                'status' => 'settled',
                'ref_id' => 'iso-a-'.$i,
                'occurred_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
        // Redis 可用时写回热键，保证两种环境同口径断言
        try {
            app(TwoPhaseMeter::class)->reconcile(self::TENANT_A, 'ai_call');
        } catch (\Throwable) {
            // Redis 不可用：summary 走 DB 降级口径，数值一致
        }

        Sanctum::actingAs($this->userA);
        static::assertSame(3, $this->getJson('/api/v1/billing/summary')->assertOk()->json('usage.ai_call.used'));

        Sanctum::actingAs($this->userB);
        static::assertSame(0, $this->getJson('/api/v1/billing/summary')->assertOk()->json('usage.ai_call.used'));
    }

    /**
     * 投递 fake 通道 settled webhook（带合法 HMAC 签名，等价真实通道投递）。
     */
    private function postFakeSettledWebhook(int $tenantId, string $ref, string $amount): TestResponse
    {
        $payload = (string) json_encode([
            'event' => 'fake.settled',
            'ref' => $ref,
            'invoice_id' => 'fake_'.$ref,
            'paid_amount' => $amount,
            'currency' => 'USDT',
        ]);
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

    private function clearLocks(): void
    {
        foreach ([self::TENANT_A, self::TENANT_B] as $tenantId) {
            foreach ($this->refs as $ref) {
                $this->cleanupRedis([
                    sprintf(BillingWebhookController::LOCK_KEY_PATTERN, $tenantId, $ref),
                ]);
            }
        }
    }
}
