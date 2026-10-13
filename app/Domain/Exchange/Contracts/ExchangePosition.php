<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Contracts;

/**
 * 交易所持仓 DTO（合约场景）。
 *
 * 与 positions 表字段对齐，side 枚举：long|short。
 */
readonly class ExchangePosition
{
    public function __construct(
        public string $symbol,
        public string $side,
        public string $size,
        public string $entryPrice,
        public string $markPrice,
        public ?string $liquidationPrice,
        public int $leverage,
        public string $unrealizedPnl,
        public string $margin,
    ) {
    }
}
