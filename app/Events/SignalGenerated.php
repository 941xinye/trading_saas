<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * AI 信号生成事件（租户级）。
 *
 * AiAnalyzeJob 写入 signals 后触发。direction ∈ {long, short, neutral}。
 *
 * 监听器职责（POC 阶段填实）：
 *  - 调度 DispatchNotificationJob → 多渠道推送
 *  - Reverb 推 private-tenant.{tid}.signals 频道（信号 Feed 实时刷新）
 *
 * 广播交给 WebSocketChannel（Notification Channels），Event 本身不实现 ShouldBroadcast。
 */
readonly class SignalGenerated
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $signalId,
        public readonly string $symbol,
        public readonly string $direction,
    ) {
    }
}
