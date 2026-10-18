<?php

declare(strict_types=1);

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Contracts\NotificationPayload;
use App\Domain\Notification\Contracts\NotifierChannel;
use App\Mail\DomainNotificationMail;
use Illuminate\Support\Facades\Mail;

/**
 * 邮件渠道（未配置收件地址时 send 抛异常，由调用方落 failed）。
 *
 * 配置：NOTIFY_MAIL_TO（业务告警收件地址；SMTP 传输走框架 mail 配置）。
 * 经 DomainNotificationMail 发纯文本：subject=title，正文 title → fields
 * 逐行 label：value → footer。信号/风控/对账链路共用本实现
 * （config notifications.channel_classes 注册生效）。
 */
class MailChannel implements NotifierChannel
{
    public function name(): string
    {
        return 'mail';
    }

    public function send(NotificationPayload $payload): ?string
    {
        $cfg = (array) config('notifications.channels.mail');
        $to = trim((string) ($cfg['to'] ?? ''));

        if ($to === '') {
            throw new \RuntimeException('邮件渠道未配置收件地址');
        }

        Mail::to($to)->send(new DomainNotificationMail($payload->title, $this->format($payload)));

        return null;
    }

    private function format(NotificationPayload $payload): string
    {
        $lines = [$payload->title];

        foreach ($payload->fields as $label => $value) {
            $lines[] = (string) $label . '：' . $value;
        }

        if ($payload->footer !== null) {
            $lines[] = '-- ' . $payload->footer;
        }

        return implode("\n", $lines);
    }
}
