<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Domain\Backtest\Contracts\BacktestRunStatus;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Models\BacktestReport;
use App\Domain\Backtest\Models\BacktestRun;
use App\Domain\Backtest\Models\BacktestSimulatedTrade;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * US5 报告二次查看零重算（T042 / SC-003 / FR-008）。
 *
 * 已完成报告再次打开时只读 reports/trades 表：页面可复现指标与曲线，
 * 且不产生任何 backtest_* 写查询（报告持久化，不重跑回测）。
 */
final class BacktestReportReloadTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/backtest-performance';

    private const BAR_TIME_MS = 1_700_000_000_000;

    private Tenant $tenant;

    private User $user;

    private BacktestRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'bt-reload',
            'slug' => 'bt-reload',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'tenant_admin',
        ]);

        TenantContext::initialize($this->tenant->id);
        $this->run = $this->createCompletedRun();
        TenantContext::clear();
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_second_view_reads_persisted_report_without_writes(): void
    {
        $url = self::URL.'?run='.$this->run->id;

        // 首次打开：正常渲染
        $this->actingAs($this->user)->get($url)
            ->assertOk()
            ->assertSee('净值曲线')
            ->assertSee('12.50000000%')
            ->assertSee('101.000000000000');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $second = $this->get($url)->assertOk();
        $second->assertSee('12.50000000%');
        $second->assertSee('0.25250000');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // 命中报告/交易表（证明确实读取持久化数据）
        $backtestReads = array_filter(
            $queries,
            static fn (array $q): bool => str_contains(strtolower((string) $q['query']), 'backtest_')
                && preg_match('/^\s*select/i', (string) $q['query']) === 1,
        );
        static::assertNotEmpty($backtestReads, '二次查看应读取持久化报告/交易表');

        // 零重算：没有任何 backtest_* 表的写查询
        $writes = array_filter(
            $queries,
            static fn (array $q): bool => preg_match('/^\s*(insert|update|delete)/i', (string) $q['query']) === 1
                && str_contains(strtolower((string) $q['query']), 'backtest_'),
        );
        static::assertCount(0, $writes, '报告二次查看不得写 backtest_* 表（零重算）');

        // 数据原样未变
        TenantContext::initialize($this->tenant->id);
        $report = BacktestReport::where('backtest_run_id', $this->run->id)->first();
        static::assertNotNull($report);
        static::assertSame('12.50000000', $report->total_return_pct);
        static::assertSame(1, BacktestSimulatedTrade::where('backtest_run_id', $this->run->id)->count());
        static::assertSame(1, BacktestReport::where('backtest_run_id', $this->run->id)->count());
    }

    private function createCompletedRun(): BacktestRun
    {
        $run = BacktestRun::create([
            'tenant_id' => $this->tenant->id,
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
            'symbol' => 'BTCUSDT',
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
            'tenant_id' => $this->tenant->id,
            'initial_capital' => '1000',
            'final_equity' => '1125.00000000',
            'total_return_pct' => '12.50000000',
            'annualized_return_pct' => '456.25000000',
            'sharpe_ratio' => '1.23456789',
            'max_drawdown_pct' => '2.50000000',
            'win_rate_pct' => '50.00000000',
            'profit_factor' => '2.00000000',
            'trade_count' => 1,
            'total_fee' => '0.25250000',
            'bar_count' => 10,
            'data_coverage_pct' => '90.00',
            'equity_curve' => [
                [self::BAR_TIME_MS, '1000.00000000'],
                [self::BAR_TIME_MS + 60_000, '1125.00000000'],
            ],
        ]);

        BacktestSimulatedTrade::create([
            'backtest_run_id' => $run->id,
            'tenant_id' => $this->tenant->id,
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
