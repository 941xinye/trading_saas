<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange\Signing;

use App\Domain\Exchange\Signing\BinanceRequestSigner;
use Tests\TestCase;

/**
 * Binance 请求签名策略逐字节回归断言（T038，SC-006 迁移零变化）。
 *
 * 行为与迁移前基类签名路径一致：params+timestamp+recvWindow → ksort →
 * http_build_query → serialized & signature=hex(HMAC-SHA256)；
 * GET 走 query 通道，POST/DELETE 走 form-urlencoded body 通道，头 X-MBX-APIKEY。
 */
class BinanceRequestSignerTest extends TestCase
{
    private const TIMESTAMP = 1697443200000;

    private const CREDENTIALS = [
        'api_key' => 'test-key',
        'api_secret' => 'test-secret',
    ];

    private BinanceRequestSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new BinanceRequestSigner();
    }

    public function test_get_request_appends_signature_to_query(): void
    {
        $signed = $this->signer->signRequest(
            'GET',
            '/api/v3/account',
            ['symbol' => 'BTCUSDT', 'limit' => 10],
            null,
            self::CREDENTIALS,
            self::TIMESTAMP,
        );

        // 迁移前行为：params+timestamp+recvWindow → ksort → http_build_query → &signature=
        $params = ['symbol' => 'BTCUSDT', 'limit' => 10, 'timestamp' => self::TIMESTAMP, 'recvWindow' => 5000];
        ksort($params);
        $serialized = http_build_query($params);
        $expected = $serialized.'&signature='.hash_hmac('sha256', $serialized, 'test-secret');

        static::assertSame($expected, $signed->query, 'GET query 通道：签名串=发送串（含 &signature= 尾）');
        static::assertStringEndsWith('&signature=', substr($expected, 0, -64), '签名以 &signature= 追加');
        static::assertNull($signed->body);
    }

    public function test_headers_contains_mbx_apikey(): void
    {
        $signed = $this->signer->signRequest(
            'GET',
            '/api/v3/account',
            [],
            null,
            self::CREDENTIALS,
            self::TIMESTAMP,
        );

        static::assertSame('test-key', $signed->headers['X-MBX-APIKEY']);
        static::assertCount(1, $signed->headers);
    }

    public function test_post_request_uses_form_urlencoded_body_channel(): void
    {
        $params = ['symbol' => 'BTCUSDT', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => '0.001'];
        $signed = $this->signer->signRequest(
            'POST',
            '/api/v3/order',
            $params,
            null,
            self::CREDENTIALS,
            self::TIMESTAMP,
        );

        $params['timestamp'] = self::TIMESTAMP;
        $params['recvWindow'] = 5000;
        ksort($params);
        $serialized = http_build_query($params);
        $expected = $serialized.'&signature='.hash_hmac('sha256', $serialized, 'test-secret');

        static::assertSame($expected, $signed->body, 'POST body 通道：form-urlencoded 签名串');
        static::assertSame('application/x-www-form-urlencoded', $signed->contentType);
        static::assertNull($signed->query);
    }

    public function test_params_are_ksort_deterministic(): void
    {
        // 乱序入参 → ksort 确定性序列化（"签名串=发送串"坑）
        $signed = $this->signer->signRequest(
            'GET',
            '/api/v3/account',
            ['limit' => 10, 'symbol' => 'BTCUSDT'],
            null,
            self::CREDENTIALS,
            self::TIMESTAMP,
        );

        $ordered = $this->signer->signRequest(
            'GET',
            '/api/v3/account',
            ['symbol' => 'BTCUSDT', 'limit' => 10],
            null,
            self::CREDENTIALS,
            self::TIMESTAMP,
        );

        static::assertSame($ordered->query, $signed->query, 'ksort 确定性：入参顺序不影响签名串');
    }
}
