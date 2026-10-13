<?php

declare(strict_types=1);

namespace App\Domain\Risk\Rules;

use App\Domain\Risk\Breaker\BreakerMetrics;
use App\Domain\Risk\Contracts\RiskRule;

/**
 * 最大回撤 Rule。
 *
 * 触发条件：历史峰值 → 当前净值回撤 ≥ 阈值
 * 默认阈值：10%
 *
 * 数据来源：balance_snapshots + 实时净值
 * 自动恢复：false（回撤触发后需人工解锁，避免反复触发）
 */
class MaxDrawdownRule implements RiskRule
{
    public function __construct(
        private readonly string $thresholdPct = '10',
    ) {
    }

    public function check(BreakerMetrics $metrics): ?string
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }
}
