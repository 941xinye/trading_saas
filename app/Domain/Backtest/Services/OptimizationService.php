<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Services;

use App\Domain\Backtest\Contracts\OptimizationStatus;
use App\Domain\Backtest\Dto\OptimizationRequest;
use App\Domain\Backtest\Dto\OptimizationResult;
use App\Domain\Backtest\Jobs\RunOptimizationJob;
use App\Domain\Backtest\Models\ParameterOptimization;
use App\Domain\Backtest\Optimization\ParameterOptimizer;
use Throwable;

/**
 * 参数寻优任务编排（P11）。
 *
 * 三段式：create（pending 快照）→ execute（running → completed / failed）→ dispatch（落库 + 入队）。
 * 命令行用 run()（同步执行）；UI / API 用 dispatch()（backtest 队列异步执行）。
 * dryRun 不落库，直接返回计算结果（命令 --dry-run 用）。
 */
final class OptimizationService
{
    public function __construct(
        private readonly ParameterOptimizer $optimizer,
    ) {}

    /**
     * 落库 pending 任务（请求快照完整保存，异步执行可还原）。
     */
    public function create(OptimizationRequest $request): ParameterOptimization
    {
        return ParameterOptimization::create([
            'tenant_id' => $request->tenantId,
            'strategy_code' => $request->strategyCode,
            'symbol' => $request->symbol,
            'interval' => $request->interval,
            'from_utc' => $request->fromUtc,
            'to_utc' => $request->toUtc,
            'mode' => $request->mode,
            'folds' => $request->folds,
            'objective' => $request->objective,
            'grid' => $request->grid,
            'base_params' => $request->baseParams,
            'top_n' => $request->topN,
            'assumptions' => $request->assumptions->toArray(),
            'status' => OptimizationStatus::Pending,
            'progress_pct' => 0,
        ]);
    }

    /**
     * 创建任务并投递 backtest 队列（UI / API 入口，HTTP 不阻塞）。
     */
    public function dispatch(OptimizationRequest $request): ParameterOptimization
    {
        $record = $this->create($request);

        RunOptimizationJob::dispatch($record->id);

        return $record;
    }

    /**
     * 同步执行（命令入口）：create + execute。
     *
     * @throws Throwable 优化器/网格校验异常（记录已标 failed 后向上抛）
     */
    public function run(OptimizationRequest $request): ParameterOptimization
    {
        return $this->execute($this->create($request), $request);
    }

    /**
     * 执行既有 pending 记录：running → completed / failed。
     *
     * @throws Throwable 异常时记录标 failed 后向上抛
     */
    public function execute(ParameterOptimization $record, OptimizationRequest $request): ParameterOptimization
    {
        $record->update([
            'status' => OptimizationStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $result = $this->optimizer->optimize($request, $record);

            $record->update([
                'status' => OptimizationStatus::Completed,
                'progress_pct' => 100,
                'best_params' => $result->bestParams,
                'best_score' => $result->bestScore,
                'report' => $result->report,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $record->update([
                'status' => OptimizationStatus::Failed,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        return $record->refresh();
    }

    public function dryRun(OptimizationRequest $request): OptimizationResult
    {
        return $this->optimizer->optimize($request);
    }
}
