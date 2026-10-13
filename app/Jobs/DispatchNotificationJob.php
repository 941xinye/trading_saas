<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * 多渠道通知分发 Job（notifications 队列）。
 *
 * AiAnalyzeJob 写入 signals 后调度本 Job，由 Notifier 编排向用户配置的多渠道
 * 推送：Telegram + 企微 + 邮件 + WebSocket（Reverb 私有频道 private-tenant.{tid}.*）。
 *
 * Notifier 职责（POC 待实现）：
 *  - DedupCooldown：同租户同信号 N 分钟内只发一次（避免同一信号多次 AI 触发刷屏）
 *  - 顺序：WebSocket 即时 → Telegram/企微（限速）→ 邮件（批量）
 *  - 写 signal_notifications 表追踪每渠道状态（queued|sent|failed|deduped）
 *
 * 广播交给各 Channel 实现，Event 自身不实现 ShouldBroadcast
 * （架构方案 §1 WebSocket 分层 + §3 Notification 模块）。
 *
 * POC 阶段：handle() 待实现，对应 P3 POC（多渠道通知链路）。
 */
class DispatchNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 队列名：通知发送涉及外部 API（Telegram/企微），独立队列避免拖累交易链路。
     */
    public $queue = 'notifications';

    public function __construct(
        public readonly int $tenantId,
        public readonly int $signalId,
        public readonly array $channels,
    ) {
    }

    /**
     * P3 POC 实现：Notifier::dispatch(tenantId, signalId, channels)
     * → DedupCooldown → 各 Channel.send() → signal_notifications 落地。
     */
    public function handle(): void
    {
        throw new RuntimeException('Not implemented. Wait for POC.');
    }
}
