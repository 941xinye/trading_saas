<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notification\Contracts\NotifierChannel;
use App\Domain\Notification\Payloads\RiskNotificationPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 风控/对账多渠道通知发送 Job（notifications 队列，普通 PHP 进程）。
 *
 * 逐渠道处理：找到 NotifyBreakerTrip / ReconciliationService 落的 queued 行 → 发送 → 更新 sent/failed。
 * circuit_breaker_event_id 非空 → 熔断告警；为 null → 账实对账告警（P5.5 步骤 1 复用通知链）。
 * 渠道发送走 NotifierChannel 契约（NotificationPayload 泛化后与信号链共用
 * Log/Telegram/WeCom/Mail 渠道类）；未注册渠道落 failed。
 */
class RiskNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $circuitBreakerEventId,
        public readonly int $instanceId,
        public readonly array $channels,
    ) {
        $this->onQueue('notifications');
    }

    /** @return int[] */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(): void
    {
        foreach ($this->channels as $channel) {
            $this->sendOne($channel);
        }
    }

    private function sendOne(string $channel): void
    {
        $query = DB::table('risk_notifications')
            ->where('tenant_id', $this->tenantId)
            ->where('instance_id', $this->instanceId)
            ->where('channel', $channel)
            ->where('status', 'queued')
            ->orderByDesc('id');

        if ($this->circuitBreakerEventId !== null) {
            $query->where('circuit_breaker_event_id', $this->circuitBreakerEventId);
        }

        $row = $query->first();

        if ($row === null) {
            return;
        }

        $notificationId = (int) $row->id;

        $channelClass = ((array) config('notifications.channel_classes', []))[$channel] ?? null;
        if ($channelClass === null) {
            $this->markFailed($notificationId, "不支持的渠道：{$channel}");

            return;
        }

        /** @var NotifierChannel $notifier */
        $notifier = app($channelClass);

        try {
            $messageId = $notifier->send(RiskNotificationPayload::fromRow($row));

            DB::table('risk_notifications')->where('id', $notificationId)->update([
                'status' => 'sent',
                'provider_message_id' => $messageId !== null ? mb_substr($messageId, 0, 100) : null,
                'error' => null,
                'sent_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            $this->markFailed($notificationId, $e->getMessage());
        }
    }

    private function markFailed(int $notificationId, string $error): void
    {
        DB::table('risk_notifications')->where('id', $notificationId)->update([
            'status' => 'failed',
            'error' => mb_substr($error, 0, 1000),
            'updated_at' => Carbon::now(),
        ]);

        Log::warning('风控通知渠道失败', [
            'notification_id' => $notificationId,
            'error' => $error,
        ]);
    }
}
