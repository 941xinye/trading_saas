<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Optimization;

use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\OptimizationRequest;
use App\Domain\Backtest\Dto\OptimizationResult;
use App\Domain\Backtest\Dto\PerformanceReport;
use App\Domain\Backtest\Models\ParameterOptimization;
use App\Domain\Backtest\Models\ParameterOptimizationTrial;
use App\Domain\Backtest\Services\BacktestRunner;
use App\Domain\Backtest\Services\EquityCurveBuilder;
use App\Domain\Backtest\Services\MatchingEngine;
use App\Domain\Backtest\Services\PerformanceCalculator;
use App\Domain\Strategy\StrategyRegistry;
use Throwable;

/**
 * 参数寻优执行器（P11）。
 *
 * 两种模式：
 *   grid          全区间逐组合回测，按 objective 选优；
 *   walk_forward  区间切为 folds+1 段，前段训练选参、后段样本外评分，跨折取均值。
 *
 * 复用 BacktestRunner（纯内存、零交易所调用）；单组合失败只落 failed trial，不中断整体。
 * 传入 $record 时同步写 trials 与进度；不传则为 dry-run（零 DB 写入）。
 */
final class ParameterOptimizer
{
    private const TRIAL_OK = 'ok';

    private const TRIAL_FAILED = 'failed';

    public function __construct(
        private readonly HistoricalQuery $historical,
        private readonly StrategyRegistry $registry,
    ) {}

    public function optimize(OptimizationRequest $request, ?ParameterOptimization $record = null): OptimizationResult
    {
        $combinations = ParameterGrid::expand($request->grid, $request->strategyCode);

        $totalRuns = $request->mode === 'walk_forward'
            ? count($combinations) * $request->folds * 2
            : count($combinations);

        $trials = [];
        $done = 0;

        if ($request->mode === 'walk_forward') {
            $windows = $this->windows($request->folds, $request->fromUtc, $request->toUtc);

            foreach ($combinations as $combination) {
                $params = array_merge($request->baseParams, $combination);

                foreach ($windows as $fold => $window) {
                    $train = $this->evaluate($request, $params, $window['train_from'], $window['train_to'], $fold, true);
                    $trials[] = $train;
                    $this->persistTrial($record, $request, $train);
                    $this->advanceProgress($record, ++$done, $totalRuns);

                    $test = $this->evaluate($request, $params, $window['test_from'], $window['test_to'], $fold, false);
                    $trials[] = $test;
                    $this->persistTrial($record, $request, $test);
                    $this->advanceProgress($record, ++$done, $totalRuns);
                }
            }
        } else {
            foreach ($combinations as $combination) {
                $params = array_merge($request->baseParams, $combination);

                $trial = $this->evaluate($request, $params, $request->fromUtc, $request->toUtc, null, true);
                $trials[] = $trial;
                $this->persistTrial($record, $request, $trial);
                $this->advanceProgress($record, ++$done, $totalRuns);
            }
        }

        $failed = count(array_filter($trials, static fn (array $t): bool => $t['status'] === self::TRIAL_FAILED));

        if ($request->mode === 'walk_forward') {
            return $this->summarizeWalkForward($request, $trials, count($combinations), $failed);
        }

        return $this->summarizeGrid($request, $trials, count($combinations), $failed);
    }

    // ------------------------------------------------------------------
    // 汇总
    // ------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $trials
     */
    private function summarizeGrid(OptimizationRequest $request, array $trials, int $combinationCount, int $failed): OptimizationResult
    {
        $ranked = $this->rank($trials, $request->objective);
        $best = $ranked[0] ?? null;

        $top = [];
        foreach (array_slice($ranked, 0, $request->topN) as $trial) {
            $top[] = [
                'params' => $trial['params'],
                'score' => $trial['score'],
                'metrics' => $trial['metrics'],
            ];
        }

        $report = [
            'mode' => 'grid',
            'objective' => $request->objective,
            'combination_count' => $combinationCount,
            'trial_count' => count($trials),
            'failed_trial_count' => $failed,
            'best' => $best === null ? null : [
                'params' => $best['params'],
                'score' => $best['score'],
                'metrics' => $best['metrics'],
            ],
            'top' => $top,
        ];

        return new OptimizationResult(
            mode: 'grid',
            objective: $request->objective,
            combinationCount: $combinationCount,
            trialCount: count($trials),
            failedTrialCount: $failed,
            bestParams: $best['params'] ?? null,
            bestScore: $best['score'] ?? null,
            top: $top,
            report: $report,
        );
    }

