<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;
use App\Domain\Backtest\Contracts\BacktestRunStatus;
use App\Domain\Backtest\Contracts\SimulatedMatcher;
use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\BacktestRunResult;
use App\Domain\Backtest\Dto\EquityPoint;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Dto\SimulatedTrade;
use App\Domain\Backtest\Exceptions\BacktestConcurrencyException;
use App\Domain\Backtest\Exceptions\BacktestDataCoverageException;
use App\Domain\Backtest\Jobs\RunBacktestJob;
use App\Domain\Backtest\Models\BacktestReport;
use App\Domain\Backtest\Models\BacktestRun;
use App\Domain\Backtest\Models\BacktestSimulatedTrade;
use App\Domain\Backtest\Services\BacktestRunService;
use App\Domain\Backtest\Services\BacktestRunner;
use App\Domain\Backtest\Services\EquityCurveBuilder;
use App\Domain\Backtest\Services\MatchingEngine;
use App\Domain\Backtest\Services\PerformanceCalculator;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * spec 008 回测引擎离线断言（T045 / contracts/backtest-commands-contract.md §3）。
 *
 * A~J 十组断言覆盖撮合/驱动/绩效/等价性/生命周期/并发/隔离/性能/红线/报告。
 * 一次性租户 poc-verify-backtest-a/b（pro / free 各一），结束自动清理（含 Redis 锁键）。
 *
 * 用法：php artisan poc:verify-backtest [--only=A,B,H]
 * 预期输出：ALL CHECKS PASSED (N/N)
 */
class PocVerifyBacktestCommand extends Command
{
    protected $signature = 'poc:verify-backtest {--only= : 仅跑指定分组，逗号分隔（A~J）}';

    protected $description = 'POC: offline assertions for backtest engine (spec 008 SC-001~006)';

    private int $pass = 0;

    private int $fail = 0;

    /** @var string[] */
    private array $failures = [];

    private int $tenantA;

    private int $tenantB;

