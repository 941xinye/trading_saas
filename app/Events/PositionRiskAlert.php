<?php

declare(strict_types=1);

namespace App\Events;

/**
 * 仓位风控告警事件（spec 005 US2/US3）。
 *
 * 当仓位保证金率触及预警/行动/紧急阈值时分发，
 * 由 NotifyPositionRiskAlert Listener 走通知链（log/telegram/wechat_work/mail/websocket）。
 */
readonly class PositionRiskAlert
{
    public function __construct(
        public int $tenantId,
        public int $instanceId,
        public string $symbol,
        public string $actionType,      // warning|reduce|emergency_close
        public string $marginRatio,
        public string $markPrice,
        public ?string $liquidationPrice,
    ) {
    }
}
