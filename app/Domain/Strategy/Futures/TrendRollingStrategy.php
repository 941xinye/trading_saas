<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Futures;

use App\Domain\Strategy\Base\AbstractStrategy;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\Contracts\ExchangeOrder;

/**
 * 滚仓宝/子弹策略。
 *
 * 核心机制：趋势确认 → 初始仓位 → 浮盈加仓 → 趋势反转止盈。
 *  - 趋势判断：trend_interval 周期（默认 4h）+ trend_indicator（ema_cross）
 *  - 浮盈加仓：浮盈达到 add_position_threshold（2%）时追加仓位
 *  - 最大滚仓次数：max_rolls（5），避免无限加仓
 *
 * 风险特征：aggressive；适合单边大趋势行情。
 */
class TrendRollingStrategy extends AbstractStrategy
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
