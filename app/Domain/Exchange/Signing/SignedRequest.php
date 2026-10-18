<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Signing;

/**
 * 签名请求产出（value object，research R-009）。
 *
 * 双通道设计覆盖三家差异：
 *  - GET 签名请求：策略产出 query（已含全部签名参数的完整 queryString），
 *    基类拼 base+path+'?'+query 发送（Binance signature / Bybit / OKX requestPath 同理）。
 *  - POST/DELETE 签名请求：策略产出 body（raw 串）+ contentType，
 *    基类 withBody(body, contentType) 发送。
 *  - headers 恒由策略产出（X-MBX-APIKEY / OK-ACCESS-* / X-BAPI-*）。
 */
final class SignedRequest
{
    /**
     * @param  array<string,string>  $headers  全部签名请求头
     * @param  string|null  $query  含签名参数的完整 queryString（GET 通道）
     * @param  string|null  $body  raw body 串（POST/DELETE 通道）
     * @param  string|null  $contentType  body 通道的 Content-Type
     */
    public function __construct(
        public readonly array $headers,
        public readonly ?string $query = null,
        public readonly ?string $body = null,
        public readonly ?string $contentType = null,
    ) {
    }
}
