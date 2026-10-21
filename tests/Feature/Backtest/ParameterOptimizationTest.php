<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Backtest\Contracts\OptimizationStatus;
use App\Domain\Backtest\Models\ParameterOptimization;
use App\Domain\Backtest\Models\ParameterOptimizationTrial;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\OptimizationKlineQuery;
use Tests\TestCase;

/**
 * backtest:optimize 端到端（P11）。
 *
 * 覆盖：grid 落库、walk-forward 分折、失败组合不中断、dry-run 零写入、租户隔离。
 */
final class ParameterOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'bt-optimize',
            'slug' => 'bt-optimize',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_grid_mode_persists_optimization_trials_and_best_params(): void
    {
        $this->bindKlines();

        $this->artisan('backtest:optimize', $this->baseArguments())
            ->assertExitCode(0);

        TenantContext::initialize($this->tenant->id);
        $optimization = ParameterOptimization::query()->firstOrFail();

        self::assertSame(OptimizationStatus::Completed, $optimization->status);
        self::assertSame(100, $optimization->progress_pct);
        self::assertSame('spot_grid', $optimization->strategy_code);
        self::assertNotNull($optimization->best_params);
        self::assertNotNull($optimization->best_score);
        self::assertArrayHasKey('grid_count', $optimization->best_params);
        self::assertSame(2, ParameterOptimizationTrial::query()->count());

        $trial = ParameterOptimizationTrial::query()->orderBy('id')->firstOrFail();
        self::assertSame($optimization->id, $trial->optimization_id);
        self::assertSame($this->tenant->id, $trial->tenant_id);
        self::assertNull($trial->fold_index);
        self::assertTrue($trial->in_sample);
        self::assertSame('ok', $trial->status);
        self::assertNotNull($trial->metrics);
        self::assertArrayHasKey('total_return_pct', $trial->metrics);

        // 报告中的最优参数与 best_params 一致
        self::assertSame($optimization->best_params, $optimization->report['best']['params']);

        // 租户隔离：其他租户上下文不可见
        TenantContext::clear();
        TenantContext::initialize(999999);
        self::assertNull(ParameterOptimization::find($optimization->id));
    }

    public function test_walk_forward_mode_records_fold_trials(): void
    {
        $this->bindKlines();

        $this->artisan('backtest:optimize', $this->baseArguments([
            '--mode' => 'walk_forward',
            '--folds' => 2,
        ]))->assertExitCode(0);

        TenantContext::initialize($this->tenant->id);
        $optimization = ParameterOptimization::query()->firstOrFail();

        self::assertSame(OptimizationStatus::Completed, $optimization->status);
        // 2 组合 × 2 折 × (训练 + 样本外)
        self::assertSame(8, ParameterOptimizationTrial::query()->count());
        self::assertCount(2, $optimization->report['fold_details']);
        self::assertArrayHasKey('out_of_sample_score', $optimization->report['fold_details'][0]);
    }

    public function test_failed_trial_is_recorded_without_aborting(): void
    {
        $this->bindKlines(failFirstQuery: true);

        $this->artisan('backtest:optimize', $this->baseArguments())
            ->assertExitCode(0);

        TenantContext::initialize($this->tenant->id);
        $optimization = ParameterOptimization::query()->firstOrFail();

        self::assertSame(OptimizationStatus::Completed, $optimization->status);
        self::assertSame(2, ParameterOptimizationTrial::query()->count());
        self::assertSame(1, ParameterOptimizationTrial::query()->where('status', 'failed')->count());
        self::assertSame(1, $optimization->report['failed_trial_count']);
        self::assertNotNull($optimization->best_score);
    }

    public function test_dry_run_does_not_write_database(): void
    {
        $this->bindKlines();

        $this->artisan('backtest:optimize', $this->baseArguments(['--dry-run' => true]))
            ->assertExitCode(0);

        self::assertSame(0, DB::table('parameter_optimizations')->count());
        self::assertSame(0, DB::table('parameter_optimization_trials')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseArguments(array $overrides = []): array
    {
        return array_merge([
            'strategy' => 'spot_grid',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            '--from' => '2023-11-14 00:00:00',
            '--to' => '2023-11-14 00:30:00',
            '--tenant' => $this->tenant->id,
            '--grid' => json_encode(['grid_count' => [5, 10]]),
            '--params' => json_encode(['lower_price' => '90', 'upper_price' => '110', 'investment_amount' => '1000']),
            '--objective' => 'total_return_pct',
            '--capital' => '1000',
        ], $overrides);
    }

    private function bindKlines(bool $failFirstQuery = false): void
    {
        $this->app->instance(HistoricalQuery::class, new OptimizationKlineQuery($failFirstQuery));
    }
}
