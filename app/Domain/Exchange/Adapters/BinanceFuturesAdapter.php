<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

/**
 * 币安合约（U 本位永续）适配器。
 *
 * REST: https://fapi.binance.com
 * WS:   wss://fstream.binance.com/ws
 *
 * 含杠杆、持仓、强平流（!forceOrder@arr）支持。
 * P1 POC 落地：合约下单/撤单/持仓 + 风控前置 + WS 重连。
 */
class BinanceFuturesAdapter extends AbstractExchangeAdapter
{
    protected function exchangeCode(): string
    {
        return 'binance';
    }
}
