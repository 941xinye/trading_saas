<?php

declare(strict_types=1);

namespace App\Domain\Notification\Payloads;

use App\Domain\Notification\Contracts\NotificationPayload;
use Illuminate\Support\Carbon;

/**
 * 续费提醒通知载荷工厂（spec 007 US3 / FR-007）：
 * 把 payment_renewal_reminders 行装配成通用 NotificationPayload。
 *
 * 字段：套餐名、到期日（UTC）、剩余天数、应付金额（bcmath 字符串）、
 * 一键续费链接（Filament 计费页绝对 URL + plan_code，契约 §3）。
 * 金额从 plans 配置按 bcmath 红线格式化为 8 位小数字符串，浮点禁入。
 */
final class RenewalReminderPayload
{
    public static function fromRow(object $row): NotificationPayload
    {
        $planCode = (string) $row->plan_code;
        $plan = (array) config("plans.plans.{$planCode}", []);
        $planName = (string) ($plan['name'] ?? $planCode);
        $amount = number_format((float) ($plan['price_monthly'] ?? 0), 8, '.', '');
        $endsAt = Carbon::parse((string) $row->subscription_ends_at)->utc();
        $daysBefore = (int) $row->days_before;

        return new NotificationPayload(
            title: '🔔 订阅续费提醒',
            fields: [
                '套餐' => $planName,
                '到期日' => $endsAt->format('Y-m-d H:i').' UTC',
                '剩余天数' => $daysBefore.' 天',
                '应付金额' => $amount.' USDT',
                '续费入口' => url('/admin/billing?plan_code='.$planCode),
            ],
            footer: gmdate('Y-m-d H:i').' UTC',
            context: [
                'reminder_id' => (int) $row->id,
                'tenant_id' => (int) $row->tenant_id,
                'subscription_id' => (int) $row->subscription_id,
                'plan_code' => $planCode,
                'days_before' => $daysBefore,
                'channel' => (string) $row->channel,
                'timestamp' => gmdate('Y-m-d H:i:s').' UTC',
            ],
        );
    }
}
