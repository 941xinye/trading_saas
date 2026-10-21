<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * 熔断器三态枚举（Eloequent cast + DB 列值对齐）。
 *
 * CircuitBreaker 内部 Redis Hash 仍用裸 string 读写；
 * 本 enum 仅用于 strategy_instances.breaker_state 列的 Eloquent cast
 * 及代码中禁止裸字符串散落。
 */
enum CircuitBreakerState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function isHalfOpen(): bool
    {
        return $this === self::HalfOpen;
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }
}
