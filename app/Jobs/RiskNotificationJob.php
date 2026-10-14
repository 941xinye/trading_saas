<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 风控多渠道通知发送 Job（notifications 队列，普通 PHP 进程）。
 *
 * 逐渠道处理：找到 NotifyBreakerTrip 落的 queued 行 → 发送 → 更新 sent/failed。
 * P4 实现 log/telegram；wechat_work/mail 标 failed（留 P5）。
 * 不走 NotifierChannel 契约（该契约绑定 Signal 对象，风控无 signal 概念）。
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

        try {
            $messageId = match ($channel) {
                'log' => $this->sendLog($row),
                'telegram' => $this->sendTelegram($row),
                default => throw new \RuntimeException("风控通知不支持的渠道：{$channel}"),
            };

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

    private function sendLog(object $row): ?string
    {
        Log::info('[BREAKER] 熔断告警', [
            'notification_id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'instance_id' => $row->instance_id,
            'channel' => $row->channel,
            'circuit_breaker_event_id' => $row->circuit_breaker_event_id,
            'timestamp' => gmdate('Y-m-d H:i:s') . ' UTC',
        ]);

        return 'logged';
    }

    private function sendTelegram(object $row): ?string
    {
        $cfg = (array) config('notifications.channels.telegram');
        $token = (string) ($cfg['bot_token'] ?? '');
        $chatId = (string) ($cfg['chat_id'] ?? '');

        if ($token === '' || $chatId === '') {
            throw new \RuntimeException('Telegram 未配置 bot_token/chat_id');
        }

        $text = "<b>⚠️ 熔断告警</b>\n"
            . '实例 #' . (int) $row->instance_id . "\n"
            . '事件 #' . ($row->circuit_breaker_event_id ?? '-') . "\n"
            . '<i>' . gmdate('Y-m-d H:i') . ' UTC</i>';

        $response = Http::timeout((int) ($cfg['timeout'] ?? 10))
            ->asJson()
            ->acceptJson()
            ->post(rtrim((string) $cfg['api_base'], '/') . "/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Telegram API HTTP ' . $response->status());
        }

        $payload = $response->json();
        if (! ($payload['ok'] ?? false)) {
            throw new \RuntimeException('Telegram API ok=false');
        }

        return isset($payload['result']['message_id']) ? (string) $payload['result']['message_id'] : null;
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
