<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Dto\OptimizationRequest;
use App\Domain\Backtest\Exceptions\OptimizationNotApplicableException;
use App\Domain\Backtest\Models\ParameterOptimization;
use App\Domain\Backtest\Models\ParameterOptimizationApplication;
use App\Domain\Backtest\Models\ParameterOptimizationTrial;
use App\Domain\Backtest\Services\OptimizationService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\ParameterOptimizationResource;
use App\Http\Resources\ParameterOptimizationTrialResource;
use App\Http\Resources\StrategyInstanceResource;
use App\Models\StrategyInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * 参数寻优 API（P11）。
 *
 * POST   创建任务 → 落库 pending → 投递 backtest 队列 → 202（异步执行，不阻塞请求）
 * GET    列表 / 详情（含 report 的 best/top/fold_details）/ trials（逐次试跑分页）
 *
 * 租户隔离：TenantScope + ensureTenantOwned 双保险；跨租户一律 404。
 */
class OptimizationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ParameterOptimizationResource::collection(
            ParameterOptimization::query()->orderByDesc('id')->paginate(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::requireTenantId();

        $data = $request->validate([
            'strategy_code' => ['required', 'string', Rule::in((array) config('backtest.strategies', []))],
            'symbol' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]{2,20}$/i'],
            'interval' => ['required', 'string', Rule::in(BacktestRunRequest::INTERVALS)],
            'from_utc' => ['required', 'date'],
            'to_utc' => ['required', 'date', 'after:from_utc'],
            'grid' => ['required', 'array', 'min:1'],
            'base_params' => ['sometimes', 'array'],
            'mode' => ['sometimes', 'string', Rule::in(OptimizationRequest::MODES)],
            'folds' => ['sometimes', 'integer', 'min:2', 'max:52'],
            'objective' => ['sometimes', 'string', Rule::in(array_keys((array) config('optimization.objectives', [])))],
            'top_n' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'fee_rate' => ['sometimes', 'numeric', 'min:0', 'max:0.01'],
            'slippage_bps' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'initial_capital' => ['sometimes', 'numeric', 'gt:0'],
        ]);

        $assumptions = MatchingAssumptions::fromConfig(
            capital: isset($data['initial_capital']) ? (string) $data['initial_capital'] : null,
            feeRate: isset($data['fee_rate']) ? (string) $data['fee_rate'] : null,
            slippageBps: isset($data['slippage_bps']) ? (int) $data['slippage_bps'] : null,
            strategyCode: (string) $data['strategy_code'],
        );

        try {
            $optimizationRequest = new OptimizationRequest(
                tenantId: $tenantId,
                strategyCode: (string) $data['strategy_code'],
                symbol: (string) $data['symbol'],
                interval: (string) $data['interval'],
                fromUtc: $this->toUtc((string) $data['from_utc']),
                toUtc: $this->toUtc((string) $data['to_utc']),
                grid: (array) $data['grid'],
                mode: (string) ($data['mode'] ?? 'grid'),
                folds: (int) ($data['folds'] ?? config('optimization.default_folds', 3)),
                objective: (string) ($data['objective'] ?? 'total_return_pct'),
                assumptions: $assumptions,
                baseParams: (array) ($data['base_params'] ?? []),
                topN: (int) ($data['top_n'] ?? config('optimization.top_n', 5)),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $record = app(OptimizationService::class)->dispatch($optimizationRequest);

        return (new ParameterOptimizationResource($record->refresh()))
            ->response()
            ->setStatusCode(202);
    }

    public function show(ParameterOptimization $optimization): ParameterOptimizationResource
    {
        $this->ensureTenantOwned($optimization);

        return new ParameterOptimizationResource($optimization);
    }

    /**
     * 逐次试跑明细（walk_forward 的 in_sample/fold_index 在此查看）。
     */
    public function trials(ParameterOptimization $optimization): AnonymousResourceCollection
    {
        $this->ensureTenantOwned($optimization);

        return ParameterOptimizationTrialResource::collection(
            ParameterOptimizationTrial::query()
                ->where('optimization_id', $optimization->id)
                ->orderBy('id')
                ->paginate(),
        );
    }

    /**
     * 应用寻优结果到策略实例（回测 → 寻优 → 实盘闭环）。
     *
     * body：instance_id（必填）、rank（可选，Top N 名次，默认 1 = best）。
     * 422：任务未完成 / 名次不存在 / 实例与任务不匹配；
     * 409：实例运行中（须先 stop）；404：跨租户实例或任务。
     */
    public function apply(Request $request, ParameterOptimization $optimization): JsonResponse
    {
        $this->ensureTenantOwned($optimization);

        $tenantId = TenantContext::requireTenantId();

        $data = $request->validate([
            'instance_id' => ['required', 'integer', 'min:1'],
            'rank' => ['sometimes', 'integer', 'min:1'],
        ]);

        $instance = StrategyInstance::query()
            ->where('tenant_id', $tenantId)
            ->find((int) $data['instance_id']);

        if ($instance === null) {
            abort(404);
        }

        try {
            $application = app(OptimizationService::class)->applyToInstance(
                $optimization,
                $instance,
                (int) ($data['rank'] ?? 1),
                $request->user()?->id,
            );
        } catch (OptimizationNotApplicableException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $e->conflict ? 409 : 422,
            );
        }

        return (new StrategyInstanceResource($application->instance->load(['strategy', 'exchangeAccount'])))
            ->additional(['application_id' => $application->id])
            ->response()
            ->setStatusCode(200);
    }

    /**
     * 回滚一次应用：实例 params 恢复为应用前快照。
     *
     * 409：已回滚 / 运行中 / 参数已被人工修改；422：实例已删除。
     */
    public function rollback(ParameterOptimizationApplication $application): JsonResponse
    {
        $this->ensureTenantOwned($application);

        try {
            $instance = app(OptimizationService::class)->rollbackApplication($application);
        } catch (OptimizationNotApplicableException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $e->conflict ? 409 : 422,
            );
        }

        return (new StrategyInstanceResource($instance->load(['strategy', 'exchangeAccount'])))
            ->response()
            ->setStatusCode(200);
    }

    private function toUtc(string $value): \DateTimeImmutable
    {
        return Carbon::parse($value, 'UTC')->toDateTimeImmutable();
    }
}
