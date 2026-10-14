<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
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

    /**
     * 轮换租户 DEK（P3）：新 DEK（KEK 加密）→ 事务内逐行重加密 exchange_accounts
     * → 更新 tenants.exchange_dek → 抽验。任何一步失败整体回滚，旧 DEK 保持可用。
     *
     * 运维约定（POC）：轮换前暂停该租户策略实例 / 排空 orders 队列——长驻协程内存中
     * 持有解密凭据，重启后才会加载新 DEK。正式期再做版本化 DEK 热轮换。
     *
     * @return string 新 DEK 指纹（sha1，仅供识别，不含密钥材料）
     */
    public function rotateDek(int $tenantId): string
    {
        $lockKey = sprintf('tenant:%d:dek:rotation', $tenantId);
        $token = bin2hex(random_bytes(8));

        // 防并发轮换（120s 自动释放，兜底死锁）
        $locked = Redis::connection('default')->set($lockKey, $token, 'EX', 120, 'NX');
        if (! ($locked === true || $locked === 'OK' || $locked === 1)) {
            throw new RuntimeException("DEK rotation already in progress for tenant {$tenantId}");
        }

        try {
            $crypt = new AesGcmCrypt($this);
            $kek = $this->masterKey();

            $fingerprint = DB::transaction(function () use ($tenantId, $crypt, $kek) {
                $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
                if ($tenant === null) {
                    throw new RuntimeException("Tenant {$tenantId} not found");
                }

                $stored = (string) $tenant->exchange_dek;
                if ($stored === '') {
                    // 尚未懒初始化：直接走懒生成即可，无需轮换
                    $this->tenantDek($tenantId);
                    $stored = (string) DB::table('tenants')->where('id', $tenantId)->value('exchange_dek');
                }

                $oldDek = $crypt->decrypt($stored, $kek);
                if ($oldDek === null) {
                    throw new RuntimeException("Failed to decrypt current DEK for tenant {$tenantId}");
                }

                $newDek = AesGcmCrypt::generateKey();
                $newEncrypted = $crypt->encrypt($newDek, $kek);

                // 收集旧密文，用于事务内断言「旧密文全部消失」
                $oldCiphers = [];

                $accounts = DB::table('exchange_accounts')->where('tenant_id', $tenantId)->get();
                foreach ($accounts as $account) {
                    $updates = [];
                    foreach (['encrypted_api_key' => 'api_key', 'encrypted_api_secret' => 'api_secret'] as $column => $label) {
                        $cipher = (string) $account->{$column};
                        if ($cipher === '') {
                            continue;
                        }
                        $oldCiphers[] = $cipher;
                        $plain = $crypt->decrypt($cipher, $oldDek);
                        if ($plain === null) {
                            throw new RuntimeException(
                                "Failed to decrypt {$label} of account {$account->id} with old DEK"
                            );
                        }
                        $updates[$column] = $crypt->encrypt($plain, $newDek);
                    }
                    if ($updates !== []) {
                        DB::table('exchange_accounts')
                            ->where('id', $account->id)
                            ->update($updates + ['updated_at' => now()]);
                    }
                }

                DB::table('tenants')->where('id', $tenantId)->update([
                    'exchange_dek' => $newEncrypted,
                    'updated_at' => now(),
                ]);

                // 事务内抽验：新 DEK 能解密首行，且明文与旧 DEK 解密结果一致
                if ($accounts->isNotEmpty()) {
                    $first = DB::table('exchange_accounts')->where('id', $accounts[0]->id)->first();
                    if ((string) $first->encrypted_api_key !== '') {
                        $plainOld = $crypt->decrypt((string) $accounts[0]->encrypted_api_key, $oldDek);
                        $plainNew = $crypt->decrypt((string) $first->encrypted_api_key, $newDek);
                        if ($plainNew === null || $plainNew !== $plainOld) {
                            throw new RuntimeException('DEK rotation roundtrip verification failed');
                        }
                    }
                }
                // 旧密文不应再出现在该租户账户行
                foreach ($oldCiphers as $cipher) {
                    $leftover = DB::table('exchange_accounts')
                        ->where('tenant_id', $tenantId)
                        ->where(fn ($q) => $q->where('encrypted_api_key', $cipher)
                            ->orWhere('encrypted_api_secret', $cipher))
                        ->exists();
                    if ($leftover) {
                        throw new RuntimeException('Old ciphertext still present after DEK rotation');
                    }
                }

                return sha1($newDek);
            }, 3);

            // 进程内缓存失效，强制下次重新加载
            unset($this->dekCache[$tenantId]);

            return $fingerprint;
        } finally {
            $this->releaseLock($lockKey, $token);
        }
    }

    private function releaseLock(string $key, string $token): void
    {
        // 只释放自己持有的锁（Lua 比对 value，防止误删别人的锁）
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;
        try {
            Redis::connection('default')->eval($script, 1, $key, $token);
        } catch (Throwable) {
            // 锁有 TTL 兜底，释放失败不致命
        }
    }
}
