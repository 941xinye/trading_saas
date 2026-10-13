<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

/**
 * OKX 适配器。
 *
 * REST: https://www.okx.com
 * WS:   wss://ws.okx.com:8443/ws/v5/public
 *
 * 特点：passphrase 鉴权、统一账户模式、SWAP（永续）合约。
 * P6 扩展阶段实现。
 */
class OkxAdapter extends AbstractExchangeAdapter
{
    protected function exchangeCode(): string
    {
        return 'okx';
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
