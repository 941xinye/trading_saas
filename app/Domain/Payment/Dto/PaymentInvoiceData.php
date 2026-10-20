<?php

declare(strict_types=1);

namespace App\Domain\Payment\Dto;

/**
 * 通道建单应答（amount 为通道侧金额复核值，MUST 与请求一致否则拒建单）。
 */
final readonly class PaymentInvoiceData
{
    public function __construct(
        public string $channelInvoiceId,
        public string $payAddress,
        public ?string $payUri,
        public string $amount,
        public ?string $expiresAt,
        public array $raw = [],
    ) {}
}
