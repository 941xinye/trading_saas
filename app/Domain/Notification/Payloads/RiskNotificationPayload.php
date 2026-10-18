<?php

declare(strict_types=1);

namespace App\Domain\Notification\Payloads;

use App\Domain\Notification\Contracts\NotificationPayload;

/**
 * 风控/对账通知载荷工厂：把 risk_notifications 行装配成通用 NotificationPayload。
 *
 * circuit_breaker_event_id 非空 → 熔断告警；为 null → 账实对账告警（P5.5 复用通知链）。
 */
final class RiskNotificationPayload
{
    public static function fromRow(object $row): NotificationPayload
    {
        $eventId = $row->circuit_breaker_event_id !== null
            ? (int) $row->circuit_breaker_event_id
            : null;

        return new NotificationPayload(
            title: $eventId !== null ? '⚠️ 熔断告警' : '🚨 账实对账告警',
            fields: [
                '实例' => '#'.(int) $row->instance_id,
                '事件' => $eventId !== null ? '#'.$eventId : '-',
            ],
            footer: gmdate('Y-m-d H:i').' UTC',
            context: [
                'notification_id' => (int) $row->id,
                'tenant_id' => (int) $row->tenant_id,
                'instance_id' => (int) $row->instance_id,
                'channel' => (string) $row->channel,
                'circuit_breaker_event_id' => $eventId,
                'timestamp' => gmdate('Y-m-d H:i:s').' UTC',
            ],
        );
    }
}
