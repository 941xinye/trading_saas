<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Models\ExchangeAccount;
use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;

/**
 * 交易所账户领域服务（API 与 Filament 共用的唯一加密写入口）。
 *
 * 明文 api_key/api_secret/passphrase 只在请求生命周期内存活，落库前用
 * 租户 DEK 做 AES-256-GCM 加密；后续任何读取都不应返回明文。
 */
class ExchangeAccountService
{
    public function __construct(
        private readonly KeyResolver $keyResolver,
        private readonly AesGcmCrypt $crypt,
    ) {}

    /**
     * 创建账户。密钥缺失时抛 InvalidArgumentException（调用方应先做参数校验）。
     *
     * @param  array{exchange_id:int,label:string,market:string,sub_account?:?string,api_key:string,api_secret:string,passphrase?:?string,permissions?:array<int,string>,status?:string}  $data
     */
    public function create(int $tenantId, array $data): ExchangeAccount
    {
        $dek = $this->keyResolver->tenantDek($tenantId);

        return ExchangeAccount::query()->create([
            'tenant_id' => $tenantId,
            'exchange_id' => (int) $data['exchange_id'],
            'label' => (string) $data['label'],
            'market' => (string) $data['market'],
            'sub_account' => $data['sub_account'] ?? null,
            'encrypted_api_key' => $this->crypt->encrypt((string) $data['api_key'], $dek),
            'encrypted_api_secret' => $this->crypt->encrypt((string) $data['api_secret'], $dek),
            'passphrase_encrypted' => filled($data['passphrase'] ?? null)
                ? $this->crypt->encrypt((string) $data['passphrase'], $dek)
                : null,
            'permissions' => $data['permissions'] ?? [],
            'status' => $data['status'] ?? 'active',
        ]);
    }

    /**
     * 更新账户。未提供 api_key/api_secret/passphrase 时保留原密文。
     *
     * @param  array{exchange_id?:int,label?:string,market?:string,sub_account?:?string,api_key?:string,api_secret?:string,passphrase?:?string,permissions?:array<int,string>,status?:string}  $data
     */
    public function update(ExchangeAccount $account, array $data): ExchangeAccount
    {
        $attributes = [];

        foreach (['exchange_id', 'label', 'market', 'sub_account', 'permissions', 'status'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }

        $needsDek = filled($data['api_key'] ?? null)
            || filled($data['api_secret'] ?? null)
            || filled($data['passphrase'] ?? null);
        $dek = $needsDek ? $this->keyResolver->tenantDek((int) $account->tenant_id) : null;

        if (filled($data['api_key'] ?? null)) {
            $attributes['encrypted_api_key'] = $this->crypt->encrypt((string) $data['api_key'], (string) $dek);
        }
        if (filled($data['api_secret'] ?? null)) {
            $attributes['encrypted_api_secret'] = $this->crypt->encrypt((string) $data['api_secret'], (string) $dek);
        }
        if (array_key_exists('passphrase', $data)) {
            $attributes['passphrase_encrypted'] = filled($data['passphrase'])
                ? $this->crypt->encrypt((string) $data['passphrase'], (string) $dek)
                : null;
        }

        $account->fill($attributes)->save();

        return $account->refresh();
    }

    /**
     * 删除账户。仍被策略实例引用时拒绝（避免级联删除运行态）。
     */
    public function delete(ExchangeAccount $account): bool
    {
        if ($account->strategyInstances()->exists()) {
            return false;
        }

        $account->delete();

        return true;
    }
}
