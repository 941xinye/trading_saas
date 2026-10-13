<?php

declare(strict_types=1);

namespace App\Domain\Risk\Rules;

use App\Domain\Risk\Breaker\BreakerMetrics;
use App\Domain\Risk\Contracts\RiskRule;

/**
 * 连续亏损次数 Rule。
 *
 * 触发条件：连续亏损订单数 ≥ 阈值
 * 默认阈值：5 次
 *
 * 数据来源：strategy_orders 状态序列（按时间升序，最近 N 笔）
 * 冷却窗口：120 分钟
 */
class ConsecutiveLossRule implements RiskRule
{
    public function __construct(
        private readonly int $thresholdCount = 5,
    ) {
    }

    public function check(BreakerMetrics $metrics): ?string
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }
}
