<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Notification\Payloads\SignalNotificationPayload;
use App\Domain\Signal\Contracts\Signal;
use PHPUnit\Framework\TestCase;

/**
 * SignalNotificationPayload 工厂纯函数测试（default 组，无 Laravel bootstrap）。
 *
 * 验证 Signal DTO → 通用 NotificationPayload 的字段映射与展示文案。
 */
class SignalNotificationPayloadTest extends TestCase
{
    public function test_maps_long_ai_signal_to_payload(): void
    {
        $signal = $this->makeSignal(direction: 'long', source: 'ai', rationale: '多头排列');

        $payload = SignalNotificationPayload::fromSignal($signal);

        static::assertSame('🟢 信号：BTCUSDT 做多 LONG', $payload->title);
        static::assertSame('5m（来源：AI，置信度 62）', $payload->fields['周期']);
        static::assertSame('83170.00000000', $payload->fields['入场']);
        static::assertSame('84000.00000000', $payload->fields['止盈']);
        static::assertSame('82000.00000000', $payload->fields['止损']);
        static::assertSame('多头排列', $payload->fields['依据']);
        static::assertStringContainsString('信号 ID：7｜', (string) $payload->footer);
        static::assertStringEndsWith('UTC', (string) $payload->footer);

        static::assertSame(7, $payload->context['signal_id']);
        static::assertSame(99, $payload->context['tenant_id']);
        static::assertSame('long', $payload->context['direction']);
        static::assertSame('ai', $payload->context['source']);
    }

    public function test_short_rule_signal_omits_rationale_field_when_null(): void
    {
        $signal = $this->makeSignal(direction: 'short', source: 'rule_fallback', rationale: null);

        $payload = SignalNotificationPayload::fromSignal($signal);

        static::assertSame('🔴 信号：BTCUSDT 做空 SHORT', $payload->title);
        static::assertSame('5m（来源：规则兜底，置信度 62）', $payload->fields['周期']);
        static::assertArrayNotHasKey('依据', $payload->fields);
        static::assertNull($payload->context['rationale']);
    }

    public function test_null_stop_loss_renders_dash(): void
    {
        $signal = $this->makeSignal(direction: 'long', source: 'ai', rationale: null, stopLoss: null);

        $payload = SignalNotificationPayload::fromSignal($signal);

        static::assertSame('-', $payload->fields['止损']);
    }

    private function makeSignal(
        string $direction,
        string $source,
        ?string $rationale,
        ?string $stopLoss = '82000.00000000',
    ): Signal {
        return new Signal(
            symbol: 'BTCUSDT',
            interval: '5m',
            direction: $direction,
            entryPrice: '83170.00000000',
            exitPrice: '84000.00000000',
            stopLoss: $stopLoss,
            confidence: 62,
            rationale: $rationale,
            source: $source,
            rawPayload: null,
            tenantId: 99,
            subscriptionId: 3,
            persistedId: 7,
        );
    }
}
