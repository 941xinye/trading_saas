<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

/**
 * 币安现货适配器。
 *
 * REST: https://api.binance.com
 * WS:   wss://stream.binance.com:9443/stream
 *
 * 现货场景无持仓概念，getPosition() 永远返回 null。
 * P1 POC 落地：签名、限流、K 线订阅、下单闭环。
 */
class BinanceSpotAdapter extends AbstractExchangeAdapter
{
    protected function exchangeCode(): string
    {
        return 'binance';
    }
}
