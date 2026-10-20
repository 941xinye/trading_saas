<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\PaymentInvoiceService;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 计费 API Feature 测试（spec 007 / FR-009 / FR-010，契约 §6）。
 *
 *  - summary / invoices / show 返回结构
 *  - POST invoices 生成 + 复用待支付单（US4-3 不堆叠）与参数校验
 *  - 跨租户 {ref} 一律 404（不泄露存在性，SC-006）
 *
 * default 组：fake 通道离线建单，不依赖 Redis / 网络。
 */
class BillingApiTest extends TestCase
{
    private const TENANT_A = 990321;

    private const TENANT_B = 990322;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 20, 8, 0, 0, 'UTC'));

        foreach ([
            [self::TENANT_A, 'poc-bill-api-a-990321'],
            [self::TENANT_B, 'poc-bill-api-b-990322'],
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
            DB::table('payment_invoices')->whereIn('tenant_id', [self::TENANT_A, self::TENANT_B])->delete();
            DB::table('users')->whereIn('tenant_id', [self::TENANT_A, self::TENANT_B])->delete();
            DB::table('tenants')->whereIn('id', [self::TENANT_A, self::TENANT_B])->delete();
        } catch (\Throwable) {
            // 清理失败不影响测试结论
        }
        TenantContext::clear();
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_summary_returns_plan_and_usage_structure(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/v1/billing/summary');

        $response->assertOk()
            ->assertJsonPath('plan_code', 'free')
            ->assertJsonStructure([
                'plan_code',
                'plan_name',
                'price_monthly',
                'subscription',
                'usage' => [
                    'ai_call' => ['used', 'limit'],
                    'order_placed' => ['used', 'limit'],
                ],
            ]);
        static::assertSame(10, $response->json('usage.ai_call.limit'));
        static::assertSame(100, $response->json('usage.order_placed.limit'));
    }

    public function test_store_creates_pending_invoice_then_reuses_it(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/v1/billing/invoices', ['plan_code' => 'pro']);

        $response->assertCreated()
            ->assertJsonPath('data.plan_code', 'pro')
            ->assertJsonPath('data.amount_usdt', '49.00000000')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure([
                'data' => [
                    'ref', 'channel_code', 'currency', 'chain', 'pay_address',
                    'pay_uri', 'expires_at', 'created_at',
                ],
            ]);

        $ref = (string) $response->json('data.ref');
        static::assertStringStartsWith('pi_', $ref);
        static::assertNotEmpty($response->json('data.pay_address'));

        // US4-3：重复发起 → 复用既有待支付单，不堆叠
        $second = $this->postJson('/api/v1/billing/invoices', ['plan_code' => 'pro']);
        $second->assertCreated()->assertJsonPath('data.ref', $ref);
        static::assertSame(
            1,
            DB::table('payment_invoices')->where('tenant_id', self::TENANT_A)->count(),
        );
    }

    public function test_store_validates_plan_code(): void
    {
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/v1/billing/invoices', [])->assertUnprocessable();
        $this->postJson('/api/v1/billing/invoices', ['plan_code' => 'nope'])->assertUnprocessable();
    }

    public function test_invoices_lists_only_own_rows(): void
    {
        $invoiceA = app(PaymentInvoiceService::class)->create(self::TENANT_A, 'pro');
        $invoiceB = app(PaymentInvoiceService::class)->create(self::TENANT_B, 'enterprise');

        Sanctum::actingAs($this->userA);
        $response = $this->getJson('/api/v1/billing/invoices');

        $response->assertOk();
        $refs = array_column((array) $response->json('data'), 'ref');
        static::assertContains($invoiceA->ref, $refs);
        static::assertNotContains($invoiceB->ref, $refs);
    }

    public function test_show_cross_tenant_returns_404(): void
    {
        $invoiceA = app(PaymentInvoiceService::class)->create(self::TENANT_A, 'pro');
        $invoiceB = app(PaymentInvoiceService::class)->create(self::TENANT_B, 'enterprise');

        Sanctum::actingAs($this->userA);

        // 己方单可见
        $this->getJson('/api/v1/billing/invoices/'.$invoiceA->ref)
            ->assertOk()
            ->assertJsonPath('data.ref', $invoiceA->ref);

        // 跨租户 → 404（不泄露存在性）
        $this->getJson('/api/v1/billing/invoices/'.$invoiceB->ref)->assertNotFound();

        Sanctum::actingAs($this->userB);
        $this->getJson('/api/v1/billing/invoices/'.$invoiceA->ref)->assertNotFound();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/billing/summary')->assertUnauthorized();
        $this->getJson('/api/v1/billing/invoices')->assertUnauthorized();
        $this->postJson('/api/v1/billing/invoices', ['plan_code' => 'pro'])->assertUnauthorized();
    }
}
