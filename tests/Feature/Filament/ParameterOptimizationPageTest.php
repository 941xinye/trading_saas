<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Backtest\Contracts\OptimizationStatus;
use App\Domain\Backtest\Models\ParameterOptimization;
use App\Domain\Backtest\Models\ParameterOptimizationTrial;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\ParameterOptimizationPage;
use App\Models\StrategyInstance;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\OptimizationKlineQuery;
use Tests\TestCase;

/**
 * Admin 面板参数寻优页测试（P11，default 组）。
 *
 * 覆盖：页头 Action 发起（落库 + 入队执行）+ 列表/详情渲染。
 */
final class ParameterOptimizationPageTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_ID = 990093;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-optimize-page',
            'slug' => 'poc-optimize-page',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => self::TENANT_ID,
            'role' => 'tenant_admin',
        ]);

        $this->actingAs($this->user);
        TenantContext::initialize(self::TENANT_ID);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->app->instance(HistoricalQuery::class, new OptimizationKlineQuery);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_header_action_creates_and_runs_optimization(): void
    {
        Livewire::test(ParameterOptimizationPage::class)
            ->callAction('optimize', data: [
                'strategy_code' => 'spot_grid',
                'symbol' => 'BTCUSDT',
                'interval' => '1m',
                'from_utc' => '2023-11-14 00:00:00',
                'to_utc' => '2023-11-14 00:30:00',
                'mode' => 'grid',
                'objective' => 'total_return_pct',
                'top_n' => '5',
                'grid' => '{"grid_count":[5,10]}',
                'base_params' => '{"lower_price":"90","upper_price":"110","investment_amount":"1000"}',
                'initial_capital' => '1000',
                'fee_rate' => '0.001',
                'slippage_bps' => '5',
            ])
            ->assertHasNoActionErrors();

        $record = ParameterOptimization::query()->firstOrFail();

        self::assertSame(self::TENANT_ID, (int) $record->tenant_id);
        self::assertSame(OptimizationStatus::Completed, $record->status);
        self::assertSame(['grid_count' => [5, 10]], $record->grid);
        self::assertSame('90', $record->base_params['lower_price'] ?? null);
        self::assertSame(2, ParameterOptimizationTrial::query()->where('optimization_id', $record->id)->count());
        self::assertNotNull($record->best_score);
    }

    public function test_page_renders_completed_optimization_detail(): void
    {
        $record = ParameterOptimization::query()->create([
            'tenant_id' => self::TENANT_ID,
            'strategy_code' => 'spot_grid',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'from_utc' => '2023-11-14 00:00:00',
            'to_utc' => '2023-11-14 00:30:00',
            'mode' => 'grid',
            'folds' => 3,
            'objective' => 'total_return_pct',
            'grid' => ['grid_count' => [5]],
            'base_params' => ['lower_price' => '90'],
            'top_n' => 5,
            'assumptions' => ['fee_rate' => '0.001', 'slippage_bps' => 5, 'initial_capital' => '1000'],
            'status' => OptimizationStatus::Completed,
            'progress_pct' => 100,
            'best_params' => ['grid_count' => 5],
            'best_score' => '1.01820060',
            'report' => [
                'mode' => 'grid',
                'objective' => 'total_return_pct',
                'combination_count' => 1,
                'trial_count' => 1,
                'failed_trial_count' => 0,
                'best' => ['params' => ['grid_count' => 5], 'score' => '1.01820060', 'metrics' => []],
                'top' => [['params' => ['grid_count' => 5], 'score' => '1.01820060', 'metrics' => []]],
            ],
            'finished_at' => now(),
        ]);

        ParameterOptimizationTrial::query()->create([
            'optimization_id' => $record->id,
            'tenant_id' => self::TENANT_ID,
            'fold_index' => null,
            'params' => ['grid_count' => 5],
            'in_sample' => true,
            'status' => 'ok',
            'score' => '1.01820060',
        ]);

        Livewire::test(ParameterOptimizationPage::class)
            ->assertOk()
            ->assertSee('1.01820060')
            ->set('selectedOptimizationId', $record->id)
            ->assertSee('最优参数')
            ->assertSee('Top 1 参数组')
            ->assertSee('逐次试跑');
    }

    public function test_apply_action_writes_optimized_params_to_instance(): void
    {
        $strategyId = $this->createStrategy();
        $accountId = $this->createAccount();

        $instance = StrategyInstance::query()->create([
            'tenant_id' => self::TENANT_ID,
            'strategy_id' => $strategyId,
            'exchange_account_id' => $accountId,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => ['grid_count' => 3, 'qty_per_grid' => '0.1'],
            'allocated_capital' => '1000',
            'status' => 'stopped',
        ]);

        $optimization = ParameterOptimization::query()->create([
            'tenant_id' => self::TENANT_ID,
            'strategy_code' => 'spot_grid',
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'from_utc' => '2023-11-14 00:00:00',
            'to_utc' => '2023-11-14 01:00:00',
            'mode' => 'grid',
            'folds' => 3,
            'objective' => 'total_return_pct',
            'grid' => ['grid_count' => [5, 10]],
            'base_params' => ['lower_price' => '90', 'upper_price' => '110', 'investment_amount' => '1000'],
            'top_n' => 5,
            'assumptions' => ['fee_rate' => '0.001', 'slippage_bps' => 5, 'initial_capital' => '1000'],
            'status' => OptimizationStatus::Completed,
            'progress_pct' => 100,
            'best_params' => ['grid_count' => 7],
            'best_score' => '1.50000000',
            'report' => [],
            'finished_at' => now(),
        ]);

        Livewire::test(ParameterOptimizationPage::class)
            ->set('selectedOptimizationId', $optimization->id)
            ->callAction('apply', data: ['instance_id' => $instance->id])
            ->assertHasNoActionErrors()
            ->assertSee('已应用到实例');

        $instance->refresh();
        self::assertSame(7, $instance->params['grid_count']);
        self::assertSame('90', $instance->params['lower_price']);
        self::assertSame('0.1', $instance->params['qty_per_grid']);

        $optimization->refresh();
        self::assertSame($instance->id, $optimization->applied_instance_id);
        self::assertNotNull($optimization->applied_at);
    }

    private function createStrategy(): int
    {
        return (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid',
            'name' => '现货网格',
            'category' => 'spot',
            'risk_profile' => 'conservative',
            'default_params' => json_encode([]),
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAccount(): int
    {
        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance-page-990093',
            'name' => 'Binance',
            'base_url' => 'https://example.invalid',
            'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => $exchangeId,
            'label' => 'page-apply-account',
            'market' => 'spot',
            'encrypted_api_key' => 'seed',
            'encrypted_api_secret' => 'seed',
            'permissions' => json_encode(['spot']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
