<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Backtest\Models\ParameterOptimizationTrial;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\OptimizationKlineQuery;
use Tests\TestCase;

/**
 * 参数寻优 API（P11）。
 *
 * 覆盖：创建入队（sync 下同步执行）→ 落库 completed；详情/列表/trials 只读；
 * 租户隔离 404；网格白名单校验 422。
 */
final class OptimizationApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 990091;

    private const TENANT_B = 990092;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [self::TENANT_A, 'poc-optimize-a'],
            [self::TENANT_B, 'poc-optimize-b'],
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

        $this->userA = User::factory()->create(['tenant_id' => self::TENANT_A, 'role' => 'tenant_admin']);
        $this->userB = User::factory()->create(['tenant_id' => self::TENANT_B, 'role' => 'tenant_admin']);

        $this->app->instance(HistoricalQuery::class, new OptimizationKlineQuery);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_store_dispatches_and_returns_completed_optimization(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/v1/parameter-optimizations', $this->payload());

        $response->assertStatus(202)
            ->assertJsonPath('data.strategy_code', 'spot_grid')
            ->assertJsonPath('data.mode', 'grid')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress_pct', 100);

        $id = (int) $response->json('data.id');
        $record = DB::table('parameter_optimizations')->where('id', $id)->first();

        self::assertNotNull($record);
        self::assertSame(self::TENANT_A, (int) $record->tenant_id);
        self::assertSame(2, ParameterOptimizationTrial::query()->where('optimization_id', $id)->count());
        self::assertNotNull($record->best_score);
        self::assertNotNull($record->best_params);
    }

    public function test_show_and_trials_return_persisted_report(): void
    {
        Sanctum::actingAs($this->userA);

        $id = (int) $this->postJson('/api/v1/parameter-optimizations', $this->payload())
            ->json('data.id');

        $this->getJson("/api/v1/parameter-optimizations/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.report.objective', 'total_return_pct')
            ->assertJsonStructure([
                'data' => [
                    'best_params',
                    'best_score',
                    'report' => ['mode', 'combination_count', 'trial_count', 'best', 'top'],
                ],
            ]);

        $this->getJson("/api/v1/parameter-optimizations/{$id}/trials")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.in_sample', true)
            ->assertJsonPath('data.0.status', 'ok');
    }

    public function test_index_lists_tenant_optimizations_only(): void
    {
        Sanctum::actingAs($this->userA);
        $id = (int) $this->postJson('/api/v1/parameter-optimizations', $this->payload())
            ->json('data.id');

        $this->getJson('/api/v1/parameter-optimizations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        Sanctum::actingAs($this->userB);

        $this->getJson('/api/v1/parameter-optimizations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_cross_tenant_optimization_is_not_found(): void
    {
        Sanctum::actingAs($this->userA);
        $id = (int) $this->postJson('/api/v1/parameter-optimizations', $this->payload())
            ->json('data.id');

        Sanctum::actingAs($this->userB);

        $this->getJson("/api/v1/parameter-optimizations/{$id}")->assertNotFound();
        $this->getJson("/api/v1/parameter-optimizations/{$id}/trials")->assertNotFound();
    }

    public function test_grid_parameter_outside_whitelist_is_rejected(): void
    {
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/v1/parameter-optimizations', $this->payload([
            'grid' => ['not_a_param' => [1, 2]],
        ]))->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'strategy_code' => 'spot_grid',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'from_utc' => '2023-11-14 00:00:00',
            'to_utc' => '2023-11-14 00:30:00',
            'grid' => ['grid_count' => [5, 10]],
            'base_params' => [
                'lower_price' => '90',
                'upper_price' => '110',
                'investment_amount' => '1000',
            ],
            'objective' => 'total_return_pct',
            'initial_capital' => '1000',
        ], $overrides);
    }
}
