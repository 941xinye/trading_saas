<?php

declare(strict_types=1);

namespace App\Domain\Risk\Contracts;

/**
 * 仓位风控决策 DTO。
 */
readonly class PositionRiskDecision
{
    public function __construct(
        public string $actionType,     // warning|reduce|emergency_close|skipped
        public string $reason,         // margin_ratio_warning|margin_ratio_action|margin_ratio_emergency|lock_contended
        public string $marginRatio,    // 触发时保证金率
        public ?string $orderQty = null,    // 减仓/全平数量
        public ?bool $reduceOnly = null,    // 减仓单标记
    ) {
    }
}
