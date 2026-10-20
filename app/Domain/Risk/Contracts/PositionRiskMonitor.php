<?php

declare(strict_types=1);

namespace App\Domain\Risk\Contracts;

use App\Domain\Exchange\Contracts\ExchangePosition;

/**
 * 仓位级风控监控器。
 *
 * 在 StrategyRunner onBar 回调中调用，评估当前仓位保证金率并执行必要动作
 * （预警通知 / 减仓 / 紧急全平）。
 *
 * 与账户级 RiskGuard 正交：仓位风控不影响熔断状态，熔断不影响仓位风控平仓。
 */
interface PositionRiskMonitor
{
    /**
     * 评估仓位风险。
     *
     * @param  ExchangePosition|null  $position  交易所持仓（无持仓为 null）
     * @param  string  $markPrice  当前标记价
     * @return PositionRiskDecision|null  有动作时返回决策，无动作返回 null
     */
    public function evaluate(
        int $tenantId,
        int $instanceId,
        string $symbol,
        ?ExchangePosition $position,
        string $markPrice,
    ): ?PositionRiskDecision;
}
