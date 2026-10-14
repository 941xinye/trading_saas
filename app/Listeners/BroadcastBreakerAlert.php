<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BreakerAlert;
use App\Events\BreakerTripped;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 同步广播 Listener：BreakerTripped → Reverb websocket 推送。
 *
 * 同步执行（不进队列）：Reverb pub/sub 毫秒级，不阻塞协程事件循环。
 * 仅负责推送到 websocket 频道，不做 DB 写、不发通知（通知走 NotifyBreakerTrip）。
 *
 * 广播失败（Reverb 未启动/网络异常）不影响后续 Listener 与状态迁移。
 */
class BroadcastBreakerAlert
{
    public function handle(BreakerTripped $event): void
    {
        try {
            broadcast(new BreakerAlert(
                $event->tenantId,
                $event->instanceId,
                $event->toState,
                $event->ruleType,
            ))->toOthers();
        } catch (Throwable $e) {
            Log::warning('BreakerAlert 广播失败（不影响状态迁移）', [
                'instance' => $event->instanceId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
