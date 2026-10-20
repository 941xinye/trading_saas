<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use App\Domain\Backtest\Contracts\BacktestRunStatus;
use App\Domain\Backtest\Models\BacktestReport;
use App\Domain\Backtest\Models\BacktestRun;
use App\Domain\Backtest\Models\BacktestSimulatedTrade;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 回测三 Model 契约测试（spec 008 tasks T012 / data-model.md）。
 *
 * 覆盖：关联（hasOne/hasMany/belongsTo）、cast（enum/decimal string/array）、
 * TenantScope 隔离、simulated_trades 无 updated_at（append-only）、状态机终态语义。
 *
 * sqlite :memory: 跑通即顺带验证 migration 双连接兼容（T011）。
 */
class BacktestModelTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_ID = 990800;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-backtest-model-990800',
            'slug' => 'poc-backtest-model-990800',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        TenantContext::initialize(self::TENANT_ID);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    private function makeRun(string $status = 'pending'): BacktestRun
    {
        return BacktestRun::create([
            'tenant_id' => self::TENANT_ID,
            'strategy_instance_id' => null,
            'strategy_code' => 'spot_grid',
            'params_snapshot' => ['grid_count' => 10, 'lower' => '90000', 'upper' => '110000'],
            'assumptions' => ['fee_rate' => '0.001', 'slippage_bps' => 5, 'initial_capital' => '1000'],
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'from_utc' => '2026-07-01 00:00:00',
            'to_utc' => '2026-10-01 00:00:00',
            'status' => $status,
        ]);
    }

    public function test_run_status_casts_to_backed_enum_and_terminal_semantics(): void
    {
        $run = $this->makeRun('pending');
        $this->assertSame(BacktestRunStatus::Pending, $run->status);
        $this->assertFalse($run->status->isTerminal());

        $run->update(['status' => 'completed']);
        $run->refresh();
        $this->assertSame(BacktestRunStatus::Completed, $run->status);
        $this->assertTrue($run->status->isTerminal());

        $this->assertTrue(BacktestRunStatus::Failed->isTerminal());
        $this->assertFalse(BacktestRunStatus::Running->isTerminal());
    }

    public function test_run_params_and_assumptions_cast_to_array(): void
    {
        $run = $this->makeRun();
        $run->refresh();
        $this->assertSame(10, $run->params_snapshot['grid_count']);
        $this->assertSame('0.001', $run->assumptions['fee_rate']);
    }

    public function test_run_has_one_report_and_many_trades(): void
    {
        $run = $this->makeRun('completed');

        $report = BacktestReport::create([
            'backtest_run_id' => $run->id,
            'tenant_id' => self::TENANT_ID,
            'initial_capital' => '1000.00000000',
            'final_equity' => '1100.50000000',
            'total_return_pct' => '10.05000000',
            'annualized_return_pct' => '40.20000000',
            'sharpe_ratio' => '1.23456789',
            'max_drawdown_pct' => '5.00000000',
            'win_rate_pct' => '66.66666667',
            'profit_factor' => '2.50000000',
            'trade_count' => 3,
            'total_fee' => '1.50000000',
            'bar_count' => 100,
            'data_coverage_pct' => '100.00',
            'equity_curve' => [[1720000000000, '1000.00000000'], [1720000300000, '1100.50000000']],
        ]);

        BacktestSimulatedTrade::create([
            'backtest_run_id' => $run->id,
            'tenant_id' => self::TENANT_ID,
            'seq' => 1,
            'bar_open_time' => 1720000000000,
            'side' => 'buy',
            'qty' => '0.010000000000',
            'price' => '100000.500000000000',
            'fee' => '0.50000000',
            'realized_pnl' => '0.00000000',
        ]);
        BacktestSimulatedTrade::create([
            'backtest_run_id' => $run->id,
            'tenant_id' => self::TENANT_ID,
            'seq' => 2,
            'bar_open_time' => 1720000300000,
            'side' => 'sell',
            'qty' => '0.010000000000',
            'price' => '100500.250000000000',
            'fee' => '0.50250000',
            'realized_pnl' => '4.49750000',
        ]);

        $run->refresh();

        $this->assertInstanceOf(BacktestReport::class, $run->report);
        $this->assertSame(2, $run->simulatedTrades()->count());
        $this->assertSame($run->id, $report->run->id);

        // decimal:8 cast 读出 string（bcmath 直喂，浮点禁入）
        $report->refresh();
        $this->assertIsString($report->initial_capital);
        $this->assertSame(0, bccomp($report->initial_capital, '1000', 8));
        $this->assertSame(0, bccomp($report->win_rate_pct, '66.66666667', 8));
        // equity_curve cast array 且字符串保值
        $this->assertSame('1100.50000000', $report->equity_curve[1][1]);
    }

    public function test_simulated_trade_has_no_updated_at_and_decimal_string_casts(): void
    {
        $run = $this->makeRun();

        $trade = BacktestSimulatedTrade::create([
            'backtest_run_id' => $run->id,
            'tenant_id' => self::TENANT_ID,
            'seq' => 1,
            'bar_open_time' => 1720000000000,
            'side' => 'buy',
            'qty' => '0.010000000000',
            'price' => '100000.500000000000',
            'fee' => '0.50000000',
            'realized_pnl' => '0.00000000',
        ]);

        // append-only：表无 updated_at 列
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('backtest_simulated_trades', 'updated_at')
        );
        $this->assertNotNull($trade->created_at);

        $trade->refresh();
        $this->assertIsString($trade->qty);
        $this->assertSame(0, bccomp($trade->qty, '0.01', 12));
        $this->assertSame(0, bccomp($trade->price, '100000.5', 12));
    }

    public function test_nullable_metric_columns_accept_null(): void
    {
        $run = $this->makeRun('completed');

        $report = BacktestReport::create([
            'backtest_run_id' => $run->id,
            'tenant_id' => self::TENANT_ID,
            'initial_capital' => '1000.00000000',
            'final_equity' => '1000.00000000',
            'total_return_pct' => '0.00000000',
            'annualized_return_pct' => '0.00000000',
            'sharpe_ratio' => null,   // 零方差
            'max_drawdown_pct' => '0.00000000',
            'win_rate_pct' => null,   // 零平仓
            'profit_factor' => null,  // 零亏损
            'trade_count' => 0,
            'total_fee' => '0.00000000',
            'bar_count' => 100,
            'data_coverage_pct' => '100.00',
            'equity_curve' => [[1720000000000, '1000.00000000']],
        ]);

        $report->refresh();
        $this->assertNull($report->sharpe_ratio);
        $this->assertNull($report->win_rate_pct);
        $this->assertNull($report->profit_factor);
    }

    public function test_tenant_scope_isolation(): void
    {
        $run = $this->makeRun();

        // 另一租户上下文下不可见
        TenantContext::clear();
        TenantContext::initialize(990801);
        $this->assertNull(BacktestRun::find($run->id));

        // 无租户上下文（平台视角）可见全部
        TenantContext::clear();
        $this->assertNotNull(BacktestRun::find($run->id));
    }
}
