<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Domain\Notification\Channels\WeComChannel;
use App\Domain\Notification\Contracts\NotificationPayload;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 企微渠道发送测试（B5 销账）。
 *
 * default 组：Http::fake() 隔离外网；未配置 webhook 抛异常（与 Telegram 同契约，
 * 由调用方落 failed，未配置时既有集成测试的 failed 断言语义不变）。
 */
class WeComChannelTest extends TestCase
{
    private const WEBHOOK = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=test-key';

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_send_posts_markdown_payload_and_returns_null(): void
    {
        Http::fake(['*' => Http::response(['errcode' => 0, 'errmsg' => 'ok'])]);
        config(['notifications.channels.wechat_work.webhook' => self::WEBHOOK]);
        $payload = new NotificationPayload(
            title: '熔断告警',
            fields: ['实例' => '1', '规则' => 'max_daily_loss'],
            footer: 'instance=1 UTC 2026-10-18',
        );

        $result = (new WeComChannel)->send($payload);

        static::assertNull($result);
        Http::assertSent(function ($request): bool {
            if (! str_starts_with((string) $request->url(), self::WEBHOOK)) {
                return false;
            }
            $content = (string) ($request->data()['markdown']['content'] ?? '');

            return $request->data()['msgtype'] === 'markdown'
                && str_contains($content, '**熔断告警**')
                && str_contains($content, '实例：1')
                && str_contains($content, '> instance=1 UTC 2026-10-18');
        });
    }

    public function test_send_throws_when_webhook_unconfigured(): void
    {
        Http::fake();
        config(['notifications.channels.wechat_work.webhook' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('企微未配置 webhook');

        (new WeComChannel)->send(new NotificationPayload(title: 'x'));
    }

    public function test_send_throws_on_nonzero_errcode(): void
    {
        config(['notifications.channels.wechat_work.webhook' => self::WEBHOOK]);
        Http::fake(['*' => Http::response(['errcode' => 93000, 'errmsg' => 'invalid webhook url'])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('errcode=93000');

        (new WeComChannel)->send(new NotificationPayload(title: 'x'));
    }

    public function test_send_throws_on_http_failure(): void
    {
        config(['notifications.channels.wechat_work.webhook' => self::WEBHOOK]);
        Http::fake(['*' => Http::response('server error', 500)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        (new WeComChannel)->send(new NotificationPayload(title: 'x'));
    }
}
