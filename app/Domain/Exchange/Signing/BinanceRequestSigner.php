<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Signing;

/**
 * Binance 请求签名策略（FR-008 迁移，行为与原基类签名路径逐字节一致）。
 *
 * 规则（contracts/binance-api-contract 范式）：
 *  - 参数追加 timestamp（毫秒）+ recvWindow，按 key 升序 http_build_query（确定性序列化）
 *  - signature = hex(HMAC-SHA256(serialized, apiSecret))，以 `&signature=` 追加到串尾
 *  - GET：完整串作为 query 通道（拼到 URL）；POST/DELETE：完整串作为 form-urlencoded body
 *  - 请求头 X-MBX-APIKEY（Content-Type 由 body 通道 withBody 设置）
 */
final class BinanceRequestSigner implements RequestSigner
{
    public function signRequest(
        string $method,
        string $path,
        array $params,
        ?string $body,
        array $credentials,
        int $timestampMs,
    ): SignedRequest {
        $params['timestamp'] = $timestampMs;
        $params['recvWindow'] = (int) config('exchange.recv_window', 5000);

        // 签名串=发送串：ksort + http_build_query 的确定性顺序，与原基类 buildQuery 一致
        ksort($params);
        $serialized = http_build_query($params);
        $signed = $serialized.'&signature='.hash_hmac('sha256', $serialized, (string) ($credentials['api_secret'] ?? ''));

        $headers = [];
        if (! empty($credentials['api_key'])) {
            $headers['X-MBX-APIKEY'] = (string) $credentials['api_key'];
        }

        if (strtoupper($method) === 'GET') {
            return new SignedRequest(headers: $headers, query: $signed);
        }

        return new SignedRequest(
            headers: $headers,
            body: $signed,
            contentType: 'application/x-www-form-urlencoded',
        );
    }
}
