<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Futures;

use App\Domain\Strategy\Base\AbstractStrategy;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\Contracts\ExchangeOrder;

/**
 * 智能马丁变体策略（反马丁思路）。
 *
 * 传统马丁：亏损后加倍下注，盈利后归零。
 * 反马丁特征（本实现）：
 *  - 盈利后减仓（profit_reduce_ratio），逐步兑现利润
 *  - 亏损后试探性加仓但限层（max_layers），避免雪崩
 *  - 多空双向均可触发，按趋势方向加仓
 *
 * 风险特征：aggressive；需配合熔断（max_drawdown 严格）。
 */
class SmartMartingaleStrategy extends AbstractStrategy
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
