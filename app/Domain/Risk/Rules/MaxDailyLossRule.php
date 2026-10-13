<?php

declare(strict_types=1);

namespace App\Domain\Risk\Rules;

use App\Domain\Risk\Breaker\BreakerMetrics;
use App\Domain\Risk\Contracts\RiskRule;

/**
 * 日内最大亏损 Rule。
 *
 * 触发条件：当日累计已实现亏损 ≥ 阈值
 * 默认阈值：5% of allocated_capital
 *
 * 数据来源：strategy_orders 当日聚合（已实现盈亏之和）
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
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }
}
