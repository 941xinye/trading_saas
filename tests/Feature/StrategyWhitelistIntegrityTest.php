<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 策略白名单结构性断言（spec 005 T040）。
 *
 * 防超卖：所有套餐的 strategies 必须 ⊆ config('strategy.implemented')。
 * 否则存在"套餐列出但未落地"的策略，用户购买后无法运行。
 *
 * default 组（纯配置断言，无 DB/Redis）。
 */
class StrategyWhitelistIntegrityTest extends TestCase
{
    public function test_all_plan_strategies_are_implemented(): void
    {
        $implemented = (array) config('strategy.implemented', []);
        $plans = (array) config('plans.plans', []);

        $violations = [];

        foreach ($plans as $planCode => $plan) {
            $strategies = (array) ($plan['features']['strategies'] ?? []);
            foreach ($strategies as $code) {
                if (! in_array($code, $implemented, true)) {
                    $violations[] = "plan={$planCode} lists unimplemented strategy: {$code}";
                }
            }
        }

        static::assertSame([], $violations, implode("\n", $violations));
    }

    public function test_futures_grid_is_implemented_and_in_pro_enterprise_only(): void
    {
        $implemented = (array) config('strategy.implemented', []);

        static::assertContains('futures_grid', $implemented, 'futures_grid 应已落地');

        $plans = (array) config('plans.plans', []);

        // free 不含
        static::assertNotContains(
            'futures_grid',
            (array) ($plans['free']['features']['strategies'] ?? []),
            'free 套餐不应含 futures_grid',
        );

        // pro/enterprise 含
        static::assertContains(
            'futures_grid',
            (array) ($plans['pro']['features']['strategies'] ?? []),
            'pro 套餐应含 futures_grid',
        );
        static::assertContains(
            'futures_grid',
            (array) ($plans['enterprise']['features']['strategies'] ?? []),
            'enterprise 套餐应含 futures_grid',
        );
    }
}
