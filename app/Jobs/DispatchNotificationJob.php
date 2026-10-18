<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notification\Contracts\NotificationPayload;
use App\Domain\Notification\Contracts\NotifierChannel;
use App\Domain\Notification\Payloads\SignalNotificationPayload;
use App\Domain\Signal\Contracts\Signal;
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
 * 多渠道通知发送 Job（notifications 队列，普通 PHP 进程，可做外部 HTTP）。
 *
 * 逐渠道处理：定位 dispatcher 落的 queued 行 → 渠道 send() → 更新 sent/failed。
 * 单渠道失败只影响该渠道行，不阻断其他渠道；Job 级重试按 10/30/90s 退避。
 */
class DispatchNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $signalId,
        public readonly array $channels,
    ) {
        // Laravel 13 Queueable trait 自带 $queue 属性，不能重复声明，走 onQueue()
        $this->onQueue('notifications');
    }

    /**
     * @return int[]
     */
    public function backoff(): array
    {
        return (array) config('notifications.retry.backoff_seconds', [10, 30, 90]);
    }

    public function handle(): void
    {
        $row = DB::table('signals')
            ->where('id', $this->signalId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if ($row === null) {
            Log::error('通知 Job 找不到信号，放弃', ['signal_id' => $this->signalId]);

            return;
        }

        $payload = SignalNotificationPayload::fromSignal($this->toSignal($row));

        foreach ($this->channels as $channel) {
            $this->sendOne($channel, $payload);
        }
    }

    private function sendOne(string $channelName, NotificationPayload $payload): void
    {
        $notificationId = DB::table('signal_notifications')
            ->where('signal_id', $this->signalId)
            ->where('tenant_id', $this->tenantId)
            ->where('channel', $channelName)
            ->where('status', 'queued')
            ->orderByDesc('id')
            ->value('id');

        if ($notificationId === null) {
            // 可能已被其他重试处理
            return;
        }

        $channelClass = ((array) config('notifications.channel_classes', []))[$channelName] ?? null;
        if ($channelClass === null) {
            $this->markFailed((int) $notificationId, "不支持的渠道：{$channelName}");

            return;
        }

        /** @var NotifierChannel $channel */
        $channel = app($channelClass);

        try {
            $messageId = $channel->send($payload);
            DB::table('signal_notifications')
                ->where('id', $notificationId)
                ->update([
                    'status' => 'sent',
                    'provider_message_id' => $messageId !== null ? mb_substr($messageId, 0, 100) : null,
                    'error' => null,
                    'sent_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
        } catch (Throwable $e) {
            $this->markFailed((int) $notificationId, $e->getMessage());
        }
    }

    private function markFailed(int $notificationId, string $error): void
    {
        DB::table('signal_notifications')
            ->where('id', $notificationId)
            ->update([
                'status' => 'failed',
                'error' => mb_substr($error, 0, 1000),
                'updated_at' => Carbon::now(),
            ]);

        Log::warning('信号通知渠道失败', [
            'notification_id' => $notificationId,
            'signal_id' => $this->signalId,
            'error' => $error,
        ]);
    }

    private function toSignal(object $row): Signal
    {
        $raw = $row->raw_payload !== null ? json_decode((string) $row->raw_payload, true) : null;

        return new Signal(
            symbol: (string) $row->symbol,
            interval: (string) $row->interval,
            direction: (string) $row->direction,
            entryPrice: (string) $row->entry_price,
            exitPrice: (string) $row->exit_price,
            stopLoss: $row->stop_loss !== null ? (string) $row->stop_loss : null,
            confidence: (int) $row->confidence,
            rationale: $row->rationale !== null ? (string) $row->rationale : null,
            source: (string) $row->source,
            rawPayload: is_array($raw) ? $raw : null,
            tenantId: $this->tenantId,
            subscriptionId: (int) $row->subscription_id,
            persistedId: $this->signalId,
        );
    }
}
