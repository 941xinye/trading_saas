<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Contracts;

use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Dto\SimulatedTrade;
use App\Domain\Exchange\Contracts\ExchangeKline;

/**
 * 模拟撮合器契约。
 *
 * 成交价格 = bar.close ± slippage_bps，手续费 = price × qty × fee_rate。
 * 纯函数：无副作用，不读写外部状态。
 */
interface SimulatedMatcher
{
    /**
     * 模拟单笔成交。
     *
     * @param array{0:string,1:string,2:?string} $decision [side, qty, price]
     * @throws \InvalidArgumentException qty <= 0 或不支持的 side
     */
    public function fill(array $decision, ExchangeKline $bar, MatchingAssumptions $assumptions): SimulatedTrade;
}
