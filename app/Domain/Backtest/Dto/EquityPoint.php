<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Dto;

/**
 * 净值曲线点（不可变值对象）。
 */
final readonly class EquityPoint
{
    public function __construct(
        /** UTC ms */
        public int $barTime,
        /** 净值（bcmath 字符串） */
        public string $equity,
    ) {
    }
}
