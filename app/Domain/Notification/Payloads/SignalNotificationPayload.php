<?php

declare(strict_types=1);

namespace App\Domain\Notification\Payloads;

use App\Domain\Notification\Contracts\NotificationPayload;
use App\Domain\Signal\Contracts\Signal;

/**
 * 信号通知载荷工厂：把 Signal DTO 装配成通用 NotificationPayload。
 *
 * fields/footer 为 Telegram 等富文本渠道的展示行；context 为 Log 渠道的结构化字段。
 */
final class SignalNotificationPayload
{
    public static function fromSignal(Signal $signal): NotificationPayload
    {
        $icon = $signal->direction === 'long' ? '🟢' : '🔴';
        $label = $signal->direction === 'long' ? '做多 LONG' : '做空 SHORT';
        $source = $signal->source === 'ai' ? 'AI' : '规则兜底';

        $fields = [
            '周期' => $signal->interval.'（来源：'.$source.'，置信度 '.$signal->confidence.'）',
            '入场' => (string) $signal->entryPrice,
            '止盈' => (string) $signal->exitPrice,
            '止损' => $signal->stopLoss !== null ? (string) $signal->stopLoss : '-',
        ];
        if ($signal->rationale !== null) {
            $fields['依据'] = $signal->rationale;
        }

        return new NotificationPayload(
            title: "{$icon} 信号：{$signal->symbol} {$label}",
            fields: $fields,
            footer: '信号 ID：'.(int) $signal->persistedId.'｜'.gmdate('Y-m-d H:i').' UTC',
            context: [
                'signal_id' => $signal->persistedId,
                'tenant_id' => $signal->tenantId,
                'direction' => $signal->direction,
                'symbol' => $signal->symbol,
                'interval' => $signal->interval,
                'entry' => $signal->entryPrice,
                'exit' => $signal->exitPrice,
                'stop' => $signal->stopLoss,
                'confidence' => $signal->confidence,
                'source' => $signal->source,
                'rationale' => $signal->rationale,
            ],
        );
    }
}
