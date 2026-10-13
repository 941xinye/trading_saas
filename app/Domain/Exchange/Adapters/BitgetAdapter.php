<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

/**
 * Bitget 适配器。
 *
 * REST: https://api.bitget.com
 * WS:   wss://ws.bitget.com/v2/ws/public
 *
 * 特点：跟单 API、V2 接口统一。
 * P6 扩展阶段实现。
 */
class BitgetAdapter extends AbstractExchangeAdapter
{
    protected function exchangeCode(): string
    {
        return 'bitget';
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
