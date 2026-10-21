<?php

declare(strict_types=1);

namespace App\Domain\Notification\Payloads;

use App\Domain\Notification\Contracts\NotificationPayload;

/**
 * 自动跟单成交通知载荷工厂（FR-030）。
 *
 * 由 NotifyAutoFollowExecutedJob 在 notifications 队列内装配并投递，
 * 复用信号/风控链的同一组 NotifierChannel 实现。
 */
final class AutoFollowExecutedPayload
{
    /**
     * @param  array<string, mixed>  $execution  AutoExecuteSignalJob 的成功结果
     */
    public static function fromExecution(array $execution): NotificationPayload
    {
        $direction = (string) ($execution['direction'] ?? '');
        $label = $direction === 'long' ? '做多 LONG' : '做空 SHORT';
        $symbol = (string) ($execution['symbol'] ?? '');
        $side = (string) ($execution['side'] ?? '');

        return new NotificationPayload(
            title: "自动跟单已执行：{$symbol} {$label}",
            fields: [
                '方向' => $side,
                '名义额' => (string) ($execution['notional'] ?? '-').' USDT',
                '数量' => (string) ($execution['quantity'] ?? '-'),
                '成交均价' => $execution['fill_price'] !== null ? (string) $execution['fill_price'] : '-',
                '订单状态' => (string) ($execution['status'] ?? '-'),
                '实例' => '#'.(int) ($execution['instance_id'] ?? 0),
            ],
            footer: '订单 '.((string) ($execution['client_order_id'] ?? '-')).'｜'.gmdate('Y-m-d H:i').' UTC',
            context: [
                'signal_id' => (int) ($execution['signal_id'] ?? 0),
                'subscription_id' => (int) ($execution['subscription_id'] ?? 0),
                'instance_id' => (int) ($execution['instance_id'] ?? 0),
                'side' => $side,
                'notional' => $execution['notional'] ?? null,
                'quantity' => $execution['quantity'] ?? null,
                'fill_price' => $execution['fill_price'] ?? null,
                'status' => $execution['status'] ?? null,
                'client_order_id' => $execution['client_order_id'] ?? null,
            ],
        );
    }
}
