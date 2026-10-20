<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Custom\DslStrategy;
use App\Domain\Strategy\StrategyRegistry;
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

    public function test_custom_dsl_is_implemented_and_in_pro_enterprise_only(): void
    {
        $implemented = (array) config('strategy.implemented', []);

        static::assertContains('custom_dsl', $implemented, 'custom_dsl 应已落地（spec 006 US5）');

        $plans = (array) config('plans.plans', []);

        static::assertNotContains(
            'custom_dsl',
            (array) ($plans['free']['features']['strategies'] ?? []),
            'free 套餐不应含 custom_dsl',
        );
        static::assertContains(
            'custom_dsl',
            (array) ($plans['pro']['features']['strategies'] ?? []),
            'pro 套餐应含 custom_dsl',
        );
        static::assertContains(
            'custom_dsl',
            (array) ($plans['enterprise']['features']['strategies'] ?? []),
            'enterprise 套餐应含 custom_dsl',
        );
    }

    public function test_registry_maps_custom_dsl_to_dsl_strategy(): void
    {
        $params = ['dsl_script' => $this->script()];
        $strategy = (new StrategyRegistry)->make(
            'custom_dsl',
            1,
            $params,
            new StrategyContext(1, $params, null, null, [], null),
        );

        static::assertInstanceOf(DslStrategy::class, $strategy, 'custom_dsl 必须映射到 DslStrategy');
    }

    private function script(): string
    {
        return implode("\n", [
            'version: 1',
            'name: whitelist',
            'symbol: BTCUSDT',
            'interval: 1m',
            'direction: long',
            'trigger: on_bar',
            'filter:',
            '  - action: noop',
            '    when:',
            '      op: true',
            'entry:',
            '  - action: open_long',
            '    when:',
            '      op: gt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            '    qty_pct: "0.5"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: lt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            '    qty_pct: "1.0"',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);
    }
}
