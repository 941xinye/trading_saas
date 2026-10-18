<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange\Signing;

use App\Domain\Exchange\Exceptions\ExchangeApiException;
use App\Domain\Exchange\Signing\BitgetRequestSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bitget V2 签名器离线断言（R-002：头名已核实，prehash 结构候选待核）。
 *
 * prehash = timestamp(ms 串)+METHOD+requestPath(+?queryString GET)+body(POST raw JSON)
 * sign = base64(HMAC-SHA256(secret, prehash))——与 Binance hex/OKX ISO8601 ts/Bybit
 * timestamp+api_key+recv_window 均不同。
 */
class BitgetRequestSignerTest extends TestCase
{
    private const CREDENTIALS = [
        'api_key' => 'bg_key_123',
        'api_secret' => 'bg_secret_abc',
        'passphrase' => 'bg_pass',
    ];

    private BitgetRequestSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new BitgetRequestSigner();
    }

    public function test_signs_get_request_with_query_string_in_prehash(): void
    {
        $timestampMs = 1697443200000;

        $signed = $this->signer->signRequest(
            'GET',
            '/api/v2/spot/trade/orderInfo',
            ['symbol' => 'BTCUSDT', 'clientOid' => 'oid-1'],
            null,
            self::CREDENTIALS,
            $timestampMs,
        );

        // GET 通道：prehash requestPath 段拼 ?queryString（与实际发送串一致），body 恒空串
        $prehash = '1697443200000GET/api/v2/spot/trade/orderInfo?symbol=BTCUSDT&clientOid=oid-1';
        $expected = base64_encode(hash_hmac('sha256', $prehash, 'bg_secret_abc', true));

        // ACCESS-* 四件套（R-002 头名已核实）
        static::assertSame('bg_key_123', $signed->headers['ACCESS-KEY']);
        static::assertSame($expected, $signed->headers['ACCESS-SIGN'], 'HMAC-SHA256 → base64');
        static::assertSame('1697443200000', $signed->headers['ACCESS-TIMESTAMP'], '毫秒整数串（非 ISO8601）');
        static::assertSame('bg_pass', $signed->headers['ACCESS-PASSPHRASE']);
        // 无 testnet 附加头（决定 A / FR-013）
        static::assertArrayNotHasKey('X-SIMULATED-TRADING', $signed->headers);

        // GET 发送通道：query 拼接、body 空
        static::assertSame('symbol=BTCUSDT&clientOid=oid-1', $signed->query, 'query 与 prehash 段逐字节一致');
        static::assertNull($signed->body);
    }

    public function test_signs_post_request_with_raw_json_body(): void
    {
        $timestampMs = 1697443201000;

        $params = ['symbol' => 'BTCUSDT', 'side' => 'buy', 'orderType' => 'market', 'size' => '100'];

        $signed = $this->signer->signRequest(
            'POST',
            '/api/v2/spot/trade/place-order',
            $params,
            null,
            self::CREDENTIALS,
            $timestampMs,
        );

        $expectedBody = json_encode($params, JSON_UNESCAPED_SLASHES);

        // POST 通道：requestPath 无 query，body 段 = raw JSON（与实际发送串逐字节一致）
        $prehash = '1697443201000POST/api/v2/spot/trade/place-order'.$expectedBody;
        $expected = base64_encode(hash_hmac('sha256', $prehash, 'bg_secret_abc', true));

        static::assertSame($expected, $signed->headers['ACCESS-SIGN']);
        static::assertSame($expectedBody, $signed->body, '签名串=发送串');
        static::assertSame('application/json', $signed->contentType, 'POST Content-Type application/json');
        static::assertNull($signed->query);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function missingCredentialProvider(): array
    {
        return [
            '缺 api_key' => ['api_key'],
            '缺 api_secret' => ['api_secret'],
            '缺 passphrase' => ['passphrase'],
        ];
    }

    #[DataProvider('missingCredentialProvider')]
    public function test_missing_credential_segment_throws_client_error(string $missing): void
    {
        // 指定段置空（缺失/空串同判——FR-006 三段凭据任一段缺失前置抛）
        $credentials = self::CREDENTIALS;
        $credentials[$missing] = '';

        try {
            $this->signer->signRequest(
                'POST',
                '/api/v2/spot/trade/place-order',
                ['symbol' => 'BTCUSDT'],
                null,
                $credentials,
                1697443200000,
            );
            static::fail("预期缺失 {$missing} 抛 ExchangeApiException");
        } catch (ExchangeApiException $e) {
            static::assertFalse($e->isRetryable(), '凭据缺失为 clientError 硬失败（不占限流额度）');
        }
    }
}
