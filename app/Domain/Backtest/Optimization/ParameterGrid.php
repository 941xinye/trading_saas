<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Optimization;

/**
 * 参数网格展开（P11）。
 *
 * 支持两种轴定义：
 *   {"grid_count": [5, 10, 20]}                        显式值列表
 *   {"grid_count": {"min": 5, "max": 20, "step": 5}}   闭区间步进
 *
 * 参数键必须命中 config('optimization.strategies.{code}') 白名单；
 * 笛卡尔积超过 config('optimization.max_combinations') 直接拒绝（不静默截断）。
 */
final class ParameterGrid
{
    /**
     * @param  array<string, mixed>  $grid
     * @return list<array<string, mixed>>
     */
    public static function expand(array $grid, string $strategyCode): array
    {
        $whitelist = (array) config("optimization.strategies.{$strategyCode}", []);
        if ($whitelist === []) {
            throw new \InvalidArgumentException("策略 {$strategyCode} 未配置可寻优参数");
        }

        if ($grid === []) {
            throw new \InvalidArgumentException('grid 不能为空');
        }

        $axes = [];
        foreach ($grid as $key => $spec) {
            $key = (string) $key;
            if (! in_array($key, $whitelist, true)) {
                throw new \InvalidArgumentException(
                    sprintf('参数 %s 不在策略 %s 的寻优白名单内（允许：%s）', $key, $strategyCode, implode('/', $whitelist))
                );
            }
            $axes[$key] = self::axisValues($key, $spec);
        }

        $combinations = [[]];
        foreach ($axes as $key => $values) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $next[] = $combination + [$key => $value];
                }
            }
            $combinations = $next;
        }

        $max = (int) config('optimization.max_combinations', 200);
        if (count($combinations) > $max) {
            throw new \InvalidArgumentException(
                sprintf('参数组合数 %d 超过上限 %d，请收窄网格', count($combinations), $max)
            );
        }

        return $combinations;
    }

    /**
     * @return list<int|float|string|bool>
     */
    private static function axisValues(string $key, mixed $spec): array
    {
        if (is_array($spec) && array_is_list($spec)) {
            $spec = ['values' => $spec];
        }

        if (! is_array($spec)) {
            throw new \InvalidArgumentException("参数 {$key} 的轴定义须为数组");
        }

        if (array_key_exists('values', $spec)) {
            $values = array_values((array) $spec['values']);
            if ($values === []) {
                throw new \InvalidArgumentException("参数 {$key} 的 values 不能为空");
            }

            return $values;
        }

        if (! isset($spec['min'], $spec['max'], $spec['step'])) {
            throw new \InvalidArgumentException("参数 {$key} 须提供 values，或 min/max/step 三元组");
        }

        $min = $spec['min'];
        $max = $spec['max'];
        $step = $spec['step'];

        if (! is_numeric($min) || ! is_numeric($max) || ! is_numeric($step)) {
            throw new \InvalidArgumentException("参数 {$key} 的 min/max/step 须为数值");
        }

        if ((float) $step <= 0.0) {
            throw new \InvalidArgumentException("参数 {$key} 的 step 必须 > 0");
        }

        if ((float) $min > (float) $max) {
            throw new \InvalidArgumentException("参数 {$key} 的 min 不能大于 max");
        }

        $maxValues = (int) config('optimization.max_combinations', 200);
        $values = [];
        $integerAxis = self::isInteger($min) && self::isInteger($step);

        for ($value = (float) $min; $value <= (float) $max + 1e-9; $value += (float) $step) {
            $values[] = $integerAxis ? (int) round($value) : self::decimal($value);
            if (count($values) > $maxValues) {
                throw new \InvalidArgumentException("参数 {$key} 的步进取值超过上限 {$maxValues}");
            }
        }

        return $values;
    }

    private static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
    }

    /**
     * 浮点转十进制字符串（避免 0.30000000000000004 之类尾差）。
     */
    private static function decimal(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
