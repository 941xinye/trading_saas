<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Dto;

/**
 * 单笔模拟成交（不可变值对象）。
 *
 * 金额字段全 bcmath 字符串；seq 为 run 内顺序号（等价性对比锚点）。
 */
final readonly class SimulatedTrade
{
    public function __construct(
        public int $seq,
        /** UTC ms */
        public int $barOpenTime,
        /** buy|sell */
        public string $side,
        /** 成交数量（bcmath 字符串） */
        public string $qty,
        /** 含滑点成交价（bcmath 字符串） */
        public string $price,
        /** 本笔手续费（bcmath 字符串） */
        public string $fee,
        /** 平仓实现盈亏（开仓为 '0'） */
        public string $realizedPnl,
    ) {
    }
}
