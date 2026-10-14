<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Metering\QuotaExceededException;
use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Billing\Plans\PlanService;
use App\Models\MeterEvent;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * 配额/权益统一门面（各业务入口只依赖本类）。
 *
 *  - assertEntitled()        租户状态 + 最新订阅状态机（active 才允许新动作）
 *  - freezeDaily()/settle()/release()  日配额两阶段（ai_call/order_placed）
 *  - assertResourceCount()   资源数限额（max_*）
 *  - assertChannels/Strategy 套餐特性白名单
 */
class QuotaGuard
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly TwoPhaseMeter $meter,
    ) {
    }

    // ------------------------------------------------------------------
    // 权益
    // ------------------------------------------------------------------

    public function tenant(int $tenantId): Tenant
    {
        return $this->plans->tenant($tenantId);
    }

    /**
     * 租户当前是否有权发起新动作（AI 分析/下单/创建资源）。
     *
     * 无订阅行的租户按 tenant.plan_code 放行（free 套餐可无订阅记录，如 P1 租户）。
     */
    public function assertEntitled(int $tenantId): void
    {
        $tenant = $this->plans->tenant($tenantId);

        if ($tenant->status !== 'active') {
            throw new EntitlementException(sprintf(
                'Tenant %d is not active (status=%s)',
                $tenantId,
                $tenant->status,
            ));
        }

        // 未知套餐同样视为权益异常（不静默降级，防提权）
        $this->plans->planConfig($tenant);

        $latest = $this->latestSubscription($tenantId);
        if ($latest === null) {
            return;
        }

        if (in_array($latest->status, ['past_due', 'canceled'], true)) {
            throw new EntitlementException(sprintf(
                'Tenant %d subscription status=%s',
                $tenantId,
                $latest->status,
            ));
        }

        // trialing/active 但已到期（refreshStatus 未跑时的直接兜底）
        if ($latest->ends_at !== null && $latest->ends_at->isPast()) {
            throw new EntitlementException(sprintf(
                'Tenant %d subscription ended at %s',
                $tenantId,
                $latest->ends_at->toDateTimeString(),
            ));
        }
    }

    public function isEntitled(int $tenantId): bool
    {
        try {
            $this->assertEntitled($tenantId);

            return true;
        } catch (EntitlementException) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // 日配额（两阶段）
    // ------------------------------------------------------------------

    /**
     * 占用一个日配额单位；超限抛 QuotaExceededException（不落 frozen 行）。
     */
    public function freezeDaily(int $tenantId, string $eventType, string $refId, string $quantity = '1'): MeterEvent
    {
        if (! isset(TwoPhaseMeter::DAILY_LIMIT_KEYS[$eventType])) {
            throw new \InvalidArgumentException("Unknown meter event type: {$eventType}");
        }

        $tenant = $this->plans->tenant($tenantId);
        $limit = $this->plans->limit($tenant, TwoPhaseMeter::DAILY_LIMIT_KEYS[$eventType]);
        $event = $this->meter->freeze($tenantId, $eventType, $refId, $limit, $quantity);

        if ($event === null) {
            throw new QuotaExceededException(
                $tenantId,
                $eventType,
                $limit,
                $this->meter->usedToday($tenantId, $eventType),
            );
        }

        return $event;
    }

    public function settle(MeterEvent $event): void
    {
        $this->meter->settle($event);
    }

    public function release(MeterEvent $event): void
    {
        $this->meter->reverse($event);
    }

    // ------------------------------------------------------------------
    // 资源数 / 特性
    // ------------------------------------------------------------------

    /**
     * 创建资源前的数量门槛（$currentCount 为当前已占用数，由调用方按表统计）。
     */
    public function assertResourceCount(int $tenantId, string $limitKey, int $currentCount): void
    {
        $tenant = $this->plans->tenant($tenantId);
        $limit = $this->plans->limit($tenant, $limitKey);

        if ($currentCount >= $limit) {
            throw new QuotaExceededException($tenantId, $limitKey, $limit, $currentCount);
        }
    }

    /**
     * @param string[] $channels
     */
    public function assertChannels(int $tenantId, array $channels): void
    {
        $tenant = $this->plans->tenant($tenantId);
        foreach ($channels as $channel) {
            if (! $this->plans->allowsChannel($tenant, $channel)) {
                throw new EntitlementException(sprintf(
                    'Plan %s does not include channel "%s"',
                    $tenant->plan_code,
                    $channel,
                ));
            }
        }
    }

    public function assertStrategyAllowed(int $tenantId, string $strategyCode): void
    {
        $tenant = $this->plans->tenant($tenantId);
        if (! $this->plans->allowsStrategy($tenant, $strategyCode)) {
            throw new EntitlementException(sprintf(
                'Plan %s does not include strategy "%s"',
                $tenant->plan_code,
                $strategyCode,
            ));
        }
    }

    private function latestSubscription(int $tenantId): ?Subscription
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
