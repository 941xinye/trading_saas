<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Signing;

use App\Domain\Exchange\Exceptions\ExchangeApiException;

/**
 * Bybit V5 请求签名策略（R-002 + contracts/bybit-api-contract.md）。
 *
 * 规则（与 Binance/OKX 均不同）：
 *  - prehash = timestamp + api_key + recv_window + payload（含 api_key 与 recv_window 两段）
 *  - sign = bin2hex(HMAC-SHA256(prehash, secret))，输出 **hex 非 base64**
 *  - payload：GET = http_build_query(params)（与实际发送串逐字节一致）；
 *             POST/DELETE = raw JSON（json_encode，与实际发送串逐字节一致，"签名串=发送串"）
 *  - 签名放 **header** 不入 query/body：X-BAPI-* 五件套
 *    （API-KEY/SIGN/TIMESTAMP/RECV-WINDOW/SIGN-TYPE），POST Content-Type application/json
 *  - 两段凭据（api_key/api_secret），无 passphrase（FR-007）：缺段前置抛 clientError，
 *    由基类 doRequest 在限流前调用——前置失败不占额度（constitution V）
 *
 * 策略纯函数无状态，可独立单测（tests/Domain/Exchange/Signing/BybitRequestSignerTest.php）。
 */
final class BybitRequestSigner implements RequestSigner
{
    public function signRequest(
        string $method,
        string $path,
        array $params,
        ?string $body,
        array $credentials,
        int $timestampMs,
    ): SignedRequest {
        // 凭据两段守卫（FR-007）：无 passphrase，缺 api_key/api_secret 任一段即前置失败
        $apiKey = (string) ($credentials['api_key'] ?? '');
        $apiSecret = (string) ($credentials['api_secret'] ?? '');
        if ($apiKey === '' || $apiSecret === '') {
            throw ExchangeApiException::clientError(
                'bybit',
                $path,
                401,
                'api_key and api_secret are required for signed Bybit requests',
            );
        }

        $method = strtoupper($method);
        $recvWindow = (string) (int) config('exchange.recv_window', 5000);
        $timestamp = (string) $timestampMs;

        if ($method === 'GET') {
            // GET 通道：payload = queryString（签名放 header，query 原样发送）
            $payload = http_build_query($params);
            $query = $payload;
            $requestBody = null;
            $contentType = null;
        } else {
            // POST/DELETE 通道：payload = raw JSON body
            $payload = json_encode($params, JSON_UNESCAPED_SLASHES);
            $query = null;
            $requestBody = $payload;
            $contentType = 'application/json';
        }

        $prehash = $timestamp.$apiKey.$recvWindow.$payload;
        $sign = bin2hex(hash_hmac('sha256', $prehash, $apiSecret, true));

        return new SignedRequest(
            headers: [
                'X-BAPI-API-KEY' => $apiKey,
                'X-BAPI-SIGN' => $sign,
                'X-BAPI-TIMESTAMP' => $timestamp,
                'X-BAPI-RECV-WINDOW' => $recvWindow,
                'X-BAPI-SIGN-TYPE' => '2',
            ],
            query: $query,
            body: $requestBody,
            contentType: $contentType,
        );
    }
}
