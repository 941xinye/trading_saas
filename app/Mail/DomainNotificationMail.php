<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 通用通知邮件（信号/风控/对账链路共用）。
 *
 * 纯文本邮件：subject=载荷标题，正文=渠道无关的文本渲染
 * （title → fields 逐行 label：value → footer），渲染结果由
 * MailChannel 组装后传入，避免 Mailable 依赖 Notification 域契约。
 */
class DomainNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectText,
        public readonly string $bodyText,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectText);
    }

    public function content(): Content
    {
        return new Content(text: 'notifications.payload');
    }
}
