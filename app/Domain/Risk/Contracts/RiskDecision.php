<?php

declare(strict_types=1);

namespace App\Domain\Risk\Contracts;

/**
 * 风控决策 DTO。
 *
 * allowed=false 时 reason 标识被哪个维度拦截（max_loss_per_trade|max_daily_loss|max_drawdown|consecutive_losses）。
 * breakerState 反映检查发生时的状态机快照，便于审计。
 */
readonly class RiskDecision
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public string $breakerState,
    ) {
    }
}
