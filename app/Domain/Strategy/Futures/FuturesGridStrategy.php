<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Futures;

use App\Domain\Strategy\Base\AbstractStrategy;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\Contracts\ExchangeOrder;

/**
 * 合约网格策略。
 *
 * 在 [lower_price, upper_price] 区间布 grid_count 个网格，
 * 每网格保证金 = margin / grid_count，使用 leverage 倍杠杆。
 *
 * 与现货网格差异：
 *  - 可做空 + 做多
 *  - 持仓概念真实存在（合约持仓）
 *  - 触发爆仓价需要监控（liquidationPrice）
 *
 * 风险特征：balanced。
 */
class FuturesGridStrategy extends AbstractStrategy
{
    public function onTick(ExchangeTicker $ticker): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function onBar(ExchangeKline $bar): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function onFill(ExchangeOrder $order): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function shouldOpen(StrategyContext $ctx): ?array
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function shouldClose(StrategyContext $ctx): ?array
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }
}
