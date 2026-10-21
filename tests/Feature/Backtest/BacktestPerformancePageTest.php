<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Domain\Backtest\Contracts\BacktestRunStatus;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Models\BacktestReport;
use App\Domain\Backtest\Models\BacktestRun;
use App\Domain\Backtest\Models\BacktestSimulatedTrade;
use App\Domain\Backtest\Services\BacktestRunService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\BacktestPerformancePage;
use App\Models\StrategyInstance;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * US5 回测绩效页 Feature 测试（T041 / SC-006）。
 *
 *  - 页面渲染 + 未登录跳转
 *  - 双租户列表零串扰（各自只见本租户任务/报告）
 *  - 详情四区齐全：净值曲线 / 指标卡 / 假设区 / 交易明细
 *  - 跨租户访问详情不泄露
 *  - 发起侧服务校验：区间上限 / 套餐策略白名单（表单服务端校验口径）
 */
final class BacktestPerformancePageTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/backtest-performance';

    private const BAR_TIME_MS = 1_700_000_000_000;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $userA;

    private User $userB;

    private BacktestRun $runA;

    private BacktestRun $runB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'bt-page-a',
            'slug' => 'bt-page-a',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'bt-page-b',
            'slug' => 'bt-page-b',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
        ]);

        $this->userA = User::factory()->create(['tenant_id' => $this->tenantA->id, 'role' => 'tenant_admin']);
        $this->userB = User::factory()->create(['tenant_id' => $this->tenantB->id, 'role' => 'tenant_admin']);

        $this->runA = $this->createCompletedRun($this->tenantA->id, 'BTCUSDT', '12.50000000');
        $this->runB = $this->createCompletedRun($this->tenantB->id, 'ETHUSDT', '7.25000000');

        TenantContext::clear();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 渲染与鉴权
    // ------------------------------------------------------------------

    public function test_page_renders_for_tenant_user(): void
    {
        $response = $this->actingAs($this->userA)->get(self::URL);

        $response->assertOk();
        $response->assertSee('回测任务');
        $response->assertSee('发起回测');
        $response->assertSee('#'.$this->runA->id);
        $response->assertSee('12.50000000%');
    }

    public function test_page_requires_authentication(): void
    {
        $this->get(self::URL)->assertRedirect();
    }

    // ------------------------------------------------------------------
    // 租户隔离
    // ------------------------------------------------------------------

    public function test_list_shows_only_own_tenant_runs(): void
    {
        $responseA = $this->actingAs($this->userA)->get(self::URL);
        $responseA->assertOk();
        $responseA->assertSee('BTCUSDT');
        $responseA->assertDontSee('ETHUSDT');

        $responseB = $this->actingAs($this->userB)->get(self::URL);
        $responseB->assertOk();
        $responseB->assertSee('ETHUSDT');
        $responseB->assertDontSee('BTCUSDT');
    }

    public function test_cross_tenant_detail_is_not_visible(): void
    {
        $response = $this->actingAs($this->userA)->get(self::URL.'?run='.$this->runB->id);

        $response->assertOk();
        // 详情区不渲染（selectedRun 为空），也不出现对方数据
        $response->assertDontSee('ETHUSDT');
        $response->assertDontSee('交易明细');
    }

    // ------------------------------------------------------------------
    // 详情四区
    // ------------------------------------------------------------------

    public function test_detail_page_renders_curve_metrics_assumptions_and_trades(): void
    {
        $response = $this->actingAs($this->userA)->get(self::URL.'?run='.$this->runA->id);

        $response->assertOk();
        // 4 区标题
        $response->assertSee('净值曲线');
        $response->assertSee('绩效指标');
        $response->assertSee('撮合假设');
        $response->assertSee('交易明细');
        // 假设区数值（费率/滑点/初始资金/口径）
        $response->assertSee('0.001');
        $response->assertSee('5 bps');
        $response->assertSee('成交口径');
        // 交易明细行（decimal cast：price/qty 12 位、fee 8 位）
        $response->assertSee('101.000000000000');
        $response->assertSee('0.25250000');
        // 已完成回测可落地实盘
        $response->assertSee('落地实盘');
    }

    // ------------------------------------------------------------------
    // 落地实盘（回测 → 策略实例）
    // ------------------------------------------------------------------

    public function test_create_instance_from_completed_run(): void
    {
        $strategyId = $this->createStrategy();
        $accountId = $this->createAccount($this->tenantA->id);

        TenantContext::initialize($this->tenantA->id);
        $this->actingAs($this->userA);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(BacktestPerformancePage::class)
            ->set('selectedRunId', $this->runA->id)
            ->callAction('createInstance', data: [
                'exchange_account_id' => $accountId,
                'allocated_capital' => '1500',
                'leverage' => null,
            ])
            ->assertHasNoActionErrors();

        $instance = StrategyInstance::query()->firstOrFail();
        self::assertSame($this->tenantA->id, $instance->tenant_id);
        self::assertSame($strategyId, $instance->strategy_id);
        self::assertSame($accountId, $instance->exchange_account_id);
        self::assertSame('BTCUSDT', $instance->symbol);
        self::assertSame('1m', $instance->interval);
        self::assertSame('stopped', $instance->status);
        self::assertSame(0, bccomp((string) $instance->allocated_capital, '1500', 8));
        self::assertSame([
            'lower_price' => '90',
            'upper_price' => '110',
            'grid_count' => 5,
            'investment_amount' => '1000',
        ], $instance->params);
    }

    public function test_create_instance_rejects_duplicate_strategy_symbol(): void
    {
        $strategyId = $this->createStrategy();
        $accountId = $this->createAccount($this->tenantA->id);

        StrategyInstance::query()->create([
            'tenant_id' => $this->tenantA->id,
            'strategy_id' => $strategyId,
            'exchange_account_id' => $accountId,
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'params' => [],
            'allocated_capital' => '1000',
            'status' => 'stopped',
        ]);

        TenantContext::initialize($this->tenantA->id);
        $this->actingAs($this->userA);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(BacktestPerformancePage::class)
            ->set('selectedRunId', $this->runA->id)
            ->callAction('createInstance', data: [
                'exchange_account_id' => $accountId,
                'allocated_capital' => '1500',
                'leverage' => null,
            ])
            ->assertHasNoActionErrors();

        self::assertSame(1, StrategyInstance::query()->count());
    }

    // ------------------------------------------------------------------
    // 发起表单服务端校验（区间上限 / 套餐策略白名单）
    // ------------------------------------------------------------------

    public function test_create_rejects_range_over_config_limit(): void
    {
        TenantContext::initialize($this->tenantA->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('超出上限');

        app(BacktestRunService::class)->create(
            $this->tenantA->id,
            'spot_grid',
            ['lower_price' => '90', 'upper_price' => '110', 'grid_count' => 5, 'investment_amount' => '1000'],
            'BTCUSDT',
            '1m',
            new \DateTimeImmutable('2023-01-01 00:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2024-06-01 00:00:00', new \DateTimeZone('UTC')),
            MatchingAssumptions::fromConfig(),
        );
    }

    public function test_create_rejects_strategy_not_allowed_by_plan(): void
    {
        TenantContext::initialize($this->tenantB->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('当前套餐不支持策略');

        // free 套餐仅 spot_grid/spot_dca
        app(BacktestRunService::class)->create(
            $this->tenantB->id,
            'futures_grid',
            ['lower_price' => '90', 'upper_price' => '110', 'grid_count' => 5, 'leverage' => 5, 'margin' => '1000'],
            'BTCUSDT',
            '1m',
            new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2023-11-14 23:00:00', new \DateTimeZone('UTC')),
            MatchingAssumptions::fromConfig(),
        );
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

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

    private function createAccount(int $tenantId): int
    {
        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance-bt-page-990091',
            'name' => 'Binance',
            'base_url' => 'https://example.invalid',
            'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => $tenantId,
            'exchange_id' => $exchangeId,
            'label' => 'bt-page-account',
            'market' => 'spot',
            'encrypted_api_key' => 'seed',
            'encrypted_api_secret' => 'seed',
            'permissions' => json_encode(['spot']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCompletedRun(int $tenantId, string $symbol, string $returnPct): BacktestRun
    {
        TenantContext::initialize($tenantId);

        $run = BacktestRun::create([
            'tenant_id' => $tenantId,
            'strategy_code' => 'spot_grid',
            'params_snapshot' => [
                'lower_price' => '90',
                'upper_price' => '110',
                'grid_count' => 5,
                'investment_amount' => '1000',
            ],
            'assumptions' => MatchingAssumptions::fromConfig(
                capital: '1000',
                feeRate: '0.001',
                slippageBps: 5,
                strategyCode: 'spot_grid',
            )->toArray(),
            'symbol' => $symbol,
            'interval' => '1m',
            'from_utc' => new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            'to_utc' => new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            'status' => BacktestRunStatus::Completed,
            'progress_pct' => 100,
            'started_at' => new \DateTimeImmutable('2023-11-14 22:11:00', new \DateTimeZone('UTC')),
            'finished_at' => new \DateTimeImmutable('2023-11-14 22:12:00', new \DateTimeZone('UTC')),
        ]);

        BacktestReport::create([
            'backtest_run_id' => $run->id,
            'tenant_id' => $tenantId,
            'initial_capital' => '1000',
            'final_equity' => bcmul(bcadd('1000', bcdiv($returnPct, '100', 12), 12), '1', 8),
            'total_return_pct' => $returnPct,
            'annualized_return_pct' => bcmul($returnPct, '36.5', 8),
            'sharpe_ratio' => '1.23456789',
            'max_drawdown_pct' => '2.50000000',
            'win_rate_pct' => '50.00000000',
            'profit_factor' => '2.00000000',
            'trade_count' => 2,
            'total_fee' => '0.25250000',
            'bar_count' => 10,
            'data_coverage_pct' => '90.00',
            'equity_curve' => [
                [self::BAR_TIME_MS, '1000.00000000'],
                [self::BAR_TIME_MS + 60_000, $returnPct === '12.50000000' ? '1125.00000000' : '1072.50000000'],
            ],
        ]);

        BacktestSimulatedTrade::create([
            'backtest_run_id' => $run->id,
            'tenant_id' => $tenantId,
            'seq' => 1,
            'bar_open_time' => self::BAR_TIME_MS,
            'side' => 'buy',
            'qty' => '2.500000000000',
            'price' => '101.000000000000',
            'fee' => '0.25250000',
            'realized_pnl' => '0.00000000',
        ]);

        return $run;
    }
}
