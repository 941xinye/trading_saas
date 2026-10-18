<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

/**
 * 通知渠道契约。
 *
 * 每个渠道（log/telegram/…）实现 send()，返回渠道侧消息标识（用于审计）。
 * 载荷为通用 NotificationPayload：信号链（DispatchNotificationJob）与
 * 风控/对账链（RiskNotificationJob）共用同一组渠道实现。
 * 渠道未启用/配置缺失/发送失败一律抛异常，由调用方落 failed 并按重试策略补偿。
 */
interface NotifierChannel
{
    /**
     * 渠道标识，与 signal_notifications.channel / risk_notifications.channel / 订阅 channels[] 一致。
     */
    public function name(): string;

    /**
     * @throws \Throwable 发送失败（调用方负责落 failed + 重试）
     */
    public function send(NotificationPayload $payload): ?string;
}
