<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Payment\PaymentInvoiceService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\PaymentInvoiceResource;
use App\Models\PaymentInvoice;
use App\Models\User;
use App\Policies\PaymentInvoicePolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\RedisCleanup;
use Tests\TestCase;

/**
 * 计费页 Feature 测试（spec 007 / FR-009 / FR-010 / SC-005 / SC-006）。
 *
 *  - 计费页渲染：套餐 / 周期起止 / 用量看板 / 升级 Action 首屏可见
 *  - 用量数字与 TwoPhaseMeter 口径一致（setUp 内 reconcile 把 DB 计数写回 Redis 热键，
 *    Redis 不可用时页面走 DB 降级口径 —— 两种环境断言值一致，不另算口径）
 *  - 双租户零串扰：各自只见己方用量与待支付单
 *  - 支付单产物只读：无 create/edit/delete 能力、无退款入口（FR-014 反向断言）
 *
 * default 组：不依赖 Redis / 网络。
 */
class BillingPageTest extends TestCase
{
    use RedisCleanup;

    private const TENANT_A = 990301;

    private const TENANT_B = 990302;

    private User $userA;

    private User $userB;

    /** 本轮要清的 Redis 键（逻辑名，flush 在 setUp/tearDown 两头做） */
    private array $redisKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 20, 8, 0, 0, 'UTC'));

        // 先清当日桶（坑 #25：全套连跑时前序测试残留 TTL 键会让 reconcile 基线漂移）
        $this->redisKeys = self::buildMeterKeys();
        $this->cleanupRedis($this->redisKeys);

        foreach ([
            [self::TENANT_A, 'free', 'poc-bill-page-a-990301'],
            [self::TENANT_B, 'pro', 'poc-bill-page-b-990302'],
        ] as [$tenantId, $planCode, $slug]) {
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'name' => $slug,
                'slug' => $slug,
                'exchange_dek' => 'test-dek',
                'status' => 'active',
                'plan_code' => $planCode,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        $this->userA = User::factory()->create(['tenant_id' => self::TENANT_A, 'role' => 'tenant_admin']);
        $this->userB = User::factory()->create(['tenant_id' => self::TENANT_B, 'role' => 'tenant_admin']);

        // A：ai_call 2 settled、order_placed 1 frozen（今日）；B：ai_call 5 settled
        foreach ([
            [self::TENANT_A, 'ai_call', 'settled', 2],
            [self::TENANT_A, 'order_placed', 'frozen', 1],
            [self::TENANT_B, 'ai_call', 'settled', 5],
        ] as [$tenantId, $eventType, $status, $rows]) {
            for ($i = 0; $i < $rows; $i++) {
                DB::table('meter_events')->insert([
                    'tenant_id' => $tenantId,
                    'event_type' => $eventType,
                    'quantity' => '1',
                    'frozen_amount' => '1',
                    'settled_amount' => $status === 'settled' ? '1' : null,
                    'status' => $status,
                    'ref_id' => sprintf('page-%d-%s-%d', $tenantId, $eventType, $i),
                    'occurred_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
        }

        // Redis 可用时把 DB 计数写回热键，保证 Redis/降级两种路径断言同值
        foreach ([self::TENANT_A, self::TENANT_B] as $tenantId) {
            foreach (array_keys(TwoPhaseMeter::DAILY_LIMIT_KEYS) as $eventType) {
                try {
                    app(TwoPhaseMeter::class)->reconcile($tenantId, $eventType);
                } catch (\Throwable) {
                    // Redis 不可用：页面自动走 DB 降级口径，数值一致
                }
            }
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupRedis($this->redisKeys);
            DB::table('payment_invoices')->whereIn('tenant_id', [self::TENANT_A, self::TENANT_B])->delete();
            DB::table('meter_events')->whereIn('tenant_id', [self::TENANT_A, self::TENANT_B])->delete();
            DB::table('users')->whereIn('tenant_id', [self::TENANT_A, self::TENANT_B])->delete();
            DB::table('tenants')->whereIn('id', [self::TENANT_A, self::TENANT_B])->delete();
        } catch (\Throwable) {
            // 清理失败不影响测试结论
        }
        TenantContext::clear();
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    /**
     * 构建本轮要清的全部日计数桶键（两租户 × 两类事件 × 测试日 20261020）。
     *
     * TwoPhaseMeter::key() 逻辑：`tenant:{tid}:meter:{event}:{Ymd}`。
     * 坑 #26：逻辑名传入 Redis facade，客户端自动拼一次全局前缀，不会二次拼接。
     */
    private static function buildMeterKeys(): array
    {
        $day = '20261020';
        $keys = [];
        foreach ([self::TENANT_A, self::TENANT_B] as $tid) {
            foreach (array_keys(TwoPhaseMeter::DAILY_LIMIT_KEYS) as $event) {
                $keys[] = "tenant:{$tid}:meter:{$event}:{$day}";
            }
        }

        return $keys;
    }

    public function test_billing_page_renders_plan_usage_and_upgrade_action(): void
    {
        $response = $this->actingAs($this->userA)->get('/admin/billing');

        $response->assertOk();
        // 当前套餐与用量看板（free：ai 10 次/日、下单 100 次/日）
        $response->assertSee('免费版');
        $response->assertSee('2 / 10');
        $response->assertSee('1 / 100');
        // 升级 Action 首屏可见（SC-005：点击升级 → 选套餐 → 见收款信息 ≤ 3 步）
        $response->assertSee('升级套餐');
        // 历史支付单入口
        $response->assertSee('/admin/payment-invoices', false);
    }

    public function test_billing_page_requires_authentication(): void
    {
        $this->get('/admin/billing')->assertRedirect();
    }

    public function test_usage_numbers_match_meter_for_each_tenant(): void
    {
        // B（pro：ai 500 次/日）只见己方 5 次用量
        $response = $this->actingAs($this->userB)->get('/admin/billing');
        $response->assertOk();
        $response->assertSee('专业版');
        $response->assertSee('5 / 500');
        $response->assertDontSee('2 / 10');
    }

    public function test_two_tenants_pending_invoices_are_isolated(): void
    {
        $invoiceA = app(PaymentInvoiceService::class)->create(self::TENANT_A, 'pro');
        $invoiceB = app(PaymentInvoiceService::class)->create(self::TENANT_B, 'enterprise');

        // A 见己方待支付单与金额，不见 B 的单号
        $responseA = $this->actingAs($this->userA)->get('/admin/billing');
        $responseA->assertOk();
        $responseA->assertSee($invoiceA->ref);
        $responseA->assertSee('49.00000000');
        $responseA->assertDontSee($invoiceB->ref);

        // B 反之
        $responseB = $this->actingAs($this->userB)->get('/admin/billing');
        $responseB->assertOk();
        $responseB->assertSee($invoiceB->ref);
        $responseB->assertSee('199.00000000');
        $responseB->assertDontSee($invoiceA->ref);
    }

    public function test_payment_invoice_resource_is_read_only_and_refund_free(): void
    {
        $invoice = app(PaymentInvoiceService::class)->create(self::TENANT_A, 'pro');

        $this->actingAs($this->userA);

        // 资源只读：create/update/delete 能力经 Gate 一律拒绝（policy 刻意缺省，
        // Filament canCreate/canEdit/canDelete 走的正是同一组 Gate 能力）
        static::assertFalse(Gate::check('create', PaymentInvoice::class));
        static::assertFalse(Gate::check('update', $invoice));
        static::assertFalse(Gate::check('delete', $invoice));
        static::assertTrue(Gate::check('viewAny', PaymentInvoice::class));
        static::assertSame(['index'], array_keys(PaymentInvoiceResource::getPages()));

        // FR-014 反向断言：policy 刻意不定义写能力与退款能力
        foreach (['create', 'update', 'delete', 'restore', 'forceDelete', 'refund'] as $ability) {
            static::assertFalse(
                method_exists(PaymentInvoicePolicy::class, $ability),
                sprintf('PaymentInvoicePolicy 不得定义 %s（只读 + 退款人工线下）', $ability),
            );
        }

        // 列表页可见己方单、无退款字样；create 路由不存在（404）
        $response = $this->actingAs($this->userA)->get('/admin/payment-invoices');
        $response->assertOk();
        $response->assertSee($invoice->ref);
        $response->assertDontSee('退款');

        $this->actingAs($this->userA)->get('/admin/payment-invoices/create')->assertNotFound();
    }

    public function test_payment_invoice_list_hides_other_tenant_rows(): void
    {
        $invoiceA = app(PaymentInvoiceService::class)->create(self::TENANT_A, 'pro');
        $invoiceB = app(PaymentInvoiceService::class)->create(self::TENANT_B, 'enterprise');

        $response = $this->actingAs($this->userA)->get('/admin/payment-invoices');
        $response->assertOk();
        $response->assertSee($invoiceA->ref);
        $response->assertDontSee($invoiceB->ref);
    }
}
