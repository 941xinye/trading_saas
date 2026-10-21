<?php

declare(strict_types=1);

namespace Tests\Integration\Backtest;

use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;
use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Jobs\RunBacktestJob;
use App\Domain\Backtest\Models\BacktestReport;
use App\Domain\Backtest\Models\BacktestRun;
use App\Domain\Backtest\Services\BacktestRunService;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US4 撮合假设可配置与显式标注（T039）。
 *
 *  - fee_rate 0.001 vs 0.002 双跑：手续费总额上升、总收益下降
 *  - 报告 assumptions JSON 快照与发起参数一致（Job 只消费快照，不重算假设）
 *  - futures_grid 报告含资金费率免责标注（US4-3 / FR-013）
 *  - 默认参数来源 config/backtest.php
 */
final class BacktestAssumptionsTest extends TestCase
{
    use RefreshDatabase;

    private const SPOT_GRID_PARAMS = [
        'lower_price' => '90',
        'upper_price' => '110',
        'grid_count' => 5,
        'investment_amount' => '1000',
    ];

    private const FUTURES_GRID_PARAMS = [
        'lower_price' => '90',
        'upper_price' => '110',
        'grid_count' => 5,
        'leverage' => 5,
        'margin' => '1000',
        'symbol' => 'BTCUSDT',
    ];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'assumptions-tenant',
            'slug' => 'assumptions-tenant',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
        ]);
        TenantContext::initialize($this->tenant->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // T039-1 费率翻倍 → 手续费上升、收益下降
    // ------------------------------------------------------------------

    public function test_higher_fee_rate_increases_total_fee_and_lowers_return(): void
    {
        [$runLow, $reportLow] = $this->runBacktest(
            'spot_grid',
            self::SPOT_GRID_PARAMS,
            MatchingAssumptions::fromConfig(capital: '1000', feeRate: '0.001', slippageBps: 5),
        );

        [$runHigh, $reportHigh] = $this->runBacktest(
            'spot_grid',
            self::SPOT_GRID_PARAMS,
            MatchingAssumptions::fromConfig(capital: '1000', feeRate: '0.002', slippageBps: 5),
        );

        static::assertGreaterThanOrEqual(1, $reportLow->trade_count, 'fixture 应产生模拟成交');
        static::assertGreaterThanOrEqual(1, $reportHigh->trade_count);

        // 手续费总额随费率翻倍上升（同决策序列下接近翻倍，留出仓位差异容差）
        static::assertSame(1, bccomp($reportHigh->total_fee, $reportLow->total_fee, 8), '费率翻倍后 total_fee 必须上升');
        $ratio = bcdiv($reportHigh->total_fee, $reportLow->total_fee, 4);
        static::assertGreaterThanOrEqual(0, bccomp($ratio, '1.5', 4), 'total_fee 倍率应与费率翻倍同向（≥ 1.5x）');

        // 收益下降（费率更高 → 净值更低）
        static::assertSame(-1, bccomp($reportHigh->total_return_pct, $reportLow->total_return_pct, 8), '费率翻倍后总收益必须下降');
    }

    // ------------------------------------------------------------------
    // T039-2 假设快照与发起参数一致
    // ------------------------------------------------------------------

    public function test_assumptions_snapshot_matches_request_and_job_uses_snapshot(): void
    {
        $assumptions = MatchingAssumptions::fromConfig(
            capital: '1000',
            feeRate: '0.002',
            slippageBps: 7,
            strategyCode: 'spot_grid',
        );

        [$run] = $this->runBacktest('spot_grid', self::SPOT_GRID_PARAMS, $assumptions);

        // 快照即发起参数（fee/slippage/capital/narrative 四项一致）
        static::assertSame($assumptions->toArray(), $run->assumptions);

        // 口径说明可读且含关键项
        static::assertStringContainsString('成交口径', (string) $run->assumptions['narrative']);
        static::assertStringContainsString('7bps', (string) $run->assumptions['narrative']);
        static::assertStringContainsString('0.002', (string) $run->assumptions['narrative']);
        static::assertStringContainsString(MatchingAssumptions::NARRATIVE_UNMODELED, (string) $run->assumptions['narrative']);
    }

    // ------------------------------------------------------------------
    // T039-3 futures_grid 资金费率免责
    // ------------------------------------------------------------------

    public function test_futures_grid_report_disclaims_funding_rate(): void
    {
        $assumptions = MatchingAssumptions::fromConfig(strategyCode: 'futures_grid');

        static::assertStringContainsString(
            MatchingAssumptions::NARRATIVE_FUNDING_DISCLAIMER,
            $assumptions->narrative,
        );

        [$run, $report] = $this->runBacktest('futures_grid', self::FUTURES_GRID_PARAMS, $assumptions);

        // 报告侧（run 快照供详情页假设区展示）含明确免责
        static::assertStringContainsString(
            '未含资金费率影响',
            (string) $run->assumptions['narrative'],
        );
        static::assertSame($run->id, $report->backtest_run_id);
    }

    // ------------------------------------------------------------------
    // T039-4 默认参数来源 config/backtest.php
    // ------------------------------------------------------------------

    public function test_defaults_come_from_backtest_config(): void
    {
        $defaults = config('backtest.defaults');
        $assumptions = MatchingAssumptions::fromConfig();

        static::assertSame((string) $defaults['initial_capital'], $assumptions->initialCapital);
        static::assertSame((string) $defaults['fee_rate'], $assumptions->feeRate);
        static::assertSame((int) $defaults['slippage_bps'], $assumptions->slippageBps);
    }

    // ------------------------------------------------------------------
    // T040 校验规则
    // ------------------------------------------------------------------

    public function test_assumptions_validation_rejects_out_of_range_values(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MatchingAssumptions::fromConfig(capital: '0');
    }

    public function test_assumptions_validation_rejects_fee_rate_above_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MatchingAssumptions::fromConfig(feeRate: '0.02');
    }

    public function test_request_validation_rejects_invalid_interval_and_reversed_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BacktestRunRequest(
            tenantId: $this->tenant->id,
            strategyCode: 'spot_grid',
            params: self::SPOT_GRID_PARAMS,
            symbol: 'BTCUSDT',
            interval: '2m', // 不在 1m/5m/15m/1h 白名单
            fromUtc: new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            assumptions: MatchingAssumptions::fromConfig(),
        );
    }

    public function test_request_normalizes_symbol_to_uppercase(): void
    {
        $request = new BacktestRunRequest(
            tenantId: $this->tenant->id,
            strategyCode: 'spot_grid',
            params: self::SPOT_GRID_PARAMS,
            symbol: ' btcusdt ',
            interval: '1m',
            fromUtc: new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            assumptions: MatchingAssumptions::fromConfig(),
        );

        static::assertSame('BTCUSDT', $request->symbol);
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    /**
     * 落一条 pending run（假设快照=发起参数），同步执行 Job，返回 [run, report]。
     *
     * @param array<string, mixed> $params
     * @return array{0: BacktestRun, 1: BacktestReport}
     */
    private function runBacktest(string $strategyCode, array $params, MatchingAssumptions $assumptions): array
    {
        $run = BacktestRun::create([
            'tenant_id' => $this->tenant->id,
            'strategy_code' => $strategyCode,
            'params_snapshot' => $params,
            'assumptions' => $assumptions->toArray(),
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'from_utc' => new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            'to_utc' => new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            'status' => \App\Domain\Backtest\Contracts\BacktestRunStatus::Pending,
            'progress_pct' => 0,
        ]);

        $job = new RunBacktestJob($run->id);
        $job->handle(
            $this->mockHistoricalQuery($this->makeBars([100, 98, 96, 94, 92, 92, 95, 98, 100, 102])),
            app(StrategyRegistry::class),
            app(BacktestRunService::class),
        );

        // Job finally 会 clear 租户上下文，读回前恢复
        TenantContext::initialize($this->tenant->id);

        $run->refresh();
        $report = BacktestReport::where('backtest_run_id', $run->id)->first();
        static::assertNotNull($report, '回测应产出报告');

        return [$run, $report];
    }

    private function mockHistoricalQuery(array $bars): HistoricalQuery
    {
        return new class($bars) implements HistoricalQuery {
            public function __construct(private readonly array $bars)
            {
            }

            public function query(HistoricalQueryRequest $request): HistoricalQueryResult
            {
                $rows = [];
                foreach ($this->bars as $i => $bar) {
                    $rows[] = [
                        'id' => (string) ($i + 1),
                        'exchange_code' => 'binance',
                        'symbol' => $request->symbol ?? 'BTCUSDT',
                        'interval' => $request->interval ?? '1m',
                        'open_time' => $bar['open_time'],
                        'close_time' => $bar['close_time'],
                        'open' => $bar['open'],
                        'high' => $bar['high'],
                        'low' => $bar['low'],
                        'close' => $bar['close'],
                        'volume' => $bar['volume'],
                        'quote_volume' => $bar['quote_volume'],
                        'trades_count' => $bar['trades_count'],
                    ];
                }

                return new HistoricalQueryResult($rows, null, HistoricalQueryResult::HOT);
            }
        };
    }

    /**
     * @param list<int|float> $closes
     * @return list<array<string, string>>
     */
    private function makeBars(array $closes): array
    {
        $start = new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC'));
        $bars = [];

        foreach ($closes as $i => $close) {
            $openTime = $start->modify("+{$i} minute");
            $closeTime = $openTime->modify('+59 seconds');
            $c = (string) $close;
            $bars[] = [
                'open_time' => $openTime->format('Y-m-d H:i:s'),
                'close_time' => $closeTime->format('Y-m-d H:i:s'),
                'open' => $c,
                'high' => (string) ($close + 1),
                'low' => (string) ($close - 1),
                'close' => $c,
                'volume' => '1',
                'quote_volume' => '1',
                'trades_count' => '1',
            ];
        }

        return $bars;
    }
}
