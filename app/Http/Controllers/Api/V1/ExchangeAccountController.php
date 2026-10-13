<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ExchangeAccountResource;
use App\Models\ExchangeAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 交易所账户 API Controller。
 *
 * 骨架阶段：返回 Skeleton 占位响应。POC 阶段：
 *  - store/update 接 AesGcmCrypt 加密 encrypted_api_key/secret
 *  - index/show 永不返回明文（Resource 把 encrypted_* 字段置 null）
 *
 * 注意：TenantScope 已自动按当前租户过滤，无需手动 where。
 */
class ExchangeAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = ExchangeAccount::query()->paginate();

        return ExchangeAccountResource::collection($records);
    }

    public function store(Request $request): JsonResponse
    {
        // POC 阶段：DB::transaction + AesGcmCrypt::encrypt($request->api_key) → encrypted_api_key
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'exchange-accounts.store',
        ], 202);
    }

    public function show(ExchangeAccount $account): ExchangeAccountResource
    {
        return new ExchangeAccountResource($account);
    }

    public function update(Request $request, ExchangeAccount $account): JsonResponse
    {
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'exchange-accounts.update',
        ], 202);
    }

    public function destroy(ExchangeAccount $account): JsonResponse
    {
        // 骨架占位：POC 阶段挂 DeleteExchangeAccountJob
        return response()->json([
            'message' => 'Skeleton. Wait for POC.',
            'resource' => 'exchange-accounts.destroy',
        ], 202);
    }
}
