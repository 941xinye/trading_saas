<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Contracts;

/**
 * 交易所 Ticker DTO。
 *
 * 字符串保留精度（避免浮点误差），上层按需 bcmath 转换。
 */
readonly class ExchangeTicker
{
    public function __construct(
        public string $symbol,
        public string $lastPrice,
        public string $bidPrice,
        public string $askPrice,
        public string $volume24h,
        public string $changePct24h,
        public int $timestamp,
    ) {
    }
}
