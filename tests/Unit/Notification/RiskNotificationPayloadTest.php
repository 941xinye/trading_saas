<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Notification\Payloads\RiskNotificationPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RiskNotificationPayload 工厂纯函数测试（default 组，无 Laravel bootstrap）。
 *
 * circuit_breaker_event_id 非空 → 熔断告警；null → 账实对账告警。
 */
class RiskNotificationPayloadTest extends TestCase
{
    #[DataProvider('rowProvider')]
    public function test_maps_risk_notification_row(
        ?int $eventId,
        string $expectedTitle,
        string $expectedEventField,
    ): void {
        $row = (object) [
            'id' => 11,
            'tenant_id' => 99,
            'instance_id' => 5,
            'channel' => 'log',
            'circuit_breaker_event_id' => $eventId,
        ];

        $payload = RiskNotificationPayload::fromRow($row);

        static::assertSame($expectedTitle, $payload->title);
        static::assertSame('#5', $payload->fields['实例']);
        static::assertSame($expectedEventField, $payload->fields['事件']);
        static::assertStringEndsWith('UTC', (string) $payload->footer);

        static::assertSame(11, $payload->context['notification_id']);
        static::assertSame(99, $payload->context['tenant_id']);
        static::assertSame(5, $payload->context['instance_id']);
        static::assertSame('log', $payload->context['channel']);
        static::assertSame($eventId, $payload->context['circuit_breaker_event_id']);
    }

    /** @return array<string, array{?int, string, string}> */
    public static function rowProvider(): array
    {
        return [
            '熔断告警（event_id 非空）' => [456, '⚠️ 熔断告警', '#456'],
            '账实对账告警（event_id 为 null）' => [null, '🚨 账实对账告警', '-'],
        ];
    }
}
