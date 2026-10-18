<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange\Signing;

use App\Domain\Exchange\Exceptions\ExchangeApiException;
use App\Domain\Exchange\Signing\OkxRequestSigner;
use Tests\TestCase;

/**
 * OKX 请求签名策略固定向量断言（T037，contracts/okx-api-contract.md）。
 *
 * prehash = timestamp + method + requestPath + body → base64(HMAC-SHA256)；
 * timestamp 为 ISO 8601 UTC（含毫秒）；headers 四件套 OK-ACCESS-*；
 * testnet=true 时附加 x-simulated-trading: 1（同域模拟盘标识，与 Bybit 独立域名机制不同）。
 */
class OkxRequestSignerTest extends TestCase
{
    private const CREDENTIALS = [
        'api_key' => 'test-key',
        'api_secret' => 'test-secret',
        'passphrase' => 'test-pass',
    ];

    private OkxRequestSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new OkxRequestSigner();
        config(['exchange.exchanges.okx.testnet' => false]);
    }

    public function test_get_request_signs_prehash_with_iso_timestamp(): void
    {
        // 1697443200000 ms → 2023-10-16T08:00:00.000Z
        $signed = $this->signer->signRequest(
            'GET',
            '/api/v5/account/balance',
            ['ccy' => 'USDT'],
            null,
            self::CREDENTIALS,
            1697443200000,
        );

        // GET requestPath 含 query，body 为空串
        $prehash = '2023-10-16T08:00:00.000Z'.'GET'.'/api/v5/account/balance?ccy=USDT'.'';
        $expectedSign = base64_encode(hash_hmac('sha256', $prehash, 'test-secret', true));

        static::assertSame($expectedSign, $signed->headers['OK-ACCESS-SIGN'], 'base64(HMAC-SHA256(prehash, secret))');
        static::assertSame('2023-10-16T08:00:00.000Z', $signed->headers['OK-ACCESS-TIMESTAMP'], 'ISO 8601 UTC');
        static::assertSame('test-key', $signed->headers['OK-ACCESS-KEY']);
        static::assertSame('test-pass', $signed->headers['OK-ACCESS-PASSPHRASE']);
    }

    public function test_headers_contains_ok_access_four_piece_set(): void
    {
        $signed = $this->signer->signRequest(
            'GET',
            '/api/v5/account/balance',
            [],
            null,
            self::CREDENTIALS,
            1697443200000,
        );

        foreach (['OK-ACCESS-KEY', 'OK-ACCESS-SIGN', 'OK-ACCESS-TIMESTAMP', 'OK-ACCESS-PASSPHRASE'] as $header) {
            static::assertArrayHasKey($header, $signed->headers);
        }
        static::assertArrayNotHasKey('x-simulated-trading', $signed->headers, 'testnet=false 无模拟盘头');
    }

    public function test_post_request_signs_raw_json_body(): void
    {
        $params = ['instId' => 'BTC-USDT', 'side' => 'buy', 'ordType' => 'market', 'sz' => '100'];
        $signed = $this->signer->signRequest(
            'POST',
            '/api/v5/trade/order',
            $params,
            null,
            self::CREDENTIALS,
            1697443200000,
        );

        // POST requestPath 不含 query，body = raw JSON
        $payload = json_encode($params, JSON_UNESCAPED_SLASHES);
        $prehash = '2023-10-16T08:00:00.000Z'.'POST'.'/api/v5/trade/order'.$payload;
        $expectedSign = base64_encode(hash_hmac('sha256', $prehash, 'test-secret', true));

        static::assertSame($payload, $signed->body, 'body 为 raw JSON，与签名 payload 逐字节一致');
        static::assertSame('application/json', $signed->contentType);
        static::assertNull($signed->query);
        static::assertSame($expectedSign, $signed->headers['OK-ACCESS-SIGN']);
    }

    public function test_testnet_adds_simulated_trading_header(): void
    {
        config(['exchange.exchanges.okx.testnet' => true]);

        $signed = $this->signer->signRequest(
            'POST',
            '/api/v5/trade/order',
            ['instId' => 'BTC-USDT'],
            null,
            self::CREDENTIALS,
            1697443200000,
        );

        static::assertSame('1', $signed->headers['x-simulated-trading'] ?? null, 'testnet=true 注入模拟盘头');
    }

    public function test_missing_passphrase_guard_throws_client_error(): void
    {
        foreach ([[], ['api_key' => 'k', 'api_secret' => 's'], ['api_key' => 'k', 'passphrase' => 'p']] as $credentials) {
            try {
                $this->signer->signRequest(
                    'GET',
                    '/api/v5/account/balance',
                    [],
                    null,
                    $credentials,
                    1697443200000,
                );
                static::fail('预期缺凭据段（含 passphrase）抛 ExchangeApiException（FR-007）');
            } catch (ExchangeApiException $e) {
                static::assertFalse($e->isRetryable(), '缺凭据为 clientError 硬失败');
            }
        }
    }
}
