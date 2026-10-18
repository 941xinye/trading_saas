<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Signing;

use App\Domain\Exchange\Exceptions\ExchangeApiException;

/**
 * OKX 请求签名策略（contracts/okx-api-contract.md，自 OkxAdapter::doRequest override 平移）。
 *
 * 规则（与 Binance/Bybit 均不同）：
 *  - prehash = timestamp + method + requestPath + body（4 段拼接）
 *  - timestamp：ISO 8601 UTC 含毫秒（gmdate 'Y-m-d\TH:i:s.v\Z'，非毫秒整数）
 *  - requestPath：GET 拼 ?http_build_query（无参则同 path）；POST/DELETE 同 path
 *  - payload：GET 恒空串；POST/DELETE = raw JSON（json_encode，与实际发送串逐字节一致）
 *  - sign = base64_encode(HMAC-SHA256(prehash, secret, raw_binary))，输出 **base64 非 hex**
 *  - headers：OK-ACCESS-KEY/SIGN/TIMESTAMP/PASSPHRASE 四件套 + Content-Type application/json；
 *    testnet=true 附加 x-simulated-trading: 1（同域模拟盘标识）
 *  - 三段凭据（api_key/api_secret/passphrase，FR-007）：缺段前置抛 clientError，
 *    由基类 doRequest 在限流前调用——前置失败不占额度（constitution V）
 *
 * 策略纯函数无状态，可独立单测（tests/Domain/Exchange/Signing/OkxRequestSignerTest.php）。
 */
final class OkxRequestSigner implements RequestSigner
{
    public function signRequest(
        string $method,
        string $path,
        array $params,
        ?string $body,
        array $credentials,
        int $timestampMs,
    ): SignedRequest {
        // 三段凭据守卫（FR-007）：OKX 比 Bybit/Binance 多 passphrase 一段
        $apiKey = (string) ($credentials['api_key'] ?? '');
        $apiSecret = (string) ($credentials['api_secret'] ?? '');
        $passphrase = (string) ($credentials['passphrase'] ?? '');
        if ($apiKey === '' || $apiSecret === '' || $passphrase === '') {
            throw ExchangeApiException::clientError(
                'okx',
                $path,
                401,
                'api_key, api_secret and passphrase are required for signed OKX requests',
            );
        }

        $method = strtoupper($method);
        $timestamp = gmdate('Y-m-d\TH:i:s', intdiv($timestampMs, 1000))
            .'.'.sprintf('%03d', $timestampMs % 1000).'Z';

        if ($method === 'GET') {
            // GET 通道：requestPath 含 ?query（无参同 path），payload 恒空串
            $queryString = $params === [] ? '' : http_build_query($params);
            $requestPath = $queryString === '' ? $path : $path.'?'.$queryString;
            $payload = '';
            $query = $queryString === '' ? null : $queryString;
            $requestBody = null;
        } else {
            // POST/DELETE 通道：requestPath 同 path，payload = raw JSON（空参数则空串）
            $requestPath = $path;
            $payload = $params === [] ? '' : json_encode($params, JSON_UNESCAPED_SLASHES);
            $query = null;
            $requestBody = $payload === '' ? null : $payload;
        }

        $prehash = $timestamp.$method.$requestPath.$payload;
        $sign = base64_encode(hash_hmac('sha256', $prehash, $apiSecret, true));

        $headers = [
            'Content-Type' => 'application/json',
            'OK-ACCESS-KEY' => $apiKey,
            'OK-ACCESS-SIGN' => $sign,
            'OK-ACCESS-TIMESTAMP' => $timestamp,
            'OK-ACCESS-PASSPHRASE' => $passphrase,
        ];

        // 模拟盘同域标识（与 Bybit 独立域名机制根本不同，R-001）
        if ((bool) config('exchange.exchanges.okx.testnet', false)) {
            $headers['x-simulated-trading'] = '1';
        }

        return new SignedRequest(
            headers: $headers,
            query: $query,
            body: $requestBody,
            contentType: $requestBody !== null ? 'application/json' : null,
        );
    }
}
