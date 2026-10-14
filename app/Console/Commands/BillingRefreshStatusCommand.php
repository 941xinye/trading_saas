<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\SubscriptionService;
use Illuminate\Console\Command;

/**
 * 全租户订阅状态每日刷新（P5.5 步骤 4，C3）。
 *
 * 薄命令：扫描全部租户的最新订阅行，委托 SubscriptionService::refreshStatus()：
 *   trialing/active 到期 → past_due；past_due 超过 plans.past_due_grace_days 宽限
 *   → tenants.status=suspended。无到期/无固定期限订阅不产生动作。
 *
 * 调度：routes/console.php 每日 UTC 00:05（schedule:list 可查）。
 * 幂等：重复执行无副作用，状态机只做一次迁移。
 */
class BillingRefreshStatusCommand extends Command
{
    protected $signature = 'billing:refresh-status';

    protected $description = 'Scan all tenants and refresh subscription status (expire to past_due, suspend after grace)';

    public function handle(SubscriptionService $subscriptions): int
    {
        $transitions = $subscriptions->refreshStatus();

        if ($transitions === []) {
            $this->info('No subscription status transitions.');

            return self::SUCCESS;
        }

        foreach ($transitions as $t) {
            $this->line(sprintf(
                'tenant=%d %s → %s',
                $t['tenant_id'],
                $t['from'],
                $t['to'],
            ));
        }

        $this->info(sprintf('%d transition(s) applied.', count($transitions)));

        return self::SUCCESS;
    }
}
