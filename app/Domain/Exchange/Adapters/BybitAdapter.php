<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

/**
 * Bybit 适配器。
 *
 * REST: https://api.bybit.com
 * WS:   wss://stream.bybit.com/v5/public/linear
 *
 * 特点：V5 接口统一现货/合约、linear/inverse 合约。
 * P6 扩展阶段实现。
 */
class BybitAdapter extends AbstractExchangeAdapter
{
    protected function exchangeCode(): string
    {
        return 'bybit';
    }

    protected function market(): string
    {
        return 'spot';
    }

    public function withCredentials(array $credentials): static
    {
        return new static($this->rateLimiter, $this->healthChecker, $credentials);
    }
}
