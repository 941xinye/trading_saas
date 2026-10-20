<?php

declare(strict_types=1);

namespace App\Domain\Payment\Dto;

/**
 * 通道建单请求（amount 为 bcmath 字符串，decimal(20,8)）。
 */
final readonly class PaymentInvoiceRequest
{
    public function __construct(
        public int $tenantId,
        public string $ref,
        public string $planCode,
        public string $symbol,
        public string $chain,
        public string $amount,
        public string $description,
        public int $ttlMinutes,
    ) {}
}
