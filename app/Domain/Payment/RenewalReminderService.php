<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Billing\Plans\PlanService;
use App\Jobs\RenewalReminderJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 续费提醒扫描服务（spec 007 US3 / FR-007 / FR-008，契约 §3）。
 *
 * 取每租户最新订阅行（status ∈ active/trialing 且 ends_at 未来），按日历日计算
 * days_left，命中 config('payment.renewal_reminder_days')（默认 [7,3,1]）档位时，
 * 按 config('payment.reminder_channels') 在套餐 channels 白名单内
 * （PlanService::allowsChannel，log 系统渠道恒放行）落 queued 行并投递
 * RenewalReminderJob。
 *
 * 幂等锚：唯一索引 (subscription_id, subscription_ends_at, days_before, channel)
 * —— 同周期同档同渠道重复扫描零新增；续费顺延（新订阅行/新 ends_at）即新周期可再发。
 */
final class RenewalReminderService
{
    public function __construct(private readonly PlanService $plans) {}

    /**
     * @return int 本次新建的提醒行数
     */
    public function dispatch(?int $tenantId = null, ?CarbonImmutable $nowUtc = null): int
    {
        $now = $nowUtc ?? CarbonImmutable::now('UTC');
        $reminderDays = array_map('intval', (array) config('payment.renewal_reminder_days', [7, 3, 1]));
        $channels = array_map('strval', (array) config('payment.reminder_channels', ['log']));
        $created = 0;

        // 每租户最新订阅行（按 id 倒序取 MAX，与 SubscriptionService::refreshStatus 同口径）
        $latestIdsQuery = DB::table('subscriptions as s')
            ->selectRaw('MAX(s.id) as id')
            ->groupBy('s.tenant_id');
        if ($tenantId !== null) {
            $latestIdsQuery->where('s.tenant_id', $tenantId);
        }

        foreach ($latestIdsQuery->pluck('id') as $id) {
            $sub = DB::table('subscriptions')->where('id', $id)->first();
            if ($sub === null || $sub->ends_at === null) {
                continue;
            }
            if (! in_array((string) $sub->status, ['active', 'trialing'], true)) {
                continue;
            }

            $endsAt = CarbonImmutable::parse((string) $sub->ends_at);
            if ($endsAt->lte($now)) {
                continue;
            }

            // 日历日差（startOfDay 对齐）：避免命令运行时刻与 ends_at 时刻的分钟级偏差丢档
            $daysLeft = (int) $now->startOfDay()->diffInDays($endsAt->startOfDay());
            if (! in_array($daysLeft, $reminderDays, true)) {
                continue;
            }

            $tenant = $this->plans->tenant((int) $sub->tenant_id);
            foreach ($channels as $channel) {
                if (! $this->plans->allowsChannel($tenant, $channel)) {
                    continue;
                }
                if ($this->enqueue($sub, $daysLeft, $channel)) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * 落 queued 行（唯一索引幂等）并在新建成功时投递发送 Job。
     *
     * @return bool 是否新建（false = 同周期同档同渠道已存在）
     */
    private function enqueue(object $sub, int $daysBefore, string $channel): bool
    {
        $now = Carbon::now('UTC');
        $endsAt = Carbon::parse((string) $sub->ends_at);

        $inserted = DB::table('payment_renewal_reminders')->insertOrIgnore([
            'tenant_id' => (int) $sub->tenant_id,
            'subscription_id' => (int) $sub->id,
            'subscription_ends_at' => $endsAt,
            'days_before' => $daysBefore,
            'plan_code' => (string) $sub->plan_code,
            'channel' => $channel,
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== 1) {
            return false;
        }

        $reminderId = (int) DB::table('payment_renewal_reminders')
            ->where('subscription_id', (int) $sub->id)
            ->where('subscription_ends_at', $endsAt)
            ->where('days_before', $daysBefore)
            ->where('channel', $channel)
            ->value('id');

        RenewalReminderJob::dispatch($reminderId);

        return true;
    }
}