    /**
     * walk-forward：每折训练段选优 → 该参数组的样本外得分；跨折均值排名。
     *
     * @param  list<array<string, mixed>>  $trials
     */
    private function summarizeWalkForward(OptimizationRequest $request, array $trials, int $combinationCount, int $failed): OptimizationResult
    {
        $windows = $this->windows($request->folds, $request->fromUtc, $request->toUtc);

        $foldReports = [];
        foreach ($windows as $fold => $window) {
            $inSample = array_values(array_filter(
                $trials,
                static fn (array $t): bool => $t['fold'] === $fold && $t['in_sample'] === true,
            ));

            $ranked = $this->rank($inSample, $request->objective);
            $chosen = $ranked[0] ?? null;

            $outOfSampleScore = null;
            if ($chosen !== null) {
                foreach ($trials as $trial) {
                    if ($trial['fold'] === $fold
                        && $trial['in_sample'] === false
                        && $trial['params'] === $chosen['params']) {
                        $outOfSampleScore = $trial['score'];
                        break;
                    }
                }
            }

            $foldReports[] = [
                'fold' => $fold + 1,
                'train_from' => $window['train_from']->format('Y-m-d H:i:s'),
                'train_to' => $window['train_to']->format('Y-m-d H:i:s'),
                'test_from' => $window['test_from']->format('Y-m-d H:i:s'),
                'test_to' => $window['test_to']->format('Y-m-d H:i:s'),
                'best_params' => $chosen['params'] ?? null,
                'in_sample_score' => $chosen['score'] ?? null,
                'out_of_sample_score' => $outOfSampleScore,
            ];
        }

        // 跨折聚合：每个参数组的样本外得分均值
        $aggregated = [];
        foreach ($trials as $trial) {
            if ($trial['in_sample'] === true || $trial['status'] !== self::TRIAL_OK || $trial['score'] === null) {
                continue;
            }

            $key = json_encode($trial['params'], JSON_UNESCAPED_SLASHES) ?: '';
            $aggregated[$key] ??= ['params' => $trial['params'], 'sum' => '0', 'count' => 0, 'fold_scores' => []];

            $aggregated[$key]['sum'] = bcadd($aggregated[$key]['sum'], $trial['score'], 8);
            $aggregated[$key]['count']++;
            $aggregated[$key]['fold_scores'][$trial['fold']] = $trial['score'];
        }

        $rows = [];
        foreach ($aggregated as $row) {
            $rows[] = [
                'params' => $row['params'],
                'score' => bcdiv($row['sum'], (string) $row['count'], 8),
                'fold_scores' => $row['fold_scores'],
            ];
        }

        usort(
            $rows,
            fn (array $a, array $b): int => $this->compareScores($request->objective, $a['score'], $b['score']),
        );

        $top = array_slice($rows, 0, $request->topN);
        $best = $top[0] ?? null;

        $report = [
            'mode' => 'walk_forward',
            'objective' => $request->objective,
            'folds' => $request->folds,
            'combination_count' => $combinationCount,
            'trial_count' => count($trials),
            'failed_trial_count' => $failed,
            'best' => $best,
            'top' => $top,
            'fold_details' => $foldReports,
        ];

        return new OptimizationResult(
            mode: 'walk_forward',
            objective: $request->objective,
            combinationCount: $combinationCount,
            trialCount: count($trials),
            failedTrialCount: $failed,
            bestParams: $best['params'] ?? null,
            bestScore: $best['score'] ?? null,
            top: $top,
            report: $report,
        );
    }

    // ------------------------------------------------------------------
    // 单次评估
    // ------------------------------------------------------------------

