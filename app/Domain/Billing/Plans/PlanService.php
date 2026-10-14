<?php

declare(strict_types=1);

namespace App\Domain\Billing\Plans;

use App\Models\Tenant;
use RuntimeException;

/**
 * 套餐解析服务（读 config/plans.php）。
 *
 * 生效计划以 tenants.plan_code 为准；subscriptions.plan_code 仅记录计费事实。
 * 未知 plan_code 直接抛异常，绝不静默降级 free（防止异常数据变相提权）。
 */
class PlanService
{
    /**
     * 系统内部渠道：不占套餐渠道白名单（POC 日志渠道、未来站内信同类处理）。
     */
    public const SYSTEM_CHANNELS = ['log'];

    /**
     * 按 ID 取租户（平台级查询，不走 TenantScope）。
     */
    public function tenant(int $tenantId): Tenant
    {
        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->withoutGlobalScopes()->find($tenantId);
        if ($tenant === null) {
            throw new RuntimeException("Tenant {$tenantId} not found");
        }

        return $tenant;
    }

    public function planCode(Tenant $tenant): string
    {
        return (string) $tenant->plan_code;
    }

    /**
     * 套餐完整配置（含 name/price_monthly/features）。
     *
     * @return array<string,mixed>
     */
    public function planConfig(Tenant $tenant): array
    {
        $code = $this->planCode($tenant);
        $plans = (array) config('plans.plans');
        if (! isset($plans[$code]) || ! is_array($plans[$code])) {
            throw new RuntimeException("Unknown plan_code '{$code}' for tenant {$tenant->id}");
        }

        return $plans[$code];
    }

    /**
     * 取套餐 feature 标量/数组。
     */
    public function feature(Tenant $tenant, string $key): mixed
    {
        $features = (array) ($this->planConfig($tenant)['features'] ?? []);

        return $features[$key] ?? null;
    }

    /**
     * 取整数限额（资源数/日配额）；未配置按 0（最保守，不放行）。
     */
    public function limit(Tenant $tenant, string $key): int
    {
        return (int) $this->feature($tenant, $key);
    }

    /**
     * 渠道是否在套餐白名单内（log 等系统内部渠道始终放行）。
     */
    public function allowsChannel(Tenant $tenant, string $channel): bool
    {
        if (in_array($channel, self::SYSTEM_CHANNELS, true)) {
            return true;
        }
        $allowed = (array) ($this->feature($tenant, 'channels') ?? []);

        return in_array($channel, $allowed, true);
    }

    /**
     * 策略类型是否可用：套餐白名单 ∩ 平台已真实落地清单（config('strategy.implemented')）。
     *
     * 双闸门防止售卖超出代码能力（P5.5 步骤 2，销债 B6）：套餐配置遗漏/超前时，
     * 未落地策略一律拒绝，绝不依赖运行时的 "Not implemented" 异常兜底。
     */
    public function allowsStrategy(Tenant $tenant, string $strategyCode): bool
    {
        $implemented = (array) config('strategy.implemented', []);
        if (! in_array($strategyCode, $implemented, true)) {
            return false;
        }

        $allowed = (array) ($this->feature($tenant, 'strategies') ?? []);

        return in_array($strategyCode, $allowed, true);
    }

    /**
     * 套餐是否存在（provision/管理后台用）。
     */
    public static function planExists(string $planCode): bool
    {
        $plans = (array) config('plans.plans');

        return isset($plans[$planCode]) && is_array($plans[$planCode]);
    }
}
