<?php

declare(strict_types=1);

namespace App\Domain\Risk\Rules;

use App\Domain\Risk\Breaker\BreakerMetrics;
use App\Domain\Risk\Contracts\RiskRule;
use bccomp;

/**
 * 日内最大亏损 Rule。
 *
 * 触发条件：当日累计已实现亏损 ≥ 阈值
 * 默认阈值：5% of allocated_capital
 *
 * 数据来源：RiskGuard.recordTrade 累计（启动时从 strategy_orders 当日聚合恢复）
 * 冷却窗口：1440 分钟（24 小时，跨日重置）
 */
class MaxDailyLossRule implements RiskRule
{
    public function __construct(
        private readonly string $thresholdPct = '5',
    ) {
    }

    public function check(BreakerMetrics $metrics): ?string
    {
        if (bccomp($metrics->getDailyLossPct(), $this->thresholdPct, 4) !== -1) {
            return 'max_daily_loss';
        }

        return null;
    }
}
