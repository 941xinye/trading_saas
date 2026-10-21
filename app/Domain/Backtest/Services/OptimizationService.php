<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Services;

use App\Domain\Backtest\Contracts\OptimizationStatus;
use App\Domain\Backtest\Dto\OptimizationRequest;
use App\Domain\Backtest\Dto\OptimizationResult;
use App\Domain\Backtest\Exceptions\OptimizationNotApplicableException;
use App\Domain\Backtest\Jobs\RunOptimizationJob;
use App\Domain\Backtest\Models\ParameterOptimization;
use App\Domain\Backtest\Models\ParameterOptimizationApplication;
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
     * 将寻优结果应用到策略实例（回测 → 寻优 → 实盘闭环）。
     *
     * rank 为 Top N 名次（1 = best，默认），>1 时从 report.top 取对应候选参数。
     * 合并顺序：实例现有 params ← base_params ← 候选 params（候选最终覆盖）；
     * 实例其他参数键保留不动。仅允许同策略 / 同交易对 / 同周期且 stopped 的实例。
     *
     * 同一事务内写实例 params、任务 applied 留痕与应用历史（含前后快照，可回滚）。
     *
     * @throws OptimizationNotApplicableException 任务未完成 / 名次不存在 / 实例不匹配 / 实例运行中
     */
    public function applyToInstance(
        ParameterOptimization $optimization,
        StrategyInstance $instance,
        int $rank = 1,
        ?int $appliedBy = null,
    ): ParameterOptimizationApplication {
        if ($optimization->status !== OptimizationStatus::Completed) {
            throw new OptimizationNotApplicableException('寻优任务尚未完成，无法应用最优参数。');
        }

        if ($rank < 1) {
            throw new OptimizationNotApplicableException('参数组名次必须从 1 开始。');
        }

        $top = (array) ($optimization->report['top'] ?? []);
        $appliedParams = $rank === 1
            ? (array) ($optimization->best_params ?? [])
            : (array) ($top[$rank - 1]['params'] ?? []);

        if ($appliedParams === []) {
            throw new OptimizationNotApplicableException(
                $rank === 1
                    ? '该寻优任务没有可应用的最优参数。'
                    : "该寻优任务不存在第 {$rank} 名参数组。",
            );
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

        // 双保险：参与寻优的维度必须仍在策略白名单内（防御历史脏数据）
        $whitelist = (array) config("optimization.strategies.{$optimization->strategy_code}", []);
        foreach (array_keys((array) $optimization->grid) as $key) {
            if (! in_array((string) $key, $whitelist, true)) {
                throw new OptimizationNotApplicableException(sprintf(
                    '寻优维度 %s 不在策略 %s 的白名单内。',
                    $key,
                    $optimization->strategy_code,
                ));
            }
        }

        $before = (array) ($instance->params ?? []);
        $after = array_replace(
            $before,
            (array) ($optimization->base_params ?? []),
            $appliedParams,
        );

        $application = DB::transaction(function () use (
            $optimization,
            $instance,
            $before,
            $after,
            $rank,
            $appliedBy,
        ): ParameterOptimizationApplication {
            $instance->forceFill(['params' => $after])->save();

            $optimization->forceFill([
                'applied_instance_id' => $instance->getKey(),
                'applied_at' => now(),
            ])->save();

            return ParameterOptimizationApplication::create([
                'tenant_id' => $optimization->tenant_id,
                'optimization_id' => $optimization->id,
                'strategy_instance_id' => $instance->getKey(),
                'rank' => $rank,
                'params_before' => $before,
                'params_after' => $after,
                'applied_by' => $appliedBy,
            ]);
        });

        $application->setRelation('instance', $instance->refresh());

        return $application;
    }

    /**
     * 回滚一次应用：实例 params 恢复为应用前快照。
     *
     * 仅当实例仍 stopped 且参数与 params_after 一致时可回滚（避免覆盖人工修改）；
     * 每条应用记录只允许回滚一次。
     *
     * @throws OptimizationNotApplicableException 已回滚 / 实例已删除 / 参数已变更 / 实例运行中
     */
    public function rollbackApplication(ParameterOptimizationApplication $application): StrategyInstance
    {
        if ($application->rolled_back_at !== null) {
            throw new OptimizationNotApplicableException('该应用记录已回滚，不能重复回滚。', conflict: true);
        }

        $instance = $application->instance;
        if ($instance === null) {
            throw new OptimizationNotApplicableException('目标实例已删除，无法回滚。');
        }

        if ((int) $instance->tenant_id !== (int) $application->tenant_id) {
            throw new OptimizationNotApplicableException('应用记录与实例不属于同一租户。');
        }

        if ($instance->isLive()) {
            throw new OptimizationNotApplicableException('实例运行中，请先 stop 再回滚。', conflict: true);
        }

        if ((array) ($instance->params ?? []) != (array) ($application->params_after ?? [])) {
            throw new OptimizationNotApplicableException('实例参数已被修改，无法自动回滚。', conflict: true);
        }

        DB::transaction(function () use ($application, $instance): void {
            $instance->forceFill(['params' => (array) ($application->params_before ?? [])])->save();
            $application->forceFill(['rolled_back_at' => now()])->save();
        });

        return $instance->refresh();
    }
}
