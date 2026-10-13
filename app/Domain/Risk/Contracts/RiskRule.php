<?php

declare(strict_types=1);

namespace App\Domain\Risk\Contracts;

use App\Domain\Risk\Breaker\BreakerMetrics;

/**
 * 风控规则接口。
 *
 * 实现 4 维度具体判断逻辑，由 RiskGuard 编排执行。
 * check() 返回触发的 rule_type 字符串（如 'max_loss_per_trade'），未触发返回 null。
 */
interface RiskRule
{
    /**
     * 检查是否触发熔断。
     *
     * @param BreakerMetrics $metrics 当前实例的 4 维度指标快照
     *
     * @return string|null 触发的 rule_type（max_loss_per_trade|max_daily_loss|max_drawdown|consecutive_losses），未触发返回 null
     */
    public function check(BreakerMetrics $metrics): ?string;
}
