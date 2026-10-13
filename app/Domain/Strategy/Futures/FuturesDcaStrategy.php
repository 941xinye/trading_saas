<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Futures;

use App\Domain\Strategy\Base\AbstractStrategy;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\Contracts\ExchangeOrder;

/**
 * 合约 DCA 策略。
 *
 * 与现货 DCA 类似的定额定期买入，叠加 leverage 倍杠杆放大敞口。
 *
 * 风险特征：balanced。
 */
class FuturesDcaStrategy extends AbstractStrategy
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
