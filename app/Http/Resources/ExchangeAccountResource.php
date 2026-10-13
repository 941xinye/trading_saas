<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 交易所账户 API Resource。
 *
 * 安全约定：encrypted_api_key / encrypted_api_secret / passphrase_encrypted 永远返回 null，
 * 即使内部对象持有密文也不暴露给前端。明文密钥仅在 AesGcmCrypt::decrypt 后于服务层使用。
 */
class ExchangeAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'exchange_id' => $this->exchange_id,
            'exchange' => $this->whenLoaded('exchange', fn () => [
                'id' => $this->exchange->id,
                'code' => $this->exchange->code,
                'name' => $this->exchange->name,
            ]),
            'label' => $this->label,
            'market' => $this->market,
            'sub_account' => $this->sub_account,
            // 敏感字段：密文不输出
            'api_key' => null,
            'api_secret' => null,
            'passphrase' => null,
            'dek_version' => $this->dek_version,
            'permissions' => $this->permissions,
            'status' => $this->status,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
