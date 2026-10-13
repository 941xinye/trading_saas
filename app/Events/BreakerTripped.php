<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 熔断器状态迁移事件（租户级）。
 *
 * 由 RiskGuard 在四维度规则（max_loss_per_trade / max_daily_loss /
 * max_drawdown / consecutive_losses）触发时产生，记录 strategy_instances.breaker_state
 * 状态机迁移：closed → open / open → half_open / half_open → closed|open。
 *
 * 监听器职责（POC 阶段填实）：
 *  - 写 circuit_breaker_events 审计表
 *  - 调度 DispatchNotificationJob（warning 级别推送）
 *  - Reverb 推 private-tenant.{tid}.alerts 频道
 *  - 暂停/恢复策略实例（strategy_instances.status ↔ breaker_state 联动）
 */
readonly class BreakerTripped
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $instanceId,
        public readonly string $fromState,
        public readonly string $toState,
        public readonly string $ruleType,
    ) {
    }
}
