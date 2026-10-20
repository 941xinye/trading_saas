<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Plans\PlanService;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 订阅状态机服务（架构 §4.7）。
 *
 * 状态流转（POC 无支付网关，由命令/管理动作显式驱动）：
 *
 *   provision(trial)   → trialing  ──(到期 refresh)──▶ past_due
 *   provision(paid)    → active    ──(到期 refresh)──▶ past_due
 *   past_due           ──(超过宽限 grace)──────────▶ tenant suspended
 *   past_due/suspended ──provision 续费/恢复──────▶ active + tenant active
 *   cancel             → canceled（到期后不再放行新动作）
 *
 * tenants.plan_code 始终跟随最近一次 provision 的套餐，是生效计划来源；
 * subscriptions.plan_code 保留历次计费事实。
 *
 * 付费周期（spec 007 US3）：trialDays=0 的付费订阅 ends_at = now + 1 个日历月
 * （本期仅月度计费，契约 §1；不新增 plans 配置项，契约 §8），使付费订阅同样
 * 参与 refreshStatus 到期流转与续费提醒扫描。
 */
class SubscriptionService
{
    public function __construct(private readonly PlanService $plans) {}

    /**
     * 开通/续费订阅。
     *
     * @param  int|null  $trialDays  >0 走 trialing；null 取 config tenancy.trial_days；0 直接 active（周期 = 1 个日历月）
     */
    public function provision(
        int $tenantId,
        ?string $planCode = null,
        ?int $trialDays = null,
    ): Subscription {
        $tenant = $this->plans->tenant($tenantId);

        if ($planCode !== null && ! PlanService::planExists($planCode)) {
            throw new RuntimeException("Unknown plan_code: {$planCode}");
        }
        $planCode ??= $tenant->plan_code;
        $trialDays ??= (int) config('tenancy.trial_days', 14);

        $now = Carbon::now();
        $status = $trialDays > 0 ? 'trialing' : 'active';
        $endsAt = $trialDays > 0 ? $now->copy()->addDays($trialDays) : $now->copy()->addMonth();

        return DB::transaction(function () use ($tenantId, $planCode, $status, $endsAt, $now) {
            // 恢复/续费：tenant 回到 active，plan_code 更新
            DB::table('tenants')->where('id', $tenantId)->update([
                'plan_code' => $planCode,
                'status' => 'active',
                'trial_ends_at' => $status === 'trialing' ? $endsAt : null,
                'updated_at' => $now,
            ]);

            /** @var Subscription $subscription */
            $subscription = Subscription::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'plan_code' => $planCode,
                'status' => $status,
                'started_at' => $now,
                'ends_at' => $endsAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $subscription;
        });
    }

    /**
     * 取消订阅（立即到期，新动作按 canceled 拦截；不直接改 tenant.status，
     * suspended 由 refreshStatus 在宽限逻辑之外单独管理）。
     */
    public function cancel(int $tenantId): ?Subscription
    {
        $latest = $this->latestFor($tenantId);
        if ($latest === null || $latest->status === 'canceled') {
            return $latest;
        }

        $now = Carbon::now();
        DB::table('subscriptions')->where('id', $latest->getKey())->update([
            'status' => 'canceled',
            'ends_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->latestFor($tenantId);
    }

    /**
     * 刷新订阅状态（可挂每日调度；POC 由 verify 命令调用）。
     *
     * @return array<int,array{tenant_id:int,from:string,to:string}> 本次发生的迁移
     */
    public function refreshStatus(): array
    {
        $transitions = [];
        $graceDays = (int) config('plans.past_due_grace_days', 3);
        $now = Carbon::now();

        // 取每个租户的最新订阅行（按 id 倒序）
        $latestIds = DB::table('subscriptions as s')
            ->selectRaw('MAX(s.id) as id')
            ->groupBy('s.tenant_id')
            ->pluck('id')
            ->all();

        foreach ($latestIds as $id) {
            $sub = DB::table('subscriptions')->where('id', $id)->first();
            if ($sub === null || $sub->ends_at === null) {
                continue; // 无固定期限的 active 不参与到期流转
            }
            $endsAt = Carbon::parse($sub->ends_at);
            if ($endsAt->isFuture()) {
                continue;
            }

            if (in_array($sub->status, ['trialing', 'active'], true)) {
                DB::table('subscriptions')->where('id', $id)->update([
                    'status' => 'past_due',
                    'updated_at' => $now,
                ]);
                $transitions[] = ['tenant_id' => (int) $sub->tenant_id, 'from' => $sub->status, 'to' => 'past_due'];

                continue;
            }

            if ($sub->status === 'past_due' && $endsAt->copy()->addDays($graceDays)->isPast()) {
                DB::table('tenants')->where('id', $sub->tenant_id)->update([
                    'status' => 'suspended',
                    'updated_at' => $now,
                ]);
                $transitions[] = ['tenant_id' => (int) $sub->tenant_id, 'from' => 'past_due', 'to' => 'suspended'];
            }
        }

        return $transitions;
    }

    public function latestFor(int $tenantId): ?Subscription
    {
        /** @var Subscription|null $row */
        $row = Subscription::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->first();

        return $row;
    }
}
