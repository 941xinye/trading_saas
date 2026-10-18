<?php

declare(strict_types=1);

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Contracts\NotificationPayload;
use App\Domain\Notification\Contracts\NotifierChannel;
use Illuminate\Support\Facades\Http;

/**
 * Telegram 渠道（未配置 token/chat_id 时 send 抛异常，由调用方落 failed）。
 *
 * 配置：TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID（getUpdates 获取）。
 * 使用 Bot API sendMessage，parse_mode=HTML；通用渲染：
 * title 加粗首行 → fields 逐行 label：value → footer 斜体，全部 e() 转义。
 * 信号/风控/对账链路共用本实现。
 */
class TelegramChannel implements NotifierChannel
{
    public function name(): string
    {
        return 'telegram';
    }

    public function send(NotificationPayload $payload): ?string
    {
        $cfg = (array) config('notifications.channels.telegram');
        $token = (string) ($cfg['bot_token'] ?? '');
        $chatId = (string) ($cfg['chat_id'] ?? '');

        if ($token === '' || $chatId === '') {
            throw new \RuntimeException('Telegram 未配置 bot_token/chat_id');
        }

        $response = Http::timeout((int) ($cfg['timeout'] ?? 10))
            ->asJson()
            ->acceptJson()
            ->post(rtrim((string) $cfg['api_base'], '/') . "/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $this->format($payload),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Telegram API HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        $result = $response->json();
        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('Telegram API 返回 ok=false: ' . mb_substr($response->body(), 0, 300));
        }

        return isset($result['result']['message_id']) ? (string) $result['result']['message_id'] : null;
    }

    private function format(NotificationPayload $payload): string
    {
        $lines = ['<b>' . e($payload->title) . '</b>'];

        foreach ($payload->fields as $label => $value) {
            $lines[] = e((string) $label) . '：' . e($value);
        }

        if ($payload->footer !== null) {
            $lines[] = '<i>' . e($payload->footer) . '</i>';
        }

        return implode("\n", $lines);
    }
}
