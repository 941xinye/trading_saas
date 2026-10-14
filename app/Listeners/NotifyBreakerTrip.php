<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Notification\DedupCooldown;
use App\Domain\Tenancy\TenantContext;
use App\Events\BreakerTripped;
use App\Jobs\RiskNotificationJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * 熔断通知调度 Listener（notifications 队列，普通 PHP 进程）。
 *
 * 监听 BreakerTripped 事件：
 *  1. 恢复租户上下文（TenantScope 在 worker 进程内按租户隔离）
 *  2. DedupCooldown 15 分钟去重（fingerprint = sha1(instanceId|ruleType)）
 *  3. 去重通过：为每个推送渠道插 risk_notifications 行 + dispatch RiskNotificationJob
 *  4. 去重未通过：跳过（不插行、不发 Job）
 *
 * 'websocket' 渠道由 BroadcastBreakerAlert 同步推送，不在此处理。
 */
class NotifyBreakerTrip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(BreakerTripped $event): void
    {
        TenantContext::initialize($event->tenantId);

        try {
            $this->process($event);
        } finally {
            TenantContext::clear();
        }
    }

    private function process(BreakerTripped $event): void
    {
        $channels = (array) config('risk.on_trip.notify_channels', []);
        // 'websocket' 由 BroadcastBreakerAlert 同步推送，不落 risk_notifications
        $pushChannels = array_values(array_diff($channels, ['websocket']));

        if ($pushChannels === []) {
            return;
        }

        $fingerprint = sha1($event->instanceId . '|' . $event->ruleType);
        $ttlMinutes = (int) config('risk.on_trip.dedup_ttl_minutes', 15);

        $dedup = app(DedupCooldown::class);
        if (! $dedup->tryAcquire($event->tenantId, 'risk_breaker', $fingerprint, $ttlMinutes)) {
            // 冷却中：同 instanceId+ruleType 的重复 trip 不再通知
            return;
        }

        $now = now();
        foreach ($pushChannels as $channel) {
            DB::table('risk_notifications')->insert([
                'tenant_id' => $event->tenantId,
                'circuit_breaker_event_id' => $event->eventId,
                'instance_id' => $event->instanceId,
                'channel' => $channel,
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        RiskNotificationJob::dispatch($event->tenantId, $event->eventId, $event->instanceId, $pushChannels);
    }
}
