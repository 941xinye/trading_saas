<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use PHPUnit\Framework\TestCase;

/**
 * AES-256-GCM 凭据加解密纯逻辑测试（支撑 P3 DEK 轮换链路）。
 *
 * default 组：不 bootstrap Laravel、无 DB/Redis/网络；KEK 用进程内固定桩注入。
 */
class AesGcmCryptTest extends TestCase
{
    private const KEK_HEX = 'ca865a76f76426728d5cc5e8f32db14cb67fb0dde77f8b109ad6b7596df0c8e3';

    private function crypt(string $hexKey = self::KEK_HEX): AesGcmCrypt
    {
        $key = AesGcmCrypt::keyFromHex($hexKey);
        $resolver = new class($key) implements KeyResolver
        {
            public function __construct(private readonly string $key) {}

            public function masterKey(): string
            {
                return $this->key;
            }

            public function tenantDek(int $tenantId): string
            {
                return $this->key;
            }
        };

        return new AesGcmCrypt($resolver);
    }

    public function test_encrypt_then_decrypt_roundtrips_plaintext(): void
    {
        $crypt = $this->crypt();

        foreach (['poc-key-A', 'poc-secret-A', '中文凭据🙂', str_repeat('x', 1000)] as $plain) {
            $cipher = $crypt->encrypt($plain, AesGcmCrypt::keyFromHex(self::KEK_HEX));

            static::assertNotSame($plain, $cipher);
            static::assertSame($plain, $crypt->decrypt($cipher, AesGcmCrypt::keyFromHex(self::KEK_HEX)));
        }
    }

    public function test_each_encryption_uses_fresh_nonce_and_differs(): void
    {
        $crypt = $this->crypt();
        $key = AesGcmCrypt::keyFromHex(self::KEK_HEX);

        static::assertNotSame($crypt->encrypt('same', $key), $crypt->encrypt('same', $key));
    }

    public function test_tampered_ciphertext_decrypts_to_null(): void
    {
        $crypt = $this->crypt();
        $key = AesGcmCrypt::keyFromHex(self::KEK_HEX);
        $cipher = $crypt->encrypt('secret', $key);

        // GCM 认证：翻转末位（tag 区）必须解密失败
        $tampered = substr($cipher, 0, -1).($cipher[strlen($cipher) - 1] === 'A' ? 'B' : 'A');

        static::assertNull($crypt->decrypt($tampered, $key));
    }

    public function test_wrong_key_decrypts_to_null(): void
    {
        $crypt = $this->crypt();
        $otherKey = AesGcmCrypt::keyFromHex(str_repeat('ab', 32));
        $cipher = $crypt->encrypt('secret', AesGcmCrypt::keyFromHex(self::KEK_HEX));

        static::assertNull($crypt->decrypt($cipher, $otherKey));
    }
}
