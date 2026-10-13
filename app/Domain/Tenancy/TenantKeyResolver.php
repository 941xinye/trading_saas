<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * 租户密钥解析器。
 *
 * KEK：config('exchange.master_key')（hex 字符串 → 32 字节二进制），从 .env 注入。
 * DEK：每租户一个，AES-GCM 加密存 tenants.exchange_dek；
 *      首次访问时生成并加密落库（懒初始化）。
 *
 * 缓存策略：实例属性缓存（进程 B 长驻单例），避免每单解密；
 * 非 static 缓存，Octane 多 worker 各自独立，无跨租户污染。
 */
class TenantKeyResolver implements KeyResolver
{
    private ?string $masterKeyCache = null;

    /** @var array<int,string> tenantId → DEK 原始二进制（进程内缓存） */
    private array $dekCache = [];

    public function masterKey(): string
    {
        if ($this->masterKeyCache !== null) {
            return $this->masterKeyCache;
        }

        $hex = (string) config('exchange.master_key');
        if ($hex === '') {
            throw new RuntimeException('EXCHANGE_MASTER_KEY is not configured');
        }

        return $this->masterKeyCache = AesGcmCrypt::keyFromHex($hex);
    }

    public function tenantDek(int $tenantId): string
    {
        if (isset($this->dekCache[$tenantId])) {
            return $this->dekCache[$tenantId];
        }

        $crypt = new AesGcmCrypt($this); // encrypt/decrypt 不依赖 resolver 注入，无循环问题
        $row = DB::table('tenants')->where('id', $tenantId)->first(['exchange_dek']);
        if ($row === null) {
            throw new RuntimeException("Tenant {$tenantId} not found");
        }

        $stored = (string) ($row->exchange_dek ?? '');
        if ($stored === '') {
            // 懒初始化：生成 DEK → KEK 加密 → 落库
            $dek = AesGcmCrypt::generateKey();
            $stored = $crypt->encrypt($dek, $this->masterKey());
            DB::table('tenants')->where('id', $tenantId)->update([
                'exchange_dek' => $stored,
                'updated_at' => now(),
            ]);
        } else {
            try {
                $dek = $crypt->decrypt($stored, $this->masterKey());
            } catch (Throwable) {
                $dek = null;
            }
            if ($dek === null) {
                throw new RuntimeException(
                    "Failed to decrypt tenant DEK (tenant {$tenantId}). Check EXCHANGE_MASTER_KEY."
                );
            }
        }

        return $this->dekCache[$tenantId] = $dek;
    }
}
