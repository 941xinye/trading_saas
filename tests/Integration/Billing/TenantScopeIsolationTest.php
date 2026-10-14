<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Domain\Tenancy\TenantContext;
use App\Models\MeterEvent;
use App\Models\SignalSubscription;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TenantScope 多租户隔离（迁自 poc:verify-billing 第 9 段）。
 *
 * 租户上下文双向不可见对方 meter/subscription/signal_subscription；
 * 平台级（无上下文）可显式按 tenant_id 聚合。
 *
 * 计量行/订阅行直接用 DB::table 造数（本测试只验证读 Scope，不验证计量），
 * SQLite + RefreshDatabase，无 Redis。
 */
class TenantScopeIsolationTest extends TestCase
{
    private const TENANT_A = 990038;

    private const TENANT_B = 990039;

    protected function setUp(): void
    {
        parent::setUp();

        $now = Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC');
        foreach ([
            [self::TENANT_A, 'poc-billing-iso-a-990038'],
            [self::TENANT_B, 'poc-billing-iso-b-990039'],
        ] as [$tenantId, $slug]) {
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'name' => $slug,
                'slug' => $slug,
                'exchange_dek' => 'test-dek',
                'status' => 'active',
                'plan_code' => 'free',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // A 两条 meter（含 v2-1 settled），B 一条
        foreach ([
            [self::TENANT_A, 'v2-1', 'settled'],
            [self::TENANT_A, 'v2-2', 'reversed'],
            [self::TENANT_B, 'b-1', 'frozen'],
        ] as [$tenantId, $refId, $status]) {
            DB::table('meter_events')->insert([
                'tenant_id' => $tenantId,
                'event_type' => 'ai_call',
                'quantity' => '1',
                'frozen_amount' => '1',
                'settled_amount' => $status === 'settled' ? '1' : null,
                'status' => $status,
                'ref_id' => $refId,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([self::TENANT_A, self::TENANT_B] as $tenantId) {
            DB::table('subscriptions')->insert([
                'tenant_id' => $tenantId,
                'plan_code' => 'free',
                'status' => 'active',
                'started_at' => $now,
                'ends_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('signal_subscriptions')->insert([
            'tenant_id' => self::TENANT_A,
            'name' => 'poc-signal-ETHUSDT',
            'symbols' => json_encode(['ETHUSDT']),
            'intervals' => json_encode(['5m']),
            'ai_prompter' => 'deepseek',
            'ai_model' => null,
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(['log']),
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_tenant_scope_hides_other_tenant_rows_both_directions(): void
    {
        // ---- 上下文 A ----
        TenantContext::initialize(self::TENANT_A);
        $aSeesV2 = MeterEvent::query()->where('ref_id', 'v2-1')->exists();
        $aMeterCount = MeterEvent::query()->count();
        $aSignalSubs = SignalSubscription::query()->count();
        $bSubscriptionVisibleFromA = Subscription::query()->where('tenant_id', self::TENANT_B)->count();
        TenantContext::clear();

        // ---- 上下文 B ----
        TenantContext::initialize(self::TENANT_B);
        $bSeesV2 = MeterEvent::query()->where('ref_id', 'v2-1')->exists();
        $bMeterCount = MeterEvent::query()->count();
        $aSubscriptionVisibleFromB = Subscription::query()->where('tenant_id', self::TENANT_A)->count();
        $aSignalSubsFromB = SignalSubscription::query()->where('tenant_id', self::TENANT_A)->count();
        TenantContext::clear();

        // A 的 v2-1 计量行仅 A 可见
        static::assertTrue($aSeesV2);
        static::assertFalse($bSeesV2);

        // 双方各自只见己方 meter
        static::assertSame(2, $aMeterCount);
        static::assertSame(1, $bMeterCount);

        // Subscription 双向跨租户查询为 0 行
        static::assertSame(0, $bSubscriptionVisibleFromA);
        static::assertSame(0, $aSubscriptionVisibleFromB);

        // SignalSubscription：A 自检可见，B 查 A 为 0
        static::assertGreaterThanOrEqual(1, $aSignalSubs);
        static::assertSame(0, $aSignalSubsFromB);

        // ---- 平台级（无上下文）：显式按租户聚合，两家都可见 ----
        $platformA = TenantContext::runWithoutTenant(
            fn () => MeterEvent::query()->withoutGlobalScopes()->where('tenant_id', self::TENANT_A)->count(),
        );
        $platformB = TenantContext::runWithoutTenant(
            fn () => MeterEvent::query()->withoutGlobalScopes()->where('tenant_id', self::TENANT_B)->count(),
        );
        static::assertSame($aMeterCount, $platformA);
        static::assertGreaterThan(0, $platformA);
        static::assertSame($bMeterCount, $platformB);
    }
}
