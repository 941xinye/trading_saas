<?php

declare(strict_types=1);

namespace App\Domain\Payment\Dto;

/**
 * 规范化 webhook 事件（纯解析产物，不含状态迁移）。
 */
final readonly class WebhookEvent
{
    public function __construct(
        public string $eventType,
        public ?string $ref,
        public ?string $channelInvoiceId,
        public ?string $paidAmount,
        public ?string $currency,
        public array $raw,
    ) {}
}
