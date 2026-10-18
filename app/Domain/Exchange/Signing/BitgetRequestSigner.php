<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Signing;

use App\Domain\Exchange\Exceptions\ExchangeApiException;

/**
 * Bitget V2 请求签名器（R-002，骨架镜像 OkxRequestSigner）。
 *
 * prehash = timestamp + METHOD + requestPath(+?queryString) + body
 *   - timestamp：UTC 毫秒整数串（与 ACCESS-TIMESTAMP 同值，与 OKX ISO8601 不同）
 *   - METHOD：大写（GET/POST）
 *   - requestPath：GET 带参拼 ?queryString（与实际发送串一致）；POST 同 path
 *   - body：GET 恒空串；POST = raw JSON（与实际发送串逐字节一致，「签名串=发送串」）
 * sign = base64_encode(hash_hmac('sha256', prehash, secret, raw_binary))——base64 非 hex
 *
 * Header 四件套：ACCESS-KEY / ACCESS-SIGN / ACCESS-TIMESTAMP / ACCESS-PASSPHRASE（头名已核实）；
 * POST Content-Type application/json；无 testnet 附加头（澄清决定 A / FR-013，与 OKX
 * x-simulated-trading、Bybit testnet 独立域名机制均不同）。
 *
 * 凭据三段守卫（FR-006）：api_key/api_secret/passphrase 任一段缺失/为空，签名前置抛
 * ExchangeApiException::clientError——基类在限流前调用签名器，前置失败不占限流额度（constitution V）。
 */
final class BitgetRequestSigner implements RequestSigner
{
    public function signRequest(
        string $method,
        string $path,
        array $params,
        ?string $body,
        array $credentials,
        int $timestampMs,
    ): SignedRequest {
        $apiKey = (string) ($credentials['api_key'] ?? '');
        $apiSecret = (string) ($credentials['api_secret'] ?? '');
        $passphrase = (string) ($credentials['passphrase'] ?? '');

        if ($apiKey === '' || $apiSecret === '' || $passphrase === '') {
            throw ExchangeApiException::clientError(
                'bitget',
                $path,
                401,
                'api_key, api_secret and passphrase are required for signed Bitget requests',
            );
        }

        $method = strtoupper($method);
        $timestamp = (string) $timestampMs; // 毫秒整数串（R-002）

        if ($method === 'GET') {
            // GET 通道：prehash requestPath 段拼 ?queryString（与实际发送串一致），body 恒空串
            $queryString = $params === [] ? '' : http_build_query($params);
            $requestPath = $queryString === '' ? $path : $path.'?'.$queryString;
            $payload = '';
            $query = $queryString === '' ? null : $queryString;
            $requestBody = null;
            $contentType = null;
        } else {
            // POST 通道：requestPath 同 path，body = raw JSON（与实际发送串逐字节一致）
            $requestPath = $path;
            $payload = $params === [] ? '' : json_encode($params, JSON_UNESCAPED_SLASHES);
            $query = null;
            $requestBody = $payload === '' ? null : $payload;
            $contentType = $requestBody !== null ? 'application/json' : null;
        }

        // prehash = timestamp(ms 串) + METHOD + requestPath(+?queryString) + body
        $prehash = $timestamp.$method.$requestPath.$payload;
        $sign = base64_encode(hash_hmac('sha256', $prehash, $apiSecret, true));

        // ACCESS-* 四件套（POST Content-Type 由基类 withBody(body, contentType) 设置）
        $headers = [
            'ACCESS-KEY' => $apiKey,
            'ACCESS-SIGN' => $sign,
            'ACCESS-TIMESTAMP' => $timestamp,
            'ACCESS-PASSPHRASE' => $passphrase,
        ];

        return new SignedRequest(
            headers: $headers,
            query: $query,
            body: $requestBody,
            contentType: $contentType,
        );
    }
}
