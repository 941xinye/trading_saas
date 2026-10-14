<?php

declare(strict_types=1);

namespace Tests\Integration\Console;

use App\Domain\Billing\SubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * billing:refresh-status 薄命令验收（P5.5 步骤 4）。
 *
 * 状态机本身由 tests/Integration/Billing/SubscriptionLifecycleTest 覆盖；
 * 这里验证命令装配：全租户扫描 → 迁移发生 → exit 0 + 输出明细；无迁移时给出明确输出。
 * SQLite + RefreshDatabase，无 Redis。
 */
class BillingRefreshStatusCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_command_transitions_expired_subscription_to_past_due_then_suspends_tenant(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 0, 5, 0, 'UTC'));
        $tenantId = 990070;
        $this->makeTenant($tenantId, 'poc-refresh-990070');
        $sub = app(SubscriptionService::class)->provision($tenantId, 'free', 14);

        // 第一次调度：昨天到期 → past_due
        DB::table('subscriptions')->where('id', $sub->getKey())
            ->update(['ends_at' => Carbon::now()->subDay()]);

        $this->artisan('billing:refresh-status')
            ->assertExitCode(0)
            ->expectsOutputToContain('tenant='.$tenantId.' trialing → past_due');

        static::assertSame(
            'past_due',
            (string) DB::table('subscriptions')->where('id', $sub->getKey())->value('status'),
        );
        static::assertSame(
            'active',
            (string) DB::table('tenants')->where('id', $tenantId)->value('status'),
        );

        // 第二次调度：到期已超过 3 天宽限 → tenant suspended
        DB::table('subscriptions')->where('id', $sub->getKey())
            ->update(['ends_at' => Carbon::now()->subDays(10)]);

        $this->artisan('billing:refresh-status')
            ->assertExitCode(0)
            ->expectsOutputToContain('tenant='.$tenantId.' past_due → suspended');

        static::assertSame(
            'suspended',
            (string) DB::table('tenants')->where('id', $tenantId)->value('status'),
        );
    }

    public function test_command_reports_no_transitions_when_nothing_due(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 0, 5, 0, 'UTC'));
        $this->makeTenant(990071, 'poc-refresh-990071');

        $this->artisan('billing:refresh-status')
            ->assertExitCode(0)
            ->expectsOutputToContain('No subscription status transitions.');
    }

    private function makeTenant(int $id, string $slug): void
    {
        $now = Carbon::now();
        DB::table('tenants')->insert([
            'id' => $id,
            'name' => $slug,
            'slug' => $slug,
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
