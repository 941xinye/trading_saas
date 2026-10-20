<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Notification\Contracts\NotifierChannel;
use App\Domain\Notification\Payloads\RenewalReminderPayload;
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
 * 续费提醒发送 Job（spec 007 US3 / FR-007；notifications 队列）。
 *
 * 镜像 RiskNotificationJob 三段式：读 payment_renewal_reminders 的 queued 行
 * → NotifierChannel::send() → 置 sent/failed。一行一渠道，构造锚定 reminderId。
 */
class RenewalReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $reminderId,
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
        $row = DB::table('payment_renewal_reminders')
            ->where('id', $this->reminderId)
            ->where('status', 'queued')
            ->first();

        if ($row === null) {
            return;
        }

        $channel = (string) $row->channel;
        $channelClass = ((array) config('notifications.channel_classes', []))[$channel] ?? null;
        if ($channelClass === null) {
            $this->markFailed("不支持的渠道：{$channel}");

            return;
        }

        /** @var NotifierChannel $notifier */
        $notifier = app($channelClass);

        try {
            $messageId = $notifier->send(RenewalReminderPayload::fromRow($row));

            DB::table('payment_renewal_reminders')->where('id', $this->reminderId)->update([
                'status' => 'sent',
                'provider_message_id' => $messageId !== null ? mb_substr($messageId, 0, 100) : null,
                'error' => null,
                'sent_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            $this->markFailed($e->getMessage());
        }
    }

    private function markFailed(string $error): void
    {
        DB::table('payment_renewal_reminders')->where('id', $this->reminderId)->update([
            'status' => 'failed',
            'error' => mb_substr($error, 0, 1000),
            'updated_at' => Carbon::now(),
        ]);

        Log::warning('续费提醒渠道失败', [
            'reminder_id' => $this->reminderId,
            'error' => $error,
        ]);
    }
}
