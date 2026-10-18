<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Domain\Notification\Channels\TelegramChannel;
use App\Domain\Notification\Contracts\NotificationPayload;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TelegramChannel 通用渲染测试（default 组：Http::fake，无外网请求）。
 *
 * 验证通用 NotificationPayload 的 HTML 渲染（转义/字段行/页脚）与
 * 未配置 bot_token 时抛异常（由调用方落 failed 的契约）。
 */
class TelegramChannelTest extends TestCase
{
    public function test_sends_rendered_html_and_returns_message_id(): void
    {
        config([
            'notifications.channels.telegram.bot_token' => 'test-token',
            'notifications.channels.telegram.chat_id' => 'chat-1',
            'notifications.channels.telegram.api_base' => 'https://telegram.invalid',
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]])]);

        $messageId = (new TelegramChannel)->send(new NotificationPayload(
            title: '⚠️ 熔断告警 <script>',
            fields: ['实例' => '#5', '事件' => '#456'],
            footer: '2026-10-18 00:00 UTC',
        ));

        static::assertSame('42', $messageId);

        Http::assertSent(function ($request): bool {
            $data = json_decode($request->body(), true);
            $text = (string) ($data['text'] ?? '');

            return str_contains($request->url(), 'https://telegram.invalid/bottest-token/sendMessage')
                && ($data['chat_id'] ?? '') === 'chat-1'
                && str_contains($text, '<b>⚠️ 熔断告警 &lt;script&gt;</b>')
                && str_contains($text, '实例：#5')
                && str_contains($text, '事件：#456')
                && str_contains($text, '<i>2026-10-18 00:00 UTC</i>');
        });
    }

    public function test_throws_when_token_missing(): void
    {
        config([
            'notifications.channels.telegram.bot_token' => '',
            'notifications.channels.telegram.chat_id' => '',
        ]);
        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Telegram 未配置');

        (new TelegramChannel)->send(new NotificationPayload(title: 'x'));
    }

    public function test_throws_when_api_returns_not_ok(): void
    {
        config([
            'notifications.channels.telegram.bot_token' => 'test-token',
            'notifications.channels.telegram.chat_id' => 'chat-1',
            'notifications.channels.telegram.api_base' => 'https://telegram.invalid',
        ]);
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'bad request'], 400)]);

        $this->expectException(\RuntimeException::class);

        (new TelegramChannel)->send(new NotificationPayload(title: 'x'));
    }
}
