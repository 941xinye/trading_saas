<?php

declare(strict_types=1);

namespace App\Domain\Notification\Contracts;

/**
 * 通知载荷 DTO（信号 / 熔断 / 账实对账等链路共用）。
 *
 * 渠道类只负责"投递"：Log 渠道写 title + context，Telegram 渠道渲染
 * title + fields + footer 为 HTML。业务侧（SignalNotificationPayload /
 * RiskNotificationPayload 等工厂）负责把领域对象装配成本载荷。
 *
 * - title：一行标题（可含 emoji），Telegram 加粗首行、Log 作为消息文本
 * - fields：有序展示字段（label => 纯文本值），Telegram 逐行渲染
 * - footer：页脚小字（通常为 ID + UTC 时间），可空
 * - context：结构化日志上下文（Log 渠道用），不进 Telegram 文本
 */
readonly class NotificationPayload
{
    /**
     * @param  array<string, string>  $fields
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $title,
        public array $fields = [],
        public ?string $footer = null,
        public array $context = [],
    ) {
    }
}
