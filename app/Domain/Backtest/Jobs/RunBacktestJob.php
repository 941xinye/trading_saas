<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Jobs;

use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Models\BacktestReport;
use App\Domain\Backtest\Models\BacktestRun;
use App\Domain\Backtest\Models\BacktestSimulatedTrade;
use App\Domain\Backtest\Services\BacktestRunService;
use App\Domain\Backtest\Services\BacktestRunner;
use App\Domain\Backtest\Services\EquityCurveBuilder;
use App\Domain\Backtest\Services\MatchingEngine;
use App\Domain\Backtest\Services\PerformanceCalculator;
use App\Domain\Strategy\StrategyRegistry;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 回测执行 Job（backtest 队列，tries=1）。
 *
 * 流程：markRunning → TenantContext::initialize → Runner+Calculator → 单事务落库 → markCompleted
 * 异常路径：markFailed（error_message 落库）→ 释放锁 → 不重试。
 */
class RunBacktestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $runId,
    ) {
        $this->onQueue('backtest');
    }

    public function handle(
        HistoricalQuery $historical,
        StrategyRegistry $registry,
        BacktestRunService $runService,
    ): void {
        $run = BacktestRun::findOrFail($this->runId);

        $runService->markRunning($run);

        // Job 边界恢复租户上下文（对齐 AiAnalyzeJob 先例）
        TenantContext::initialize($run->tenant_id);
        try {
            $this->execute($run, $historical, $registry, $runService);
        } catch (Throwable $e) {
            $runService->markFailed($run, $e->getMessage());
            Log::error('回测执行失败', [
                'run_id' => $run->id,
                'tenant_id' => $run->tenant_id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            TenantContext::clear();
        }
    }

    private function execute(
        BacktestRun $run,
        HistoricalQuery $historical,
        StrategyRegistry $registry,
        BacktestRunService $runService,
    ): void {
        $assumptions = MatchingAssumptions::fromArray($run->assumptions ?? []);
        $params = $run->params_snapshot ?? [];

        $request = new BacktestRunRequest(
            tenantId: $run->tenant_id,
            strategyCode: $run->strategy_code,
            params: $params,
            symbol: $run->symbol,
            interval: $run->interval,
            fromUtc: $run->from_utc->toDateTimeImmutable(),
            toUtc: $run->to_utc->toDateTimeImmutable(),
            assumptions: $assumptions,
            runId: $run->id,
        );

        $equity = new EquityCurveBuilder($assumptions->initialCapital);
        $runner = new BacktestRunner($historical, $registry, new MatchingEngine(), $equity);

        $lastProgress = 0;
        $runner->onProgress(static function (int $barCount) use ($run, $runService, &$lastProgress): void {
            // 粗粒度节流：每 5% 写一次（Runner 每 500 bar 回调一次）
            $pct = min(95, (int) ($barCount / 100));
            if ($pct >= $lastProgress + 5) {
                $lastProgress = $pct;
                $runService->updateProgress($run, $pct);
            }
        });

        $result = $runner->run($request);

        $days = max(1, (int) ceil(
            ($run->to_utc->getTimestamp() - $run->from_utc->getTimestamp()) / 86400
        ));
        $report = (new PerformanceCalculator())->calculate($result, $assumptions->initialCapital, $days);

        // 完成单事务：run + report + trades 同事务落库（半完成态零容忍）
        DB::transaction(function () use ($run, $report, $result, $runService): void {
            $runService->markCompleted($run);

            BacktestReport::create([
                'backtest_run_id' => $run->id,
                'tenant_id' => $run->tenant_id,
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
                'equity_curve' => $result->equityPoints,
            ]);

            foreach ($result->trades as $trade) {
                BacktestSimulatedTrade::create([
                    'backtest_run_id' => $run->id,
                    'tenant_id' => $run->tenant_id,
                    'seq' => $trade->seq,
                    'bar_open_time' => $trade->barOpenTime,
                    'side' => $trade->side,
                    'qty' => $trade->qty,
                    'price' => $trade->price,
                    'fee' => $trade->fee,
                    'realized_pnl' => $trade->realizedPnl,
                ]);
            }
        });
    }
}
