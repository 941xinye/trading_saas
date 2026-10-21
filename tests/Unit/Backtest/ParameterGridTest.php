<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use App\Domain\Backtest\Optimization\ParameterGrid;
use Tests\TestCase;

/**
 * 参数网格展开契约（P11）。
 */
final class ParameterGridTest extends TestCase
{
    public function test_expands_explicit_values_into_cartesian_product(): void
    {
        $combinations = ParameterGrid::expand([
            'grid_count' => [5, 10],
            'investment_amount' => ['1000', '2000'],
        ], 'spot_grid');

        self::assertCount(4, $combinations);
        self::assertSame([
            ['grid_count' => 5, 'investment_amount' => '1000'],
            ['grid_count' => 5, 'investment_amount' => '2000'],
            ['grid_count' => 10, 'investment_amount' => '1000'],
            ['grid_count' => 10, 'investment_amount' => '2000'],
        ], $combinations);
    }

    public function test_expands_min_max_step_inclusive(): void
    {
        $combinations = ParameterGrid::expand([
            'grid_count' => ['min' => 5, 'max' => 15, 'step' => 5],
        ], 'spot_grid');

        self::assertSame([
            ['grid_count' => 5],
            ['grid_count' => 10],
            ['grid_count' => 15],
        ], $combinations);
    }

    public function test_decimal_axis_uses_trimmed_strings(): void
    {
        $combinations = ParameterGrid::expand([
            'lower_price' => ['min' => '90.5', 'max' => '91.5', 'step' => '0.5'],
        ], 'spot_grid');

        self::assertCount(3, $combinations);
        self::assertSame(['lower_price' => '90.5'], $combinations[0]);
        self::assertSame(['lower_price' => '91.5'], $combinations[2]);
    }

    public function test_rejects_parameter_outside_strategy_whitelist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('不在策略 spot_grid 的寻优白名单内');

        ParameterGrid::expand(['leverage' => [3, 5]], 'spot_grid');
    }

    public function test_rejects_empty_grid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ParameterGrid::expand([], 'spot_grid');
    }

    public function test_rejects_combination_count_over_limit(): void
    {
        config()->set('optimization.max_combinations', 2);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('超过上限 2');

        ParameterGrid::expand(['grid_count' => [5, 10, 15]], 'spot_grid');
    }

    public function test_rejects_invalid_step(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('step 必须 > 0');

        ParameterGrid::expand(['grid_count' => ['min' => 5, 'max' => 10, 'step' => 0]], 'spot_grid');
    }
}