    /**
     * @return array{
     *   fold: int|null,
     *   in_sample: bool,
     *   params: array<string, mixed>,
     *   status: string,
     *   score: string|null,
     *   metrics: array<string, mixed>|null,
     *   error: string|null
     * }
     */
    private function evaluate(
        OptimizationRequest $request,
        array $params,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?int $fold,
        bool $inSample,
    ): array {
        $base = [
            'fold' => $fold,
            'in_sample' => $inSample,
            'params' => $params,
        ];

        try {
            $runRequest = new BacktestRunRequest(
                tenantId: $request->tenantId,
                strategyCode: $request->strategyCode,
                params: $params,
                symbol: $request->symbol,
                interval: $request->interval,
                fromUtc: $from,
                toUtc: $to,
                assumptions: $request->assumptions,
                runId: 0,
            );

            $runner = new BacktestRunner(
                $this->historical,
                $this->registry,
                new MatchingEngine,
                new EquityCurveBuilder($request->assumptions->initialCapital),
            );
            $result = $runner->run($runRequest);

            $days = max(1, (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 86400));
            $report = (new PerformanceCalculator)->calculate($result, $request->assumptions->initialCapital, $days);

            return $base + [
                'status' => self::TRIAL_OK,
                'score' => $this->scoreOf($report, $request->objective),
                'metrics' => $this->metricsOf($report),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return $base + [
                'status' => self::TRIAL_FAILED,
                'score' => null,
                'metrics' => null,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ];
        }
    }

    /**
     * 目标得分；成交笔数低于 min_trades 或指标不可计算 → null（不参与选优）。
     */
    private function scoreOf(PerformanceReport $report, string $objective): ?string
    {
        if ($report->tradeCount < (int) config('optimization.min_trades', 0)) {
            return null;
        }

        return match ($objective) {
            'total_return_pct' => $report->totalReturnPct,
            'sharpe_ratio' => $report->sharpeRatio,
            'max_drawdown_pct' => $report->maxDrawdownPct,
            'profit_factor' => $report->profitFactor,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function metricsOf(PerformanceReport $report): array
    {
        return [
            'initial_capital' => $report->initialCapital,
            'final_equity' => $report->finalEquity,
            'total_return_pct' => $report->totalReturnPct,
            'annualized_return_pct' => $report->annualizedReturnPct,
            'sharpe_ratio' => $report->sharpeRatio,
            'max_drawdown_pct' => $report->maxDrawdownPct,
            'win_rate_pct' => $report->winRatePct,
            'profit_factor' => $report->profitFactor,
            'trade_count' => $report->tradeCount,
            'total_fee' => $report->totalFee,
            'bar_count' => $report->barCount,
            'data_coverage_pct' => $report->dataCoveragePct,
        ];
    }

    // ------------------------------------------------------------------
    // 排序 / 持久化 / 窗口
    // ------------------------------------------------------------------

    /**
     * 排名只纳入成功且可评分的 trial（null 分不参与选优）。
     *
     * @param  list<array<string, mixed>>  $trials
     * @return list<array<string, mixed>>
     */
    private function rank(array $trials, string $objective): array
    {
        $scored = array_values(array_filter(
            $trials,
            static fn (array $t): bool => $t['status'] === self::TRIAL_OK && $t['score'] !== null,
        ));

        usort(
            $scored,
            fn (array $a, array $b): int => $this->compareScores($objective, $a['score'], $b['score']),
        );

        return $scored;
    }

    /**
     * 得分比较：max_drawdown_pct 越小越好，其余越大越好；null 排最后。
     */
    private function compareScores(string $objective, ?string $a, ?string $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        $cmp = bccomp($a, $b, 8);

        return $objective === 'max_drawdown_pct' ? $cmp : -$cmp;
    }

    /**
     * @param  array<string, mixed>  $trial
     */
    private function persistTrial(?ParameterOptimization $record, OptimizationRequest $request, array $trial): void
    {
        if ($record === null) {
            return;
        }

        ParameterOptimizationTrial::create([
            'optimization_id' => $record->id,
            'tenant_id' => $request->tenantId,
            'fold_index' => $trial['fold'],
            'params' => $trial['params'],
            'in_sample' => $trial['in_sample'],
            'status' => $trial['status'],
            'score' => $trial['score'],
            'metrics' => $trial['metrics'],
            'error_message' => $trial['error'],
        ]);
    }

    /**
     * 进度按 5% 粒度落库（最终 100% 由 OptimizationService 完成态写入）。
     */
    private function advanceProgress(?ParameterOptimization $record, int $done, int $total): void
    {
        if ($record === null || $total <= 0) {
            return;
        }

        $pct = min(99, (int) floor($done * 100 / $total));
        if ($pct - (int) $record->progress_pct >= 5) {
            $record->update(['progress_pct' => $pct]);
        }
    }

    /**
     * walk-forward 窗口：区间等分为 folds+1 段，第 k 折训练=段 k、样本外=段 k+1。
     *
     * @return list<array{train_from: \DateTimeImmutable, train_to: \DateTimeImmutable, test_from: \DateTimeImmutable, test_to: \DateTimeImmutable}>
     */
    private function windows(int $folds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $segments = $folds + 1;
        $span = $to->getTimestamp() - $from->getTimestamp();
        $step = intdiv($span, $segments);

        if ($step <= 0) {
            throw new \InvalidArgumentException('寻优区间过短，无法切分 walk-forward 窗口');
        }

        $points = [];
        for ($i = 0; $i <= $segments; $i++) {
            $points[] = $i === $segments ? $to->getTimestamp() : $from->getTimestamp() + $step * $i;
        }

        $windows = [];
        for ($i = 0; $i < $folds; $i++) {
            $windows[] = [
                'train_from' => new \DateTimeImmutable('@'.$points[$i]),
                'train_to' => new \DateTimeImmutable('@'.$points[$i + 1]),
                'test_from' => new \DateTimeImmutable('@'.$points[$i + 1]),
                'test_to' => new \DateTimeImmutable('@'.$points[$i + 2]),
            ];
        }

        return $windows;
    }
}
