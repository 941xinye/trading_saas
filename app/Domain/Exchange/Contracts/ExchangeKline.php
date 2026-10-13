<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Contracts;

/**
 * 交易所 K 线 DTO。
 *
 * 字段命名与 kline_bars 表对齐，便于直接持久化。
 * 时间戳为毫秒级（Binance/OKX 原生粒度）。
 */
readonly class ExchangeKline
{
    public function __construct(
        public string $exchangeCode,
        public string $symbol,
        public string $interval,
        public int $openTime,
        public int $closeTime,
        public string $open,
        public string $high,
        public string $low,
        public string $close,
        public string $volume,
        public string $quoteVolume,
        public int $tradesCount,
    ) {
    }
}
