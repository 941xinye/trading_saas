<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Notification\DedupCooldown;
use App\Domain\Tenancy\TenantContext;
use App\Events\PositionRiskAlert;
use App\Jobs\RiskNotificationJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * 仓位风控告警通知 Listener（spec 005 T038）。
 *
 * 监听 PositionRiskAlert 事件（warning/reduce/emergency_close）：
 *  1. 恢复租户上下文
 *  2. DedupCooldown 15 分钟去重（fingerprint = sha1(instanceId|actionType)）
 *  3. 去重通过：为每个推送渠道插 risk_notifications 行 + dispatch RiskNotificationJob
 *
 * 复用熔断通知的 RiskNotificationJob 渠道分发逻辑；circuit_breaker_event_id 传 null（仓位风控无熔断事件）。
 */
class NotifyPositionRiskAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(PositionRiskAlert $event): void
    {
        TenantContext::initialize($event->tenantId);

        try {
            $this->process($event);
        } finally {
            TenantContext::clear();
        }
    }

    private function process(PositionRiskAlert $event): void
    {
        $channels = (array) config('risk.on_trip.notify_channels', []);
        $pushChannels = array_values(array_diff($channels, ['websocket']));

        if ($pushChannels === []) {
            return;
        }

        $fingerprint = sha1($event->instanceId . '|' . $event->actionType);
        $ttlMinutes = (int) config('risk.on_trip.dedup_ttl_minutes', 15);

        $dedup = app(DedupCooldown::class);
        if (! $dedup->tryAcquire($event->tenantId, 'position_risk', $fingerprint, $ttlMinutes)) {
            return;
        }

        $now = now();
        foreach ($pushChannels as $channel) {
            DB::table('risk_notifications')->insert([
                'tenant_id' => $event->tenantId,
                'circuit_breaker_event_id' => null,
                'instance_id' => $event->instanceId,
                'channel' => $channel,
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        RiskNotificationJob::dispatch($event->tenantId, null, $event->instanceId, $pushChannels);
    }
}
