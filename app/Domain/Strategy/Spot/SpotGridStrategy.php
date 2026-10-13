<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Spot;

use App\Domain\Strategy\Base\AbstractStrategy;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\Contracts\ExchangeOrder;

/**
 * 现货网格策略。
 *
 * 在 [lower_price, upper_price] 区间均匀布 grid_count 个网格：
 *  - 价格触及网格下沿 → 买入
 *  - 价格触及网格上沿 → 卖出
 *  - 资金分配：investment_amount / grid_count
 *
 * 风险特征：conservative；适合震荡市。
 * P1 POC 落地核心闭环。
 */
class SpotGridStrategy extends AbstractStrategy
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
