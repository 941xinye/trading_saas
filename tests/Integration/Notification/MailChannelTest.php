<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Domain\Notification\Channels\MailChannel;
use App\Domain\Notification\Contracts\NotificationPayload;
use App\Mail\DomainNotificationMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 邮件渠道发送测试（B5 销账）。
 *
 * default 组：Mail::fake() 隔离传输；未配置收件地址抛异常（与 Telegram 同契约，
 * 由调用方落 failed，未配置时既有集成测试的 failed 断言语义不变）。
 */
class MailChannelTest extends TestCase
{
    private const TO = 'ops@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_send_delivers_raw_mail_with_subject_and_returns_null(): void
    {
        config(['notifications.channels.mail.to' => self::TO]);
        $payload = new NotificationPayload(
            title: '对账告警',
            fields: ['实例' => '1', '差异' => 'drift'],
            footer: 'reconcile=1 UTC 2026-10-18',
        );

        $result = (new MailChannel)->send($payload);

        static::assertNull($result);
        Mail::assertSent(function (DomainNotificationMail $mail) use ($payload): bool {
            return $mail->subjectText === $payload->title
                && $mail->bodyText === "对账告警\n实例：1\n差异：drift\n-- reconcile=1 UTC 2026-10-18"
                && $mail->hasTo(self::TO);
        });
    }

    public function test_send_throws_when_recipient_unconfigured(): void
    {
        config(['notifications.channels.mail.to' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('邮件渠道未配置收件地址');

        (new MailChannel)->send(new NotificationPayload(title: 'x'));
    }
}
