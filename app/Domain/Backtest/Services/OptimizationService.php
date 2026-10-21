<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Services;

use App\Domain\Backtest\Contracts\OptimizationStatus;
use App\Domain\Backtest\Dto\OptimizationRequest;
use App\Domain\Backtest\Dto\OptimizationResult;
use App\Domain\Backtest\Exceptions\OptimizationNotApplicableException;
use App\Domain\Backtest\Jobs\RunOptimizationJob;
use App\Domain\Backtest\Models\ParameterOptimization;
use App\Domain\Backtest\Optimization\ParameterOptimizer;
use App\Models\StrategyInstance;
use Illuminate\Support\Facades\DB;
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

    /**
     * 将最优参数应用到策略实例（回测 → 寻优 → 实盘闭环）。
     *
     * 合并顺序：实例现有 params ← base_params ← best_params（最优参数最终覆盖）；
     * 实例其他参数键保留不动。仅允许同策略 / 同交易对 / 同周期且 stopped 的实例，
     * 写入 params 与 applied_instance_id/applied_at 在同一事务。
     *
     * @throws OptimizationNotApplicableException 任务未完成 / 无最优参数 / 实例不匹配 / 实例运行中
     */
    public function applyToInstance(ParameterOptimization $optimization, StrategyInstance $instance): StrategyInstance
    {
        if ($optimization->status !== OptimizationStatus::Completed) {
            throw new OptimizationNotApplicableException('寻优任务尚未完成，无法应用最优参数。');
        }

        $bestParams = (array) ($optimization->best_params ?? []);
        if ($bestParams === []) {
            throw new OptimizationNotApplicableException('该寻优任务没有可应用的最优参数。');
        }

        if ((int) $instance->tenant_id !== (int) $optimization->tenant_id) {
            throw new OptimizationNotApplicableException('策略实例与寻优任务不属于同一租户。');
        }

        $strategyCode = (string) $instance->strategy()->value('code');
        if ($strategyCode !== (string) $optimization->strategy_code) {
            throw new OptimizationNotApplicableException(sprintf(
                '策略不匹配：寻优任务为 %s，实例为 %s。',
                $optimization->strategy_code,
                $strategyCode !== '' ? $strategyCode : '未知',
            ));
        }

        if (mb_strtoupper((string) $instance->symbol) !== mb_strtoupper((string) $optimization->symbol)) {
            throw new OptimizationNotApplicableException(sprintf(
                '交易对不匹配：寻优任务为 %s，实例为 %s。',
                $optimization->symbol,
                $instance->symbol,
            ));
        }

        if ((string) $instance->interval !== (string) $optimization->interval) {
            throw new OptimizationNotApplicableException(sprintf(
                'K 线周期不匹配：寻优任务为 %s，实例为 %s。',
                $optimization->interval,
                $instance->interval,
            ));
        }

        if ($instance->isLive()) {
            throw new OptimizationNotApplicableException('实例运行中，请先 stop 再应用最优参数。', conflict: true);
        }

        // 双保险：best_params 的键必须仍在策略可寻优白名单内（防御历史脏数据）
        $whitelist = (array) config("optimization.strategies.{$optimization->strategy_code}", []);
        foreach ($bestParams as $key => $value) {
            if (! in_array((string) $key, $whitelist, true)) {
                throw new OptimizationNotApplicableException(sprintf(
                    '最优参数 %s 不在策略 %s 的可寻优白名单内。',
                    $key,
                    $optimization->strategy_code,
                ));
            }

            if (! is_scalar($value)) {
                throw new OptimizationNotApplicableException("最优参数 {$key} 的值非法。");
            }
        }

        $params = array_replace(
            (array) ($instance->params ?? []),
            (array) ($optimization->base_params ?? []),
            $bestParams,
        );

        DB::transaction(function () use ($optimization, $instance, $params): void {
            $instance->forceFill(['params' => $params])->save();

            $optimization->forceFill([
                'applied_instance_id' => $instance->getKey(),
                'applied_at' => now(),
            ])->save();
        });

        return $instance->refresh();
    }
}
