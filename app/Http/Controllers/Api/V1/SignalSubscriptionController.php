<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SignalSubscriptionResource;
use App\Models\SignalSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 信号订阅 API Controller。
 *
 * POC 阶段：store/update 关联 BarAggregator 订阅 + MultiChannel 通知配置。
 */
class SignalSubscriptionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = SignalSubscription::query()->paginate();

        return SignalSubscriptionResource::collection($records);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'signal-subscriptions.store',
        ], 202);
    }

    public function show(SignalSubscription $subscription): SignalSubscriptionResource
    {
        return new SignalSubscriptionResource($subscription);
    }

    public function update(Request $request, SignalSubscription $subscription): JsonResponse
    {
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'signal-subscriptions.update',
        ], 202);
    }

    public function destroy(SignalSubscription $subscription): JsonResponse
    {
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'signal-subscriptions.destroy',
        ], 202);
    }
}
