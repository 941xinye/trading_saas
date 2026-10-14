<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Domain\Billing\QuotaGuard;
use App\Domain\Billing\SubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 订阅状态机（迁自 poc:verify-billing 第 4 段）：
 *
 *   provision(trial=14d) → trialing → 到期 refresh → past_due（权益拦截）
 *   → 超过宽限 refresh → tenant suspended（权益拦截）
 *   → provision(pro,0) 续费恢复 active + plan_code=pro → cancel → canceled（权益拦截）
 *
 * SQLite + RefreshDatabase；无 Redis（QuotaGuard 权益判断只读 tenants/subscriptions）。
 */
class SubscriptionLifecycleTest extends TestCase
{
    private const TENANT_ID = 990032;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC'));
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-billing-sub-990032',
            'slug' => 'poc-billing-sub-990032',
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

    public function test_trial_past_due_suspend_restore_and_cancel_lifecycle(): void
    {
        $service = app(SubscriptionService::class);
        $guard = app(QuotaGuard::class);
        $tenantId = self::TENANT_ID;

        // ---- provision(trial=14d) → trialing，期内权益正常 ----
        $sub = $service->provision($tenantId, 'free', 14);
        static::assertSame('trialing', $sub->status);
        static::assertTrue($guard->isEntitled($tenantId), 'trialing 期内权益应正常');

        // ---- 回拨到期日：trialing → past_due，权益拦截 ----
        DB::table('subscriptions')->where('id', $sub->getKey())->update(['ends_at' => Carbon::now()->subDay()]);
        $transitions = $service->refreshStatus();
        $fresh = $service->latestFor($tenantId);
        $hitPastDue = false;
        foreach ($transitions as $transition) {
            if ($transition['tenant_id'] === $tenantId && $transition['to'] === 'past_due') {
                $hitPastDue = true;
            }
        }
        static::assertTrue($hitPastDue && $fresh->status === 'past_due', '试用到期 refresh 应迁移到 past_due');
        static::assertFalse($guard->isEntitled($tenantId), 'past_due 权益必须拦截');

        // ---- 超过宽限（默认 3 天，回拨 10 天）：tenant suspended ----
        DB::table('subscriptions')->where('id', $sub->getKey())
            ->update(['ends_at' => Carbon::now()->subDays(10)]);
        $service->refreshStatus();
        static::assertSame(
            'suspended',
            (string) DB::table('tenants')->where('id', $tenantId)->value('status'),
        );
        static::assertFalse($guard->isEntitled($tenantId), 'suspended 权益必须拦截');

        // ---- 续费恢复：provision(pro, 0) → active + tenant active + plan_code=pro ----
        $restored = $service->provision($tenantId, 'pro', 0);
        static::assertSame('active', $restored->status);
        static::assertSame(
            'active',
            (string) DB::table('tenants')->where('id', $tenantId)->value('status'),
        );
        static::assertSame(
            'pro',
            (string) DB::table('tenants')->where('id', $tenantId)->value('plan_code'),
        );
        static::assertTrue($guard->isEntitled($tenantId), '恢复后权益应正常');

        // ---- 取消：canceled 且权益拦截 ----
        $service->cancel($tenantId);
        static::assertSame('canceled', $service->latestFor($tenantId)->status);
        static::assertFalse($guard->isEntitled($tenantId), 'canceled 权益必须拦截');
    }
}