    public function handle(
        StrategyRegistry $registry,
        BacktestRunService $runs,
    ): int {
        $this->info('==== spec 008 回测引擎 离线断言 ====');

        $groups = $this->resolveGroups();
        if ($groups === null) {
            return self::FAILURE;
        }

        $matcher = new MatchingEngine();

        try {
            $this->provisionFixtures();

            if (in_array('A', $groups, true)) {
                $this->checkMatching($matcher);
            }
            if (in_array('B', $groups, true)) {
                $this->checkDriver($registry);
            }
            if (in_array('C', $groups, true)) {
                $this->checkPerformanceVectors();
            }
            if (in_array('D', $groups, true)) {
                $this->checkEquivalence();
            }
            if (in_array('E', $groups, true)) {
                $this->checkLifecycle($runs);
            }
            if (in_array('F', $groups, true)) {
                $this->checkConcurrency($runs);
            }
            if (in_array('G', $groups, true)) {
                $this->checkIsolation();
            }
            if (in_array('H', $groups, true)) {
                $this->checkPerformance($registry, $matcher);
            }
            if (in_array('I', $groups, true)) {
                $this->checkRedLine();
            }
            if (in_array('J', $groups, true)) {
                $this->checkReportPersistence($runs);
            }
        } catch (Throwable $e) {
            $this->fail++;
            $this->failures[] = '断言流程异常中断：'.$e->getMessage();
            $this->error('FATAL: '.$e->getMessage());
            $this->line($e->getTraceAsString());
        } finally {
            $this->cleanupFixtures();
        }

        $this->newLine();
        $this->info(sprintf('RESULT: %d/%d passed', $this->pass, $this->pass + $this->fail));
        if ($this->fail > 0) {
            $this->error('VERIFY FAILED');
            foreach ($this->failures as $f) {
                $this->line('  - '.$f);
            }

            return self::FAILURE;
        }

        $this->info('ALL CHECKS PASSED ('.$this->pass.'/'.$this->pass.')');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // 分组解析 / 断言助手
    // ------------------------------------------------------------------

    /**
     * @return list<string>|null null = 参数非法
     */
    private function resolveGroups(): ?array
    {
        $valid = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        $only = $this->option('only');

        if ($only === null || trim((string) $only) === '') {
            return $valid;
        }

        $requested = array_values(array_unique(array_map(
            static fn (string $s): string => strtoupper(trim($s)),
            explode(',', (string) $only),
        )));

        $unknown = array_diff($requested, $valid);
        if ($unknown !== []) {
            $this->error('未知分组：'.implode(',', $unknown).'（合法：'.implode(',', $valid).'）');

            return null;
        }

        return $requested;
    }

    private function check(bool $cond, string $label, string $detail = ''): void
    {
        if ($cond) {
            $this->pass++;
            $this->line('  PASS '.$label);

            return;
        }

        $this->fail++;
        $this->failures[] = $label.($detail !== '' ? "（{$detail}）" : '');
        $this->error('  FAIL '.$label.($detail !== '' ? "（{$detail}）" : ''));
    }

    // ------------------------------------------------------------------
    // A 撮合（SC/FR-005）
    // ------------------------------------------------------------------

    private function checkMatching(SimulatedMatcher $matcher): void
    {
        $this->info(PHP_EOL.'---- A. 撮合价/费精度（FR-005）----');

        $bar = $this->bar('2023-11-14 22:00:00', '100');
        $assumptions = new MatchingAssumptions(
            feeRate: '0.001',
            slippageBps: 10,
            initialCapital: '1000',
        );

        $buy = $matcher->fill(['buy', '1.5'], $bar, $assumptions);
        $this->check(
            bccomp($buy->price, '100.1', 8) === 0,
            '买入价 = close × (1 + 10bps)',
            'actual='.$buy->price,
        );
        $this->check(
            bccomp($buy->fee, '0.15015', 8) === 0,
            'fee = price × qty × rate（8 位截断）',
            'actual='.$buy->fee,
        );
        $this->check(bccomp($buy->qty, '1.5', 8) === 0, 'qty 保真');

        $sell = $matcher->fill(['sell', '2.0'], $this->bar('2023-11-14 22:01:00', '200'), $assumptions);
        $this->check(
            bccomp($sell->price, '199.8', 8) === 0,
            '卖出价 = close × (1 − 10bps)',
            'actual='.$sell->price,
        );

        $rejected = false;
        try {
            $matcher->fill(['buy', '0'], $bar, $assumptions);
        } catch (\InvalidArgumentException) {
            $rejected = true;
        }
        $this->check($rejected, 'qty=0 拒绝成交');
    }

    // ------------------------------------------------------------------
    // B 驱动（FR-002/003）
    // ------------------------------------------------------------------

    private function checkDriver(StrategyRegistry $registry): void
    {
        $this->info(PHP_EOL.'---- B. Registry 构造路径 + 信号序列（FR-002/003）----');

        $ctx = new StrategyContext(0, [], null, null, [], null);
        $examples = [
            'spot_grid' => [
                'lower_price' => '90', 'upper_price' => '110',
                'grid_count' => 5, 'investment_amount' => '1000',
            ],
            'spot_dca' => ['amount_per_order' => '100', 'interval_minutes' => 1, 'max_orders' => 3],
            'futures_grid' => [
                'lower_price' => '90', 'upper_price' => '110', 'grid_count' => 5,
                'leverage' => 5, 'margin' => '1000', 'symbol' => 'BTCUSDT',
            ],
            'custom_dsl' => [
                'dsl_script' => $this->dslScript(),
                'symbol' => 'BTCUSDT',
                'tenant_id' => $this->tenantA,
                'allocated_capital' => '1000',
            ],
        ];

        foreach ($examples as $code => $params) {
            try {
                $strategy = $registry->make($code, 0, $params, $ctx);
                $this->check(
                    $strategy instanceof StrategyInterface,
                    "Registry::make({$code}) 产出 StrategyInterface",
                );
            } catch (Throwable $e) {
                $this->check(false, "Registry::make({$code}) 构造失败", $e->getMessage());
            }
        }

        // fixture 信号序列：方向 + bar 时点与预期一致
        $fixture = $this->loadFixture();
        $result = $this->runFixtureBacktest($fixture);

        $expected = $fixture['expected_trades'];
        $this->check(count($result->trades) === count($expected), 'spot_grid 成交笔数 = fixture 预期', sprintf(
            'actual=%d expected=%d',
            count($result->trades),
            count($expected),
        ));

        foreach ($expected as $i => $exp) {
            $actual = $result->trades[$i] ?? null;
            $this->check(
                $actual !== null
                    && $actual->side === $exp['side']
                    && $actual->barOpenTime === $this->ms($exp['bar_open_time']),
                sprintf('第 %d 笔方向/bar 时点一致（%s @ %s）', $i + 1, $exp['side'], $exp['bar_open_time']),
            );
        }
    }

    // ------------------------------------------------------------------
    // C 绩效黄金向量（R-004）
    // ------------------------------------------------------------------

    private function checkPerformanceVectors(): void
    {
        $this->info(PHP_EOL.'---- C. 绩效黄金向量（R-004）----');

        $calc = new PerformanceCalculator();

        // 1) 单调上涨 10 点 → return=10%，drawdown=0，win_rate=null
        $points = [];
        for ($i = 0; $i < 10; $i++) {
            $points[] = new EquityPoint($this->ms('2023-11-01 00:00:00') + $i * 86_400_000, (string) (1000 + $i * 10));
        }
        $report = $calc->calculate(new BacktestRunResult(10, [], $points, '100'), '1000', 10);
        $this->check(bccomp($report->totalReturnPct, '9', 8) === 0, '单调上涨 total_return=9%（末点 1090）', 'actual='.$report->totalReturnPct);
        $this->check(bccomp($report->maxDrawdownPct, '0', 8) === 0, '单调上涨 drawdown=0');
        $this->check($report->winRatePct === null, '零平仓 win_rate=null');

        // 2) 已知回撤 [1000,1200,900,1100] → 25%
        $equities = ['1000', '1200', '900', '1100'];
        $points = [];
        foreach ($equities as $i => $eq) {
            $points[] = new EquityPoint($this->ms('2023-11-01 00:00:00') + $i * 86_400_000, $eq);
        }
        $report = $calc->calculate(new BacktestRunResult(4, [], $points, '100'), '1000', 4);
        $this->check(
            bccomp($report->maxDrawdownPct, '25', 8) === 0,
            '回撤序列 max_drawdown=25%',
            'actual='.$report->maxDrawdownPct,
        );

        // 3) 两胜一负 → win_rate=66.66666667%，profit_factor=2.5
        $trades = [
            $this->trade(1, '100', '0'),
            $this->trade(2, '50', '0'),
            $this->trade(3, '-60', '0'),
        ];
        $report = $calc->calculate(
            new BacktestRunResult(3, $trades, [new EquityPoint($this->ms('2023-11-01 00:00:00'), '1090')], '100'),
            '1000',
            1,
        );
        $this->check(
            bccomp((string) $report->winRatePct, '66.66666666', 8) === 0,
            '两胜一负 win_rate=66.66666666%（bcmath 截断口径）',
            'actual='.$report->winRatePct,
        );
        $this->check(
            bccomp((string) $report->profitFactor, '2.5', 8) === 0,
            '两胜一负 profit_factor=2.5',
            'actual='.$report->profitFactor,
        );

        // 4) 零方差日收益 → sharpe=null（净值恒定，日收益恒为 0）
        $points = [];
        foreach (['1000', '1000', '1000'] as $i => $eq) {
            $points[] = new EquityPoint($this->ms('2023-11-01 00:00:00') + $i * 86_400_000, $eq);
        }
        $report = $calc->calculate(new BacktestRunResult(3, [], $points, '100'), '1000', 3);
        $this->check($report->sharpeRatio === null, '零方差 sharpe=null', 'actual='.var_export($report->sharpeRatio, true));

        // 5) 手续费侵蚀：10 笔 fee=1 → total_fee=10
        $trades = [];
        for ($i = 1; $i <= 10; $i++) {
            $trades[] = $this->trade($i, '0', '1');
        }
        $report = $calc->calculate(
            new BacktestRunResult(10, $trades, [new EquityPoint($this->ms('2023-11-01 00:00:00'), '990')], '100'),
            '1000',
            1,
        );
        $this->check(bccomp($report->totalFee, '10', 8) === 0, '手续费侵蚀 total_fee=10', 'actual='.$report->totalFee);
        $this->check(bccomp($report->finalEquity, '990', 8) === 0, '手续费侵蚀 final_equity=990');
    }

    // ------------------------------------------------------------------
    // D 等价性（SC-002）
    // ------------------------------------------------------------------

    private function checkEquivalence(): void
    {
        $this->info(PHP_EOL.'---- D. 等价性：净值误差 < 1%（SC-002）----');

        $fixture = $this->loadFixture();
        $result = $this->runFixtureBacktest($fixture);

        $this->check($result->trades !== [], 'fixture 产生成交');

        $liveGross = $this->liveGrossEquity($fixture);
        if ($liveGross <= 0.0) {
            $this->check(false, '实跑毛收益计算异常', (string) $liveGross);

            return;
        }

        $backtestFinal = (float) ($result->equityPoints[count($result->equityPoints) - 1]->equity ?? '0');
        $errorPct = abs($backtestFinal - $liveGross) / $liveGross * 100;

        $this->check(
            $errorPct < 1.0,
            '回测净值 vs 实跑毛收益误差 < 1%',
            sprintf('backtest=%.4f live=%.4f error=%.4f%%', $backtestFinal, $liveGross, $errorPct),
        );
        $this->check($backtestFinal <= $liveGross + 0.01, '含费净值 ≤ 实跑毛收益（方向可解释）');
    }

    // ------------------------------------------------------------------
    // E 生命周期（FR-007/R-008）
    // ------------------------------------------------------------------

    private function checkLifecycle(BacktestRunService $runs): void
    {
        $this->info(PHP_EOL.'---- E. 生命周期 + 覆盖率失败 + reap-stale（FR-007）----');

        $run = $this->createRun($this->tenantA, 'spot_grid', ['top' => 'lifecycle']);
        $runs->markRunning($run);
        $run->refresh();
        $this->check($run->status === BacktestRunStatus::Running, 'pending → running');
        $this->check($run->started_at !== null, 'running 记录 started_at');

        // 覆盖率不足 → Runner 抛 BacktestDataCoverageException → markFailed
        $failingRun = $this->createRun($this->tenantA, 'spot_grid', ['top' => 'coverage']);
        $runs->markRunning($failingRun);

        $coverageException = null;
        try {
            $this->runBacktest(
                'spot_grid',
                ['lower_price' => '90', 'upper_price' => '110', 'grid_count' => 5, 'investment_amount' => '1000'],
                $this->fixtureBarsAsArray($this->loadFixture(), 2),
                new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
                new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
                (int) $failingRun->id,
            );
        } catch (BacktestDataCoverageException $e) {
            $coverageException = $e;
            $runs->markFailed($failingRun, 'data_coverage_below_threshold');
        }
        $this->check($coverageException !== null, '覆盖率 < 80% 抛 BacktestDataCoverageException');
        $failingRun->refresh();
        $this->check(
            $failingRun->status === BacktestRunStatus::Failed && $failingRun->error_message === 'data_coverage_below_threshold',
            '异常 → failed + error_message 落库',
        );

        // reap-stale 收割
        $stale = $this->createRun($this->tenantA, 'spot_grid', ['top' => 'stale'], BacktestRunStatus::Running);
        DB::table('backtest_runs')
            ->where('id', $stale->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        TenantContext::initialize($this->tenantA);
        $exit = Artisan::call('backtest:reap-stale');
        $stale->refresh();
        $this->check($exit === 0, 'backtest:reap-stale 执行成功');
        $this->check(
            $stale->status === BacktestRunStatus::Failed
                && is_string($stale->error_message)
                && str_starts_with($stale->error_message, 'reaped:'),
            '停滞 running 行被收割为 failed（reaped: ...）',
            'actual='.(string) $stale->error_message,
        );
    }

    // ------------------------------------------------------------------
    // F 并发（FR-009）
    // ------------------------------------------------------------------

    private function checkConcurrency(BacktestRunService $runs): void
    {
        $this->info(PHP_EOL.'---- F. 单飞锁 + 租户并发上限（FR-009）----');

        // 单飞锁：同 key 二次 SET NX 必须失败
        $method = new \ReflectionMethod($runs, 'acquireLock');
        $release = new \ReflectionMethod($runs, 'releaseLock');
        $probeRunId = 990_000_001;

        $method->invoke($runs, $probeRunId, $this->tenantA);
        $conflicted = false;
        try {
            $method->invoke($runs, $probeRunId, $this->tenantA);
        } catch (BacktestConcurrencyException) {
            $conflicted = true;
        }
        $release->invoke($runs, $probeRunId, $this->tenantA);
        $this->check($conflicted, '同任务二次抢锁被拒绝（SET NX 单飞）');

        // 租户并发上限：free=1，插入 1 条 running 后再发起必须拒绝
        TenantContext::initialize($this->tenantB);
        $running = $this->createRun($this->tenantB, 'spot_grid', ['top' => 'concurrency'], BacktestRunStatus::Running);

        $rejected = false;
        try {
            $runs->create(
                $this->tenantB,
                'spot_grid',
                ['lower_price' => '90', 'upper_price' => '110', 'grid_count' => 5, 'investment_amount' => '1000'],
                'BTCUSDT',
                '1m',
                new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
                new \DateTimeImmutable('2023-11-14 23:00:00', new \DateTimeZone('UTC')),
                MatchingAssumptions::fromConfig(),
            );
        } catch (BacktestConcurrencyException) {
            $rejected = true;
        }
        $this->check($rejected, 'free 套餐并发上限 1：第二条任务被拒绝', 'running_run=#'.$running->id);
    }

    // ------------------------------------------------------------------
    // G 隔离（FR-010/SC-006）
    // ------------------------------------------------------------------

    private function checkIsolation(): void
    {
        $this->info(PHP_EOL.'---- G. 双租户隔离（FR-010/SC-006）----');

        TenantContext::initialize($this->tenantA);
        $runA = $this->createRun($this->tenantA, 'spot_grid', ['top' => 'isolation'], BacktestRunStatus::Completed);
        $this->createReportAndTrade($runA);
        $runAId = (int) $runA->id;

        TenantContext::initialize($this->tenantB);
        $runB = $this->createRun($this->tenantB, 'spot_grid', ['top' => 'isolation'], BacktestRunStatus::Completed);
        $this->createReportAndTrade($runB);
        $runBId = (int) $runB->id;

        TenantContext::initialize($this->tenantA);
        $this->check(BacktestRun::where('id', $runAId)->exists(), '租户 A 可见自身 run');
        $this->check(BacktestRun::where('id', $runBId)->count() === 0, '租户 A 不可见租户 B run');
        $this->check(BacktestReport::where('backtest_run_id', $runBId)->count() === 0, '租户 A 不可见租户 B 报告');
        $this->check(BacktestSimulatedTrade::where('backtest_run_id', $runBId)->count() === 0, '租户 A 不可见租户 B 交易');

        TenantContext::initialize($this->tenantB);
        $this->check(BacktestRun::where('id', $runBId)->exists(), '租户 B 可见自身 run');
        $this->check(BacktestRun::where('id', $runAId)->count() === 0, '租户 B 不可见租户 A run');
        $this->check(BacktestReport::where('backtest_run_id', $runAId)->count() === 0, '租户 B 不可见租户 A 报告');
        $this->check(BacktestSimulatedTrade::where('backtest_run_id', $runAId)->count() === 0, '租户 B 不可见租户 A 交易');
    }

    // ------------------------------------------------------------------
    // H 性能（SC-001）
    // ------------------------------------------------------------------

    private function checkPerformance(StrategyRegistry $registry, SimulatedMatcher $matcher): void
    {
        $this->info(PHP_EOL.'---- H. 性能：105k bars 回测 < 30s（SC-001）----');

        // 365 天 × 5m = 105,120 根 bar（惰性分页生成，避免一次性常驻内存）
        $totalBars = 105_120;
        $startTs = intdiv($this->ms('2023-01-01 00:00:00'), 1000);

        $runner = new BacktestRunner(
            $this->syntheticQuery($totalBars, (int) $startTs),
            $registry,
            $matcher,
            new EquityCurveBuilder('1000'),
        );

        $started = microtime(true);
        $result = $runner->run(new BacktestRunRequest(
            tenantId: $this->tenantA,
            strategyCode: 'spot_grid',
            params: ['lower_price' => '90', 'upper_price' => '110', 'grid_count' => 5, 'investment_amount' => '1000'],
            symbol: 'BTCUSDT',
            interval: '5m',
            fromUtc: new \DateTimeImmutable('2023-01-01 00:00:00', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
            assumptions: MatchingAssumptions::fromConfig(capital: '1000'),
            pageSize: 5000,
        ));
        $elapsed = microtime(true) - $started;

        $this->check($result->barCount === $totalBars, '105,120 根 bar 全量回放', 'actual='.$result->barCount);
        $this->check($result->trades !== [], '合成行情产生成交', 'trades='.count($result->trades));
        $this->line(sprintf('  实测耗时 %.3fs（预算 30s）', $elapsed));
        $this->check(
            $elapsed < 30.0,
            '端到端回测 < 30 秒（含 365 天 5m 全量）',
            sprintf('实测 %.3fs', $elapsed),
        );
    }

    // ------------------------------------------------------------------
    // I 红线（FR-004/SC-005）
    // ------------------------------------------------------------------

    private function checkRedLine(): void
    {
        $this->info(PHP_EOL.'---- I. 红线：回测零真实交易所调用（FR-004/SC-005）----');

        $patterns = [
            '/ExchangeAdapter\b/' => 'ExchangeAdapter 引用',
            '/->placeOrder\s*\(/' => 'placeOrder 调用',
            '/->cancelOrder\s*\(/' => 'cancelOrder 调用',
            '/->fetchOrder\s*\(/' => 'fetchOrder 调用',
            '/->fetchBalance\s*\(/' => 'fetchBalance 调用',
            '/->fetchTicker\s*\(/' => 'fetchTicker 调用',
            '/->fetchKlines\s*\(/' => 'fetchKlines 调用',
            '/->fetchPositions\s*\(/' => 'fetchPositions 调用',
        ];

        $violations = [];
        foreach ($this->phpFiles(base_path('app/Domain/Backtest')) as $file) {
            $code = (string) file_get_contents($file);
            $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
            $code = preg_replace('#//.*$#m', '', $code) ?? $code;
            $code = preg_replace('/\'[^\']*\'/', "''", $code) ?? $code;
            $code = preg_replace('/"[^"]*"/', '""', $code) ?? $code;

            foreach ($patterns as $pattern => $label) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = basename($file).': '.$label;
                }
            }
        }

        $this->check($violations === [], 'Domain\Backtest 静态扫描无交易所调用', implode('; ', $violations));

        $ctor = (new \ReflectionClass(BacktestRunner::class))->getConstructor();
        $deps = $ctor === null ? [] : array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $ctor->getParameters(),
        );
        $this->check(
            ! preg_grep('/exchange|adapter/i', $deps),
            'BacktestRunner 依赖不含 Exchange/Adapter',
            'deps='.implode(',', $deps),
        );
    }

