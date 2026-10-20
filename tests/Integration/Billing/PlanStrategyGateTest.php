<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Domain\Billing\Plans\PlanService;
use App\Models\Tenant;
use Tests\TestCase;

/**
 * 套餐策略售卖闸门（P5.5 步骤 2 验收 #8）。
 *
 * plans.php 白名单 ∩ config('strategy.implemented') 双闸门：
 * 任何未真实落地的策略代码（合约网格/DCA/马丁/滚仓/DSL）在任何套餐下都必须拒绝。
 */
class PlanStrategyGateTest extends TestCase
{
    private function tenant(string $plan): Tenant
    {
        return new Tenant(['plan_code' => $plan]);
    }

    public function test_implemented_spot_strategies_allowed_per_whitelist(): void
    {
        $service = app(PlanService::class);

        static::assertTrue($service->allowsStrategy($this->tenant('free'), 'spot_grid'));
        static::assertTrue($service->allowsStrategy($this->tenant('free'), 'spot_dca'));
        static::assertTrue($service->allowsStrategy($this->tenant('pro'), 'spot_grid'));
        static::assertTrue($service->allowsStrategy($this->tenant('pro'), 'spot_dca'));
        static::assertTrue($service->allowsStrategy($this->tenant('enterprise'), 'spot_grid'));
        static::assertTrue($service->allowsStrategy($this->tenant('enterprise'), 'spot_dca'));
    }

    public function test_futures_grid_allowed_in_pro_and_enterprise_but_not_free(): void
    {
        $service = app(PlanService::class);

        // free 不含合约
        static::assertFalse($service->allowsStrategy($this->tenant('free'), 'futures_grid'));
        // pro/enterprise 含合约
        static::assertTrue($service->allowsStrategy($this->tenant('pro'), 'futures_grid'));
        static::assertTrue($service->allowsStrategy($this->tenant('enterprise'), 'futures_grid'));
    }

    public function test_custom_dsl_allowed_in_pro_and_enterprise_but_not_free(): void
    {
        $service = app(PlanService::class);

        // free 不含自定义 DSL（spec 006 US5-1）
        static::assertFalse($service->allowsStrategy($this->tenant('free'), 'custom_dsl'));
        static::assertTrue($service->allowsStrategy($this->tenant('pro'), 'custom_dsl'));
        static::assertTrue($service->allowsStrategy($this->tenant('enterprise'), 'custom_dsl'));
    }

    public function test_unimplemented_strategies_denied_everywhere(): void
    {
        $service = app(PlanService::class);
        $unimplemented = ['futures_dca', 'smart_martingale', 'trend_rolling'];

        foreach (['free', 'pro', 'enterprise'] as $plan) {
            foreach ($unimplemented as $code) {
                static::assertFalse(
                    $service->allowsStrategy($this->tenant($plan), $code),
                    "plan={$plan} strategy={$code} 应被拒绝（未落地策略不得售卖/运行）",
                );
            }
        }
    }

    public function test_hard_gate_rejects_unimplemented_code_even_if_plan_whitelist_lists_it(): void
    {
        // 模拟运营误配置：套餐白名单超前写入了未落地策略
        config()->set('plans.plans.pro.features.strategies', ['spot_grid', 'futures_grid', 'smart_martingale']);
        $service = app(PlanService::class);

        static::assertTrue($service->allowsStrategy($this->tenant('pro'), 'spot_grid'));
        // futures_grid 已落地，硬闸门放行
        static::assertTrue($service->allowsStrategy($this->tenant('pro'), 'futures_grid'));
        // smart_martingale 未落地，硬闸门拒绝
        static::assertFalse($service->allowsStrategy($this->tenant('pro'), 'smart_martingale'));
    }

    public function test_unknown_strategy_code_denied(): void
    {
        $service = app(PlanService::class);

        static::assertFalse($service->allowsStrategy($this->tenant('enterprise'), 'does_not_exist'));
    }
}
