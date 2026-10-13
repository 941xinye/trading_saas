<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\RiskRuleResource;
use App\Models\RiskRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 风控规则 API Controller。
 *
 * rule_type：max_loss_per_trade / max_daily_loss / max_drawdown / consecutive_losses
 * POC 阶段：store/update 关联 RiskGuard 与 CircuitBreaker 状态机初始化。
 */
class RiskRuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = RiskRule::query()->paginate();

        return RiskRuleResource::collection($records);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'risk-rules.store',
        ], 202);
    }

    public function show(RiskRule $rule): RiskRuleResource
    {
        return new RiskRuleResource($rule);
    }

    public function update(Request $request, RiskRule $rule): JsonResponse
    {
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'risk-rules.update',
        ], 202);
    }

    public function destroy(RiskRule $rule): JsonResponse
    {
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'risk-rules.destroy',
        ], 202);
    }
}
