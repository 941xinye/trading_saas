<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * AES-256-GCM 加密封装。
 *
 * 基于 libsodium 的 sodium_crypto_aead_aes256gcm_*，区别于 Laravel 默认的
 * Crypt::encryptString（AES-256-CBC + HMAC）。GCM 提供认证加密（AEAD），
 * 密文与 IV/Tag 绑定，篡改任何一部分都会解密失败。
 *
 * 用于加密租户的交易所 API Key/Secret：
 *  - 主密钥（KEK）：config('exchange.master_key')，从 .env 注入
 *  - 数据密钥（DEK）：每租户一个，加密存 tenants.exchange_dek
 *  - API Key：用租户 DEK 加密存 exchange_accounts.encrypted_api_*
 *
 * 输出格式：base64(nonce(12B) + ciphertext + tag(16B))
 */
final class AesGcmCrypt
{
    private const NONCE_BYTES = 12;       // 96-bit nonce（GCM 推荐）
    private const TAG_BYTES = 16;         // 128-bit auth tag

    public function __construct(
        private readonly KeyResolver $keyResolver,
    ) {
    }

    /**
     * 加密。
     *
     * @param string $plaintext 明文
     * @param string $key       32 字节原始密钥
     */
    public function encrypt(string $plaintext, string $key): string
    {
        $this->assertKey($key);
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_aes256gcm_encrypt(
            $plaintext,
            '', // additional data（可绑定 tenant_id/version，但暂未启用）
            $nonce,
            $key,
        );
        // 拼接 nonce + ciphertext（sodium 输出已含 tag）

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * 解密。
     *
     * @param string $payload base64 包裹的 nonce+ciphertext+tag
     * @param string $key     32 字节原始密钥
     *
     * @return string|null 失败返回 null（密钥/版本/篡改均可能失败）
     */
    public function decrypt(string $payload, string $key): ?string
    {
        $this->assertKey($key);
        $raw = base64_decode($payload, strict: true);
        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            return null;
        }
        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $ciphertextWithTag = substr($raw, self::NONCE_BYTES);

        $plaintext = sodium_crypto_aead_aes256gcm_decrypt(
            $ciphertextWithTag,
            '',
            $nonce,
            $key,
        );

        return is_string($plaintext) ? $plaintext : null;
    }

    /**
     * 生成新的 32 字节随机密钥（用于 DEK 轮换）。
     */
    public static function generateKey(): string
    {
        return sodium_crypto_aead_aes256gcm_keygen();
    }

    /**
     * 从 hex 字符串加载密钥（便于配置 .env）。
     */
    public static function keyFromHex(string $hex): string
    {
        $bin = @hex2bin($hex);
        if ($bin === false) {
            throw new RuntimeException('Invalid hex key');
        }

        return $bin;
    }

    private function assertKey(string $key): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES) {
            throw new RuntimeException(
                'AES-256-GCM key must be 32 bytes, got ' . strlen($key)
            );
        }
    }
}

/**
 * 密钥解析器：从配置/租户上下文解析 KEK/DEK。
 *
 * 实现走 DI，避免 static 缓存（Octane 长驻下脏数据）。
 */
interface KeyResolver
{
    /**
     * 获取主密钥 KEK（32 字节原始二进制）。
     */
    public function masterKey(): string;

    /**
     * 获取指定租户的 DEK（先解密 tenants.exchange_dek，5 分钟缓存）。
     */
    public function tenantDek(int $tenantId): string;
}
