<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Contracts;

/**
 * 交易所订单 DTO。
 *
 * 与 strategy_orders 表字段一一对应，便于直接落库。
 * 状态枚举：pending|submitted|partial|filled|canceled|rejected|failed
 */
readonly class ExchangeOrder
{
    public function __construct(
        public string $exchangeOrderId,
        public string $clientOrderId,
        public string $symbol,
        public string $side,
        public string $type,
        public string $quantity,
        public ?string $price,
        public ?string $stopPrice,
        public ?int $leverage,
        public bool $reduceOnly,
        public string $status,
        public string $filledQty,
        public ?string $avgFillPrice,
        public ?string $fee,
        public ?int $placedAt,
        public ?int $filledAt,
    ) {
    }
}
