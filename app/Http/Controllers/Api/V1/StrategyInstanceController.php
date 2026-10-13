<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\StrategyInstanceResource;
use App\Models\StrategyInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 策略实例 API Controller。
 *
 * 状态机：stopped ↔ starting ↔ running ↔ paused_by_breaker ↔ error
 *  - start：调度到 StrategyRunner 协程（进程 B）
 *  - stop：撤销协程 + 持久化最终状态到 strategy_instances + Redis
 *
 * 骨架阶段：start/stop 占位，POC 阶段挂 RiskGuard.check + PlaceOrderJob 队列。
 */
class StrategyInstanceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = StrategyInstance::query()->paginate();

        return StrategyInstanceResource::collection($records);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'strategy-instances.store',
        ], 202);
    }

    public function show(StrategyInstance $instance): StrategyInstanceResource
    {
        return new StrategyInstanceResource($instance);
    }

    public function update(Request $request, StrategyInstance $instance): JsonResponse
    {
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'strategy-instances.update',
        ], 202);
    }

    public function destroy(StrategyInstance $instance): JsonResponse
    {
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'strategy-instances.destroy',
        ], 202);
    }

    /**
     * 启动策略实例：调度到 StrategyRunner（进程 B）。
     */
    public function start(StrategyInstance $instance): JsonResponse
    {
        // POC 阶段：StrategyRunner::start($instance) + 状态机迁 starting → running
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'strategy-instances.start',
            'instance_id' => $instance->getKey(),
        ], 202);
    }

    /**
     * 停止策略实例：撤销协程 + 持久化最终状态。
     */
    public function stop(StrategyInstance $instance): JsonResponse
    {
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'strategy-instances.stop',
            'instance_id' => $instance->getKey(),
        ], 202);
    }
}
