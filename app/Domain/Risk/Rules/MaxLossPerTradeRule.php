<?php

declare(strict_types=1);

namespace App\Domain\Risk\Rules;

use App\Domain\Risk\Breaker\BreakerMetrics;
use App\Domain\Risk\Contracts\RiskRule;

/**
 * 单笔最大亏损 Rule。
 *
 * 触发条件：单笔成交已实现亏损 ≥ 阈值
 * 默认阈值：2% of allocated_capital
 * 阈值来源：config/risk.php defaults.max_loss_per_trade 或 risk_rules 表覆盖
 *
 * 数据来源：onFill 回调 → BreakerMetrics.recordTrade → lastTradeLossPct
 */
class MaxLossPerTradeRule implements RiskRule
{
    public function __construct(
        private readonly string $thresholdPct = '2',
    ) {
    }

    public function check(BreakerMetrics $metrics): ?string
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }
}
