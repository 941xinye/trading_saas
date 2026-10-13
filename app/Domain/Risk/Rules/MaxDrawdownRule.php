<?php

declare(strict_types=1);

namespace App\Domain\Risk\Rules;

use App\Domain\Risk\Breaker\BreakerMetrics;
use App\Domain\Risk\Contracts\RiskRule;
use bccomp;

/**
 * 最大回撤 Rule。
 *
 * 触发条件：历史峰值 → 当前净值回撤 ≥ 阈值
 * 默认阈值：10%
 *
 * 数据来源：BreakerMetrics 峰值/净值追踪（P3 接入 balance_snapshots 对账）
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
        if (bccomp($metrics->getCurrentDrawdownPct(), $this->thresholdPct, 4) !== -1) {
            return 'max_drawdown';
        }

        return null;
    }
}
