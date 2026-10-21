<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Backtest\Contracts\OptimizationStatus;
use App\Domain\Backtest\Models\ParameterOptimization;
use App\Domain\Tenancy\TenantContext;
use App\Models\StrategyInstance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 寻优结果应用到策略实例 API（回测 → 寻优 → 实盘闭环）。
 *
 * 覆盖：合并 best_params + base_params 并保留实例其他参数、applied 留痕；
 * 任务未完成/无最优参数/实例不匹配 422；运行中 409；跨租户 404。
 */
final class OptimizationApplyApiTest extends TestCase
{
    private const TENANT_A = 990101;

    private const TENANT_B = 990102;

    private const EXCHANGE_ID = 990201;

    private User $userA;

    private User $userB;

    private int $strategyId;

    private int $accountA;

    private int $accountB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [self::TENANT_A, 'poc-apply-a'],
            [self::TENANT_B, 'poc-apply-b'],
        ] as [$tenantId, $slug]) {
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'name' => $slug,
                'slug' => $slug,
                'exchange_dek' => 'test-dek',
                'status' => 'active',
                'plan_code' => 'pro',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('exchanges')->insert([
            'id' => self::EXCHANGE_ID,
            'code' => 'binance-apply-990201',
            'name' => 'Binance',
            'base_url' => 'https://example.invalid',
            'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid',
            'name' => '现货网格',
            'category' => 'spot',
            'risk_profile' => 'conservative',
            'default_params' => json_encode([]),
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accountA = $this->createAccount(self::TENANT_A, 'apply-account-a');
        $this->accountB = $this->createAccount(self::TENANT_B, 'apply-account-b');

        $this->userA = User::factory()->create(['tenant_id' => self::TENANT_A, 'role' => 'tenant_admin']);
        $this->userB = User::factory()->create(['tenant_id' => self::TENANT_B, 'role' => 'tenant_admin']);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_apply_merges_best_and_base_params_into_stopped_instance(): void
    {
        Sanctum::actingAs($this->userA);

        $optimization = $this->createOptimization();
        $instance = $this->createInstance();

        $this->postJson(
            "/api/v1/parameter-optimizations/{$optimization->id}/apply",
            ['instance_id' => $instance->id],
        )
            ->assertOk()
            ->assertJsonPath('data.id', $instance->id)
            ->assertJsonPath('data.params.grid_count', 7)
            ->assertJsonPath('data.params.lower_price', '90')
            ->assertJsonPath('data.params.upper_price', '110')
            ->assertJsonPath('data.params.investment_amount', '1000')
            // 实例原有、未参与寻优的参数保留
            ->assertJsonPath('data.params.qty_per_grid', '0.1');

        $instance->refresh();
        self::assertSame(7, $instance->params['grid_count']);
        self::assertSame('0.1', $instance->params['qty_per_grid']);

        $optimization->refresh();
        self::assertSame($instance->id, $optimization->applied_instance_id);
        self::assertNotNull($optimization->applied_at);
    }

    public function test_apply_rejects_unfinished_optimization(): void
    {
        Sanctum::actingAs($this->userA);

        $optimization = $this->createOptimization([
            'status' => OptimizationStatus::Pending,
            'best_params' => null,
            'best_score' => null,
        ]);
        $instance = $this->createInstance();

        $this->postJson(
            "/api/v1/parameter-optimizations/{$optimization->id}/apply",
            ['instance_id' => $instance->id],
        )->assertStatus(422);
    }

    public function test_apply_rejects_completed_optimization_without_best_params(): void
    {
        Sanctum::actingAs($this->userA);

        $optimization = $this->createOptimization(['best_params' => null, 'best_score' => null]);
        $instance = $this->createInstance();

        $this->postJson(
            "/api/v1/parameter-optimizations/{$optimization->id}/apply",
            ['instance_id' => $instance->id],
        )->assertStatus(422);
    }

    public function test_apply_rejects_instance_mismatch(): void
    {
        Sanctum::actingAs($this->userA);

        $optimization = $this->createOptimization();
        $instance = $this->createInstance(['interval' => '1m']);

        $this->postJson(
            "/api/v1/parameter-optimizations/{$optimization->id}/apply",
            ['instance_id' => $instance->id],
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'K 线周期不匹配：寻优任务为 5m，实例为 1m。');
    }

    public function test_apply_rejects_live_instance(): void
    {
        Sanctum::actingAs($this->userA);

        $optimization = $this->createOptimization();
        $instance = $this->createInstance(['status' => 'running']);

        $this->postJson(
            "/api/v1/parameter-optimizations/{$optimization->id}/apply",
            ['instance_id' => $instance->id],
        )->assertStatus(409);
    }

    public function test_cross_tenant_instance_or_optimization_is_not_found(): void
    {
        $optimization = $this->createOptimization();
        $instanceB = $this->createInstance([
            'tenant_id' => self::TENANT_B,
            'exchange_account_id' => $this->accountB,
        ]);

        Sanctum::actingAs($this->userA);

        // 他人租户实例 → 404
        $this->postJson(
            "/api/v1/parameter-optimizations/{$optimization->id}/apply",
            ['instance_id' => $instanceB->id],
        )->assertNotFound();

        Sanctum::actingAs($this->userB);

        // 他人租户任务 → 404
        $this->postJson(
            "/api/v1/parameter-optimizations/{$optimization->id}/apply",
            ['instance_id' => $instanceB->id],
        )->assertNotFound();
    }

    private function createAccount(int $tenantId, string $label): int
    {
        return (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => $tenantId,
            'exchange_id' => self::EXCHANGE_ID,
            'label' => $label,
            'market' => 'spot',
            'encrypted_api_key' => 'seed',
            'encrypted_api_secret' => 'seed',
            'permissions' => json_encode(['spot']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOptimization(array $overrides = []): ParameterOptimization
    {
        return ParameterOptimization::query()->create(array_merge([
            'tenant_id' => self::TENANT_A,
            'strategy_code' => 'spot_grid',
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'from_utc' => '2023-11-14 00:00:00',
            'to_utc' => '2023-11-14 01:00:00',
            'mode' => 'grid',
            'folds' => 3,
            'objective' => 'total_return_pct',
            'grid' => ['grid_count' => [5, 10]],
            'base_params' => [
                'lower_price' => '90',
                'upper_price' => '110',
                'investment_amount' => '1000',
            ],
            'top_n' => 5,
            'assumptions' => ['fee_rate' => '0.001', 'slippage_bps' => 5, 'initial_capital' => '1000'],
            'status' => OptimizationStatus::Completed,
            'progress_pct' => 100,
            'best_params' => ['grid_count' => 7],
            'best_score' => '1.50000000',
            'report' => [],
            'finished_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createInstance(array $overrides = []): StrategyInstance
    {
        return StrategyInstance::query()->create(array_merge([
            'tenant_id' => self::TENANT_A,
            'strategy_id' => $this->strategyId,
            'exchange_account_id' => $this->accountA,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => ['grid_count' => 3, 'qty_per_grid' => '0.1'],
            'allocated_capital' => '1000',
            'status' => 'stopped',
        ], $overrides));
    }
}
