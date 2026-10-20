<?php

declare(strict_types=1);

namespace App\Domain\Payment\Dto;

/**
 * 支付单状态机（data-model.md §5）。
 *
 * 终态 = confirmed / expired / manual（只进不退）。
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Partial = 'partial';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case Manual = 'manual';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Confirmed, self::Expired, self::Manual], true);
    }
}
