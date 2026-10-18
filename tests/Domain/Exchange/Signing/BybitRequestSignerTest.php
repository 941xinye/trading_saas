<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange\Signing;

use App\Domain\Exchange\Exceptions\ExchangeApiException;
use App\Domain\Exchange\Signing\BybitRequestSigner;
use Tests\TestCase;

/**
 * Bybit V5 签名策略固定向量断言（T022，R-002 + contracts/bybit-api-contract.md）。
 *
 * prehash = timestamp + api_key + recv_window + payload → hex(HMAC-SHA256)；
 * GET 走 query 通道（签名放 header，query 原样）；POST 走 raw JSON body 通道；
 * headers 五件套 X-BAPI-*。与 Binance（hex 但 signature 入串）、OKX（base64）均不同。
 */
class BybitRequestSignerTest extends TestCase
{
    private const TIMESTAMP = 1697443200000;

    private const CREDENTIALS = [
        'api_key' => 'test-key',
        'api_secret' => 'test-secret',
    ];

    private BybitRequestSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new BybitRequestSigner();
    }

    public function test_get_request_signs_query_channel_with_hex_hmac(): void
    {
        $signed = $this->signer->signRequest(
            'GET',
            '/v5/account/wallet-balance',
            ['accountType' => 'UNIFIED'],
            null,
            self::CREDENTIALS,
            self::TIMESTAMP,
        );

        // prehash = timestamp + api_key + recv_window + payload（payload=queryString）
        $prehash = '1697443200000'
            .'test-key'
            .'5000'
            .http_build_query(['accountType' => 'UNIFIED']);
        $expectedSign = bin2hex(hash_hmac('sha256', $prehash, 'test-secret', true));

        static::assertSame($expectedSign, $signed->headers['X-BAPI-SIGN'], 'hex(HMAC-SHA256(prehash, secret))');
        static::assertSame($expectedSign, hash_hmac('sha256', $prehash, 'test-secret', false), '输出为 hex 非 base64');
    }

    public function test_headers_contains_bapi_five_piece_set(): void
    {
        $signed = $this->signer->signRequest(
            'GET',
            '/v5/account/wallet-balance',
            ['accountType' => 'UNIFIED'],
            null,
            self::CREDENTIALS,
            self::TIMESTAMP,
        );

        static::assertSame('test-key', $signed->headers['X-BAPI-API-KEY']);
        static::assertSame('1697443200000', $signed->headers['X-BAPI-TIMESTAMP'], '毫秒字符串');
        static::assertSame('5000', $signed->headers['X-BAPI-RECV-WINDOW']);
        static::assertSame('2', $signed->headers['X-BAPI-SIGN-TYPE']);
        static::assertArrayHasKey('X-BAPI-SIGN', $signed->headers);
        static::assertCount(5, $signed->headers, '五件套齐备');
    }

    public function test_get_request_uses_query_channel_without_signature_in_url(): void
    {
        $params = ['accountType' => 'UNIFIED', 'coin' => 'USDT'];
        $signed = $this->signer->signRequest(
            'GET',
            '/v5/account/wallet-balance',
            $params,
            null,
            self::CREDENTIALS,
            self::TIMESTAMP,
        );

        // 签名放 header：query 原样（无 signature 追加，与 Binance 根本不同）
        static::assertSame(http_build_query($params), $signed->query);
        static::assertStringNotContainsString('signature', (string) $signed->query);
        static::assertNull($signed->body);
        static::assertNull($signed->contentType);
    }

    public function test_post_request_signs_raw_json_body_channel(): void
    {
        $params = [
            'category' => 'spot',
            'symbol' => 'BTCUSDT',
            'side' => 'Buy',
            'orderType' => 'Market',
            'qty' => '100',
        ];
        $signed = $this->signer->signRequest(
            'POST',
            '/v5/order/create',
            $params,
            null,
            self::CREDENTIALS,
            self::TIMESTAMP,
        );

        // body = raw JSON（签名串=发送串）
        $payload = json_encode($params, JSON_UNESCAPED_SLASHES);
        $prehash = '1697443200000'.'test-key'.'5000'.$payload;
        $expectedSign = bin2hex(hash_hmac('sha256', $prehash, 'test-secret', true));

        static::assertSame($payload, $signed->body, 'body 为 raw JSON，与签名 payload 逐字节一致');
        static::assertSame('application/json', $signed->contentType);
        static::assertNull($signed->query);
        static::assertSame($expectedSign, $signed->headers['X-BAPI-SIGN']);
    }

    public function test_missing_credentials_guard_throws_client_error(): void
    {
        foreach ([[], ['api_key' => 'k'], ['api_secret' => 's']] as $credentials) {
            try {
                $this->signer->signRequest(
                    'GET',
                    '/v5/account/wallet-balance',
                    ['accountType' => 'UNIFIED'],
                    null,
                    $credentials,
                    self::TIMESTAMP,
                );
                static::fail('预期缺凭据段抛 ExchangeApiException（FR-007 两段守卫）');
            } catch (ExchangeApiException $e) {
                static::assertFalse($e->isRetryable(), '缺凭据为 clientError 硬失败');
            }
        }
    }
}
