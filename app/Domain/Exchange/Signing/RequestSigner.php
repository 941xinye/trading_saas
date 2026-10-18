<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Signing;

/**
 * 请求签名策略契约（FR-008，clarify Q1 + research R-009）。
 *
 * 策略是纯函数（无状态、可独立单测），负责把「交易所差异」内聚在一处：
 *  - Binance：queryString+signature 追加（hex HMAC），GET 走 query 通道 / POST 走 body 通道
 *  - OKX：prehash=timestamp+method+requestPath+body（base64 HMAC），OK-ACCESS-* 四件套 headers
 *  - Bybit：prehash=timestamp+api_key+recv_window+payload（hex HMAC），X-BAPI-* 五件套 headers
 *
 * 序列化必须确定性且与实际发送串逐字节一致（"签名串=发送串"，Binance -1022 坑）：
 * 策略产出的 query/body 就是基类 doRequest 实际发送的内容，不做二次编码。
 */
interface RequestSigner
{
    /**
     * 签名一个请求。
     *
     * @param  string  $method  HTTP 方法（GET/POST/DELETE，大写）
     * @param  string  $path  请求路径（如 /v5/order/create），不含 query
     * @param  array<string,mixed>  $params  请求参数（GET=query 数组；POST/DELETE=body 数据数组）
     * @param  string|null  $body  调用方已序列化的 raw body（POST/DELETE）；null 时策略从 $params 自行序列化
     * @param  array<string,string>  $credentials  凭据（api_key/api_secret，OKX 另含 passphrase）
     * @param  int  $timestampMs  本地毫秒时间戳（签名用）
     */
    public function signRequest(
        string $method,
        string $path,
        array $params,
        ?string $body,
        array $credentials,
        int $timestampMs,
    ): SignedRequest;
}
