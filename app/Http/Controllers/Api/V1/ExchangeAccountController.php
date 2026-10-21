<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exchange\ExchangeAccountService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\ExchangeAccountResource;
use App\Models\ExchangeAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * 交易所账户 API Controller。
 *
 * 写操作统一走 ExchangeAccountService：明文密钥只在请求内存在，落库前用租户 DEK
 * 做 AES-256-GCM 加密；读接口永不返回明文（Resource 把 encrypted_* 置 null）。
 *
 * 注意：TenantScope 已自动按当前租户过滤，无需手动 where tenant_id。
 */
class ExchangeAccountController extends Controller
{
    public function __construct(
        private readonly ExchangeAccountService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $records = ExchangeAccount::query()->with('exchange')->paginate();

        return ExchangeAccountResource::collection($records);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::requireTenantId();

        $data = $request->validate([
            'exchange_id' => ['required', 'integer', 'exists:exchanges,id'],
            'label' => [
                'required', 'string', 'max:60',
                Rule::unique('exchange_accounts', 'label')
                    ->where(fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('exchange_id', (int) $request->input('exchange_id'))),
            ],
            'market' => ['required', Rule::in(['spot', 'futures'])],
            'sub_account' => ['nullable', 'string', 'max:60'],
            'api_key' => ['required', 'string', 'max:4096'],
            'api_secret' => ['required', 'string', 'max:4096'],
            'passphrase' => ['nullable', 'string', 'max:4096'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'max:40'],
            'status' => ['sometimes', Rule::in(['active', 'revoked', 'expired'])],
        ]);

        $account = $this->service->create($tenantId, $data);

        return (new ExchangeAccountResource($account->load('exchange')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ExchangeAccount $account): ExchangeAccountResource
    {
        $this->ensureTenantOwned($account);

        return new ExchangeAccountResource($account->load('exchange'));
    }

    public function update(Request $request, ExchangeAccount $account): ExchangeAccountResource
    {
        $this->ensureTenantOwned($account);

        $tenantId = TenantContext::requireTenantId();
        $exchangeId = (int) $request->input('exchange_id', $account->exchange_id);

        $data = $request->validate([
            'exchange_id' => ['sometimes', 'integer', 'exists:exchanges,id'],
            'label' => [
                'sometimes', 'string', 'max:60',
                Rule::unique('exchange_accounts', 'label')
                    ->where(fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('exchange_id', $exchangeId))
                    ->ignore($account->getKey()),
            ],
            'market' => ['sometimes', Rule::in(['spot', 'futures'])],
            'sub_account' => ['sometimes', 'nullable', 'string', 'max:60'],
            'api_key' => ['sometimes', 'string', 'max:4096'],
            'api_secret' => ['sometimes', 'string', 'max:4096'],
            'passphrase' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'max:40'],
            'status' => ['sometimes', Rule::in(['active', 'revoked', 'expired'])],
        ]);

        $account = $this->service->update($account, $data);

        return new ExchangeAccountResource($account->load('exchange'));
    }

    public function destroy(ExchangeAccount $account): JsonResponse
    {
        $this->ensureTenantOwned($account);

        if (! $this->service->delete($account)) {
            return response()->json([
                'message' => '账户仍被策略实例引用，请先删除相关实例。',
            ], 409);
        }

        return response()->json(null, 204);
    }
}
