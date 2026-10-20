<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Domain\Billing\EntitlementException;
use App\Domain\Strategy\StrategyGateway;
use App\Domain\Tenancy\TenantContext;
use App\Models\StrategyInstance;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 自定义 DSL 售卖门控（tasks T033 / FR-015/016 / US5-1）。
 *
 * 与既有 PlanStrategyGateTest 同层（RefreshDatabase）：
 *  - pro / enterprise 租户可创建 custom_dsl 实例
 *  - free 租户被 EntitlementException 拒绝，且不落库
 *  - 实例走 TenantScope：跨租户草稿不可见
 */
class DslPlanGateTest extends TestCase
{
    private const FREE = 990110;

    private const PRO = 990111;

    private const ENTERPRISE = 990112;

    private const ACCOUNT_ID = 990181;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scaffold();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    public function test_pro_and_enterprise_tenants_can_create_dsl_instance(): void
    {
        $gateway = app(StrategyGateway::class);

        foreach ([self::PRO, self::ENTERPRISE] as $tenantId) {
            $instance = $gateway->createInstance(
                $this->tenant($tenantId),
                'custom_dsl',
                $this->accountId($tenantId),
                'BTCUSDT',
                '1m',
                ['dsl_script' => $this->script()],
                '1000',
            );

            static::assertSame($tenantId, (int) $instance->tenant_id);
            static::assertSame('stopped', (string) $instance->status);
        }

        static::assertSame(
            2,
            (int) DB::table('strategy_instances')->whereIn('tenant_id', [self::PRO, self::ENTERPRISE])->count(),
        );
    }

    public function test_free_tenant_is_denied_and_nothing_is_persisted(): void
    {
        $gateway = app(StrategyGateway::class);
        $threw = false;

        try {
            $gateway->createInstance(
                $this->tenant(self::FREE),
                'custom_dsl',
                $this->accountId(self::FREE),
                'BTCUSDT',
                '1m',
                ['dsl_script' => $this->script()],
                '1000',
            );
        } catch (EntitlementException) {
            $threw = true;
        }

        static::assertTrue($threw, 'free 租户创建 custom_dsl 实例必须被拒');
        static::assertSame(
            0,
            (int) DB::table('strategy_instances')->where('tenant_id', self::FREE)->count(),
            '被拒请求不得落库',
        );
    }

    public function test_dsl_drafts_are_invisible_across_tenants(): void
    {
        TenantContext::initialize(self::PRO);
        app(StrategyGateway::class)->createInstance(
            $this->tenant(self::PRO),
            'custom_dsl',
            $this->accountId(self::PRO),
            'BTCUSDT',
            '1m',
            ['dsl_script' => $this->script()],
            '1000',
        );

        static::assertSame(1, StrategyInstance::query()->count(), 'pro 上下文可见自己的 DSL 草稿');

        TenantContext::initialize(self::FREE);
        static::assertSame(0, StrategyInstance::query()->count(), 'free 上下文不可见 pro 的 DSL 草稿');
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function tenant(int $tenantId): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($tenantId);

        return $tenant;
    }

    private function accountId(int $tenantId): int
    {
        return self::ACCOUNT_ID + ($tenantId - self::FREE);
    }

    private function script(): string
    {
        return implode("\n", [
            'version: 1',
            'name: plan-gate',
            'symbol: BTCUSDT',
            'interval: 1m',
            'direction: long',
            'trigger: on_bar',
            'filter:',
            '  - action: noop',
            '    when:',
            '      op: true',
            'entry:',
            '  - action: open_long',
            '    when:',
            '      op: gt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            '    qty_pct: "0.5"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: lt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            '    qty_pct: "1.0"',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);
    }

    private function scaffold(): void
    {
        $now = now();

        foreach ([[self::FREE, 'free'], [self::PRO, 'pro'], [self::ENTERPRISE, 'enterprise']] as [$id, $plan]) {
            DB::table('tenants')->insert([
                'id' => $id,
                'name' => "poc-dsl-gate-{$plan}",
                'slug' => "poc-dsl-gate-{$plan}",
                'exchange_dek' => 'dummy-dek',
                'status' => 'active',
                'plan_code' => $plan,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance-dsl-gate', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'custom_dsl', 'name' => '自定义 DSL', 'category' => 'spot',
            'risk_profile' => 'custom', 'default_params' => json_encode(['dsl_script' => null]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        static::assertGreaterThan(0, $strategyId);

        foreach ([self::FREE, self::PRO, self::ENTERPRISE] as $tenantId) {
            DB::table('exchange_accounts')->insert([
                'id' => $this->accountId($tenantId),
                'tenant_id' => $tenantId,
                'exchange_id' => $exchangeId,
                'label' => 'poc-dsl-gate',
                'market' => 'spot',
                'encrypted_api_key' => 'poc',
                'encrypted_api_secret' => 'poc',
                'permissions' => json_encode(['spot']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
