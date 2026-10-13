<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Contracts;

/**
 * 交易所账户余额快照 DTO。
 *
 * 与 balance_snapshots 表字段对齐。
 * assets 为多资产明细，结构如 ['USDT' => ['total' => '1000', 'available' => '800'], ...]
 */
readonly class ExchangeBalance
{
    /**
     * @param string $totalEquity      总权益（USDT 计价）
     * @param string $totalMargin      已用保证金
     * @param string $available        可用余额
     * @param string $unrealizedPnl    未实现盈亏
     * @param array<string,array<string,string>> $assets 多资产明细
     */
    public function __construct(
        public string $totalEquity,
        public string $totalMargin,
        public string $available,
        public string $unrealizedPnl,
        public array $assets,
    ) {
    }
}
