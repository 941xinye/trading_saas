<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payment\RenewalReminderService;
use Illuminate\Console\Command;

/**
 * 订阅续费提醒扫描（spec 007 US3 / FR-007；薄命令，委托 RenewalReminderService::dispatch()）。
 *
 * 调度：routes/console.php 每日 00:10 UTC（schedule:list 可查）。
 * 幂等：唯一索引 (subscription_id, subscription_ends_at, days_before, channel)，
 *       同周期同档同渠道重复执行零新增；续费顺延即新周期可再发。
 */
class BillingRenewalRemindersCommand extends Command
{
    protected $signature = 'billing:renewal-reminders {--tenant= : 仅扫描指定租户}';

    protected $description = 'Scan subscriptions and queue 7/3/1-day renewal reminders';

    public function handle(RenewalReminderService $reminders): int
    {
        $tenant = $this->option('tenant');
        $count = $reminders->dispatch($tenant !== null ? (int) $tenant : null);

        $this->info(sprintf('%d renewal reminder(s) queued.', $count));

        return self::SUCCESS;
    }
}
