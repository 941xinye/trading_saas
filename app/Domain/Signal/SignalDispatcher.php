<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Signal\Contracts\Signal;
use App\Domain\Signal\Contracts\SignalDispatcher as SignalDispatcherContract;

/**
 * 信号分发实现。
 *
 * 编排多个通知渠道，每个 channel 分发为独立 Job（queue=notifications），
 * 互不阻塞。P2 POC 落地时注入 App\Domain\Notification\DedupCooldown 做冷却去重：
 *  - 同 (tenant, symbol, direction, interval) 在 N 分钟内只通知一次
 *  - 冷却命中 → 标记 signal_notifications.status='deduped'，不分发 Job
 *
 * 渠道：telegram|wechat_work|mail|websocket
 */
class SignalDispatcher implements SignalDispatcherContract
{
    public function __construct()
    {
    }

    public function dispatch(Signal $signal, array $channels): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P2 POC.');
    }
}