    // ------------------------------------------------------------------
    // J 报告持久化（SC-003/FR-005）
    // ------------------------------------------------------------------

    private function checkReportPersistence(BacktestRunService $runs): void
    {
        $this->info(PHP_EOL.'---- J. 报告持久化 + 二次查看零重算（SC-003）----');

        $run = $this->createRun($this->tenantA, 'spot_grid', [
            'lower_price' => '90',
            'upper_price' => '110',
            'grid_count' => 5,
            'investment_amount' => '1000',
        ]);
        $fixture = $this->loadFixture();

        (new RunBacktestJob($run->id))->handle(
            $this->fixtureQuery($fixture['bars']),
            app(StrategyRegistry::class),
            $runs,
        );

        TenantContext::initialize($this->tenantA);
        $run->refresh();
        $this->check($run->status === BacktestRunStatus::Completed, 'Job 执行后 run=completed');
        $this->check(
            is_array($run->assumptions)
                && isset($run->assumptions['fee_rate'], $run->assumptions['slippage_bps'], $run->assumptions['initial_capital'], $run->assumptions['narrative']),
            '假设区四要素齐全（fee/slippage/capital/narrative）',
        );
        $this->check(
            str_contains((string) ($run->assumptions['narrative'] ?? ''), '成交口径'),
            '口径说明可读（成交口径 + 未建模项）',
        );

        $report = BacktestReport::where('backtest_run_id', $run->id)->first();
        $trades = BacktestSimulatedTrade::where('backtest_run_id', $run->id)->count();
        $this->check($report !== null, '报告行已落库');
        $this->check($trades > 0, '交易明细已落库', 'trades='.$trades);
        if ($report === null) {
            return;
        }

        // 二次查看：只读，无 backtest_* 写查询
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) === 1
                && str_contains(strtolower($query->sql), 'backtest_')) {
                $writes++;
            }
        });

        $first = (string) $report->total_return_pct;
        $again = BacktestReport::where('backtest_run_id', $run->id)->first();
        $againTrades = BacktestSimulatedTrade::where('backtest_run_id', $run->id)->count();

        $this->check($again !== null && (string) $again->total_return_pct === $first, '二次读取指标与首读一致（零重算）');
        $this->check($againTrades === $trades, '二次读取交易数不变');
        $this->check($writes === 0, '二次查看零 backtest_* 写查询', 'writes='.$writes);
    }

    // ------------------------------------------------------------------
    // 夹具
    // ------------------------------------------------------------------

    private function provisionFixtures(): void
    {
        foreach (['poc-verify-backtest-a', 'poc-verify-backtest-b'] as $slug) {
            $old = DB::table('tenants')->where('slug', $slug)->first();
            if ($old !== null) {
                $this->deleteTenantData((int) $old->id);
            }
        }

        $this->tenantA = $this->createTenant('poc-verify-backtest-a', 'pro');
        $this->tenantB = $this->createTenant('poc-verify-backtest-b', 'free');
    }

    private function createTenant(string $slug, string $plan): int
    {
        return (int) DB::table('tenants')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'exchange_dek' => 'poc-dek',
            'status' => 'active',
            'plan_code' => $plan,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deleteTenantData(int $tenantId): void
    {
        DB::table('backtest_simulated_trades')->where('tenant_id', $tenantId)->delete();
        DB::table('backtest_reports')->where('tenant_id', $tenantId)->delete();
        DB::table('backtest_runs')->where('tenant_id', $tenantId)->delete();
        DB::table('tenants')->where('id', $tenantId)->delete();
        $this->cleanupLocks($tenantId);
    }

    private function cleanupFixtures(): void
    {
        try {
            if (isset($this->tenantA)) {
                $this->deleteTenantData($this->tenantA);
            }
            if (isset($this->tenantB)) {
                $this->deleteTenantData($this->tenantB);
            }
        } catch (Throwable) {
            // 清理失败不影响验证结论
        } finally {
            TenantContext::clear();
        }
    }

    /**
     * 清理本租户单飞锁（keys 自动加前缀，del 前剥离前缀——坑 25）。
     */
    private function cleanupLocks(int $tenantId): void
    {
        try {
            $prefix = (string) config('database.redis.options.prefix', '');
            $keys = Redis::connection('default')->keys(sprintf('tenant:%d:backtest:lock:*', $tenantId));
            foreach ($keys as $key) {
                $raw = str_starts_with((string) $key, $prefix) ? substr((string) $key, strlen($prefix)) : (string) $key;
                Redis::connection('default')->del($raw);
            }
        } catch (Throwable) {
            // Redis 不可用时不阻断清理
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function createRun(
        int $tenantId,
        string $strategyCode,
        array $params,
        BacktestRunStatus $status = BacktestRunStatus::Pending,
    ): BacktestRun {
        TenantContext::initialize($tenantId);

        $run = BacktestRun::create([
            'tenant_id' => $tenantId,
            'strategy_code' => $strategyCode,
            'params_snapshot' => $params,
            'assumptions' => MatchingAssumptions::fromConfig(capital: '1000', strategyCode: $strategyCode)->toArray(),
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'from_utc' => new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            'to_utc' => new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            'status' => $status,
            'progress_pct' => $status === BacktestRunStatus::Completed ? 100 : 0,
        ]);

        return $run;
    }

    private function createReportAndTrade(BacktestRun $run): void
    {
        BacktestReport::create([
            'backtest_run_id' => $run->id,
            'tenant_id' => $run->tenant_id,
            'initial_capital' => '1000',
            'final_equity' => '1010.00000000',
            'total_return_pct' => '1.00000000',
            'annualized_return_pct' => '36.50000000',
            'sharpe_ratio' => null,
            'max_drawdown_pct' => '0',
            'win_rate_pct' => null,
            'profit_factor' => null,
            'trade_count' => 1,
            'total_fee' => '0.10000000',
            'bar_count' => 10,
            'data_coverage_pct' => '100.00',
            'equity_curve' => [
                [$this->ms('2023-11-14 22:00:00'), '1000.00000000'],
                [$this->ms('2023-11-14 22:09:00'), '1010.00000000'],
            ],
        ]);

        BacktestSimulatedTrade::create([
            'backtest_run_id' => $run->id,
            'tenant_id' => $run->tenant_id,
            'seq' => 1,
            'bar_open_time' => $this->ms('2023-11-14 22:00:00'),
            'side' => 'buy',
            'qty' => '1.000000000000',
            'price' => '100.000000000000',
            'fee' => '0.10000000',
            'realized_pnl' => '0',
        ]);
    }

    // ------------------------------------------------------------------
    // 回测执行助手
    // ------------------------------------------------------------------

    /**
     * fixture 全量回测（等价性/驱动共用）。
     *
     * @param array<string, mixed> $fixture
     */
    private function runFixtureBacktest(array $fixture): BacktestRunResult
    {
        $bars = $fixture['bars'];
        $from = new \DateTimeImmutable((string) $bars[0]['open_time'], new \DateTimeZone('UTC'));
        $to = (new \DateTimeImmutable((string) $bars[count($bars) - 1]['open_time'], new \DateTimeZone('UTC')))
            ->modify('+60 seconds');

        return $this->runBacktest(
            (string) $fixture['strategy'],
            (array) $fixture['params'],
            $bars,
            $from,
            $to,
        );
    }

    /**
     * @param array<string, mixed>              $params
     * @param list<array<string, string>>       $bars
     */
    private function runBacktest(
        string $strategyCode,
        array $params,
        array $bars,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?int $runId = null,
    ): BacktestRunResult {
        $runner = new BacktestRunner(
            $this->fixtureQuery($bars),
            app(StrategyRegistry::class),
            new MatchingEngine(),
            new EquityCurveBuilder((string) config('backtest.defaults.initial_capital')),
        );

        return $runner->run(new BacktestRunRequest(
            tenantId: $this->tenantA,
            strategyCode: $strategyCode,
            params: $params,
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: $from,
            toUtc: $to,
            assumptions: MatchingAssumptions::fromConfig(),
            runId: $runId,
        ));
    }

    /**
     * @param list<array<string, string>> $bars
     */
    private function fixtureQuery(array $bars): HistoricalQuery
    {
        return new class($bars) implements HistoricalQuery {
            /** @param list<array<string, string>> $bars */
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
     * 合成行情惰性分页（H 组）：三角波 90↔110，5m 周期。
     */
    private function syntheticQuery(int $totalBars, int $startTs): HistoricalQuery
    {
        return new class($totalBars, $startTs) implements HistoricalQuery {
            public function __construct(
                private readonly int $totalBars,
                private readonly int $startTs,
            ) {
            }

            public function query(HistoricalQueryRequest $request): HistoricalQueryResult
            {
                $offset = $request->cursor === null ? 0 : (int) $request->cursor;
                $size = min(5000, $this->totalBars - $offset);
                $rows = [];

                for ($i = $offset; $i < $offset + $size; $i++) {
                    $phase = $i % 40;
                    $close = $phase < 20 ? 90 + $phase : 110 - ($phase - 20);
                    $openTime = gmdate('Y-m-d H:i:s', $this->startTs + $i * 300);
                    $closeTime = gmdate('Y-m-d H:i:s', $this->startTs + $i * 300 + 299);

                    $rows[] = [
                        'id' => (string) ($i + 1),
                        'exchange_code' => 'binance',
                        'symbol' => 'BTCUSDT',
                        'interval' => '5m',
                        'open_time' => $openTime,
                        'close_time' => $closeTime,
                        'open' => (string) $close,
                        'high' => (string) ($close + 1),
                        'low' => (string) ($close - 1),
                        'close' => (string) $close,
                        'volume' => '1',
                        'quote_volume' => '1',
                        'trades_count' => '1',
                    ];
                }

                $next = $offset + $size < $this->totalBars ? (string) ($offset + $size) : null;

                return new HistoricalQueryResult($rows, $next, HistoricalQueryResult::HOT);
            }
        };
    }

    /**
     * 实跑毛收益：按 fixture 成交价、无费无滑点重放现金/持仓，末点按最后 close 估值。
     *
     * @param array<string, mixed> $fixture
     */
    private function liveGrossEquity(array $fixture): float
    {
        $cash = 1000.0;
        $position = 0.0;

        foreach ($fixture['expected_trades'] as $trade) {
            $qty = (float) $trade['qty'];
            $price = (float) $trade['price'];

            if ($trade['side'] === 'buy') {
                $cost = $qty * $price;
                if ($cost > $cash) {
                    $qty = $cash / $price;
                    $cost = $cash;
                }
                $cash -= $cost;
                $position += $qty;
            } else {
                $qty = min($qty, $position);
                $cash += $qty * $price;
                $position -= $qty;
            }
        }

        $lastClose = (float) $fixture['bars'][count($fixture['bars']) - 1]['close'];

        return $cash + $position * $lastClose;
    }

    /**
     * @param array<string, mixed> $fixture
     * @return list<array<string, string>>
     */
    private function fixtureBarsAsArray(array $fixture, int $limit): array
    {
        return array_slice($fixture['bars'], 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(): array
    {
        $path = base_path('tests/fixtures/backtest/testnet_spot_grid_sample.json');
        $decode = json_decode((string) file_get_contents($path), true);
        if (! is_array($decode)) {
            throw new \RuntimeException('fixture 解析失败: '.$path);
        }

        return $decode;
    }

    private function bar(string $openTime, string $close): ExchangeKline
    {
        $ts = $this->ms($openTime);

        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '1m',
            openTime: $ts,
            closeTime: $ts + 59_000,
            open: $close,
            high: $close,
            low: $close,
            close: $close,
            volume: '1',
            quoteVolume: '1',
            tradesCount: 1,
        );
    }

    private function trade(int $seq, string $realizedPnl, string $fee): SimulatedTrade
    {
        return new SimulatedTrade(
            seq: $seq,
            barOpenTime: $this->ms('2023-11-01 00:00:00'),
            side: 'sell',
            qty: '1',
            price: '100',
            fee: $fee,
            realizedPnl: $realizedPnl,
        );
    }

    private function ms(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp() * 1000;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function dslScript(): string
    {
        return implode("\n", [
            'version: 1',
            'name: poc-dsl',
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
            '      op: lt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "95"',
            '    qty_pct: "0.5"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: gte',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "99"',
            '    qty_pct: "1.0"',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);
    }
}
