<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Domain\Billing\Plans\PlanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * 套餐解析/限额/白名单（迁自 poc:verify-billing 第 1 段）。
 *
 * SQLite + RefreshDatabase；以真实 tenants 行驱动 plan_code 解析（与 POC 一致），
 * 不依赖 Redis/网络。未知套餐必须抛异常，绝不静默降级 free。
 */
class PlanServiceTest extends TestCase
{
    private const TENANT_ID = 990030;

    private PlanService $plans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plans = app(PlanService::class);
        $now = Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC');
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-billing-plan-990030',
            'slug' => 'poc-billing-plan-990030',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_free_plan_ai_calls_limit_is_ten(): void
    {
        static::assertSame(10, $this->plans->limit($this->plans->tenant(self::TENANT_ID), 'ai_calls_per_day'));
    }

    public function test_free_plan_max_signal_subscriptions_is_one(): void
    {
        static::assertSame(
            1,
            $this->plans->limit($this->plans->tenant(self::TENANT_ID), 'max_signal_subscriptions'),
        );
    }

    public function test_system_log_channel_is_always_allowed(): void
    {
        static::assertTrue($this->plans->allowsChannel($this->plans->tenant(self::TENANT_ID), 'log'));
    }

    public function test_free_channel_whitelist_allows_websocket_and_denies_telegram(): void
    {
        $tenant = $this->plans->tenant(self::TENANT_ID);

        static::assertTrue($this->plans->allowsChannel($tenant, 'websocket'));
        static::assertFalse($this->plans->allowsChannel($tenant, 'telegram'));
    }

    public function test_free_strategy_whitelist_allows_spot_grid_and_denies_futures_grid(): void
    {
        $tenant = $this->plans->tenant(self::TENANT_ID);

        static::assertTrue($this->plans->allowsStrategy($tenant, 'spot_grid'));
        static::assertFalse($this->plans->allowsStrategy($tenant, 'futures_grid'));
    }

    public function test_pro_plan_has_500_ai_calls_and_telegram_channel(): void
    {
        DB::table('tenants')->where('id', self::TENANT_ID)->update(['plan_code' => 'pro']);
        $tenant = $this->plans->tenant(self::TENANT_ID);

        static::assertSame(500, $this->plans->limit($tenant, 'ai_calls_per_day'));
        static::assertTrue($this->plans->allowsChannel($tenant, 'telegram'));

        DB::table('tenants')->where('id', self::TENANT_ID)->update(['plan_code' => 'free']);
    }

    public function test_unknown_plan_code_throws_instead_of_silent_free_fallback(): void
    {
        DB::table('tenants')->where('id', self::TENANT_ID)->update(['plan_code' => 'ghost-plan']);

        try {
            $this->expectException(RuntimeException::class);
            $this->plans->planConfig($this->plans->tenant(self::TENANT_ID));
        } finally {
            DB::table('tenants')->where('id', self::TENANT_ID)->update(['plan_code' => 'free']);
        }
    }
}
