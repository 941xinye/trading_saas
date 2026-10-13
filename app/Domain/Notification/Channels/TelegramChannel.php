<?php

declare(strict_types=1);

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Contracts\NotifierChannel;
use App\Domain\Signal\Contracts\Signal;
use Illuminate\Support\Facades\Http;

/**
 * Telegram 渠道（P2 POC 顺带实现；未配置 token/chat_id 时不启用）。
 *
 * 配置：TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID（getUpdates 获取）。
 * 使用 Bot API sendMessage，parse_mode=HTML。
 */
class TelegramChannel implements NotifierChannel
{
    public function name(): string
    {
        return 'telegram';
    }

    public function send(Signal $signal): ?string
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
                'text' => $this->format($signal),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Telegram API HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        $payload = $response->json();
        if (! ($payload['ok'] ?? false)) {
            throw new \RuntimeException('Telegram API 返回 ok=false: ' . mb_substr($response->body(), 0, 300));
        }

        return isset($payload['result']['message_id']) ? (string) $payload['result']['message_id'] : null;
    }

    private function format(Signal $signal): string
    {
        $icon = $signal->direction === 'long' ? '🟢' : '🔴';
        $label = $signal->direction === 'long' ? '做多 LONG' : '做空 SHORT';
        $source = $signal->source === 'ai' ? 'AI' : '规则兜底';

        $lines = [
            "<b>{$icon} 信号：" . e($signal->symbol) . " {$label}</b>",
            '周期：' . e($signal->interval) . '（来源：' . $source . '，置信度 ' . $signal->confidence . '）',
            '入场：<code>' . e((string) $signal->entryPrice) . '</code>',
            '止盈：<code>' . e((string) $signal->exitPrice) . '</code>',
            '止损：<code>' . e((string) ($signal->stopLoss ?? '-')) . '</code>',
        ];
        if ($signal->rationale !== null) {
            $lines[] = '依据：' . e($signal->rationale);
        }
        $lines[] = '<i>信号 ID：' . (int) $signal->persistedId . '｜' . gmdate('Y-m-d H:i') . ' UTC</i>';

        return implode("\n", $lines);
    }
}
