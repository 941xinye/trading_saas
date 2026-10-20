<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Signal\IndicatorCalculator;

/**
 * DSL 指标快照构建（tasks T008 / FR-008 / data-model §5）。
 *
 * 唯一指标来源为既有 `IndicatorCalculator::compute()`；窗口取「引用到的指标所需最小 bar 数」
 * 的最大值 + 1（覆盖 prev），上限 `indicator_window_max`；NaN→null；每 bar 至多构建一次，
 * 结果缓存于 `DslEvalContext.indicatorSnapshot`，求值时不再重复计算。
 *
 * 快照结构（`now` / `prev` 同构，`prev` 为去掉最后一根后再算一次的 shifted snapshot）：
 *   price => {open,high,low,close,last}                      // last 取 ticker，缺失回退收盘
 *   ma    => {period => {v,prev}}                            // 复用计算器内置 prev
 *   macd  => {dif,dea,hist,hist_prev}                        // hist 复用内置 hist_prev
 *   rsi   => {period => value|null}
 *   boll  => {"period|std_dev" => {upper,middle,lower}}      // 操作数 `bollinger` → 计算器键 `boll`
 *   atr   => {period => value|null}
 */
final class IndicatorSnapshotBuilder
{
    private const DEFAULT_WINDOW_MAX = 150;

    /** 各指标类型在「单次计算」下所需的基础 bar 数（未含 prev）。 */
    private const BASE_BARS = [
        'ma' => 1,
        'rsi' => 2,
        'atr' => 2,
        'bollinger' => 1,
        'macd' => 35, // slow 26 + signal 9
    ];

    private IndicatorCalculator $calculator;

    private int $windowMax;

    public function __construct(?IndicatorCalculator $calculator = null, ?int $windowMax = null)
    {
        $this->calculator = $calculator ?? new IndicatorCalculator();
        $this->windowMax = $windowMax ?? self::resolveWindowMax();
    }

    /**
     * @param ExchangeKline[] $recentBars 升序已闭 bar
     *
     * @return array{now:array<string,mixed>,prev:array<string,mixed>|null}
     */
    public function build(DslProgram $program, array $recentBars, ?ExchangeTicker $ticker = null): array
    {
        $bars = array_values($recentBars);
        $window = $this->requiredWindow($program);
        if (count($bars) > $window) {
            $bars = array_slice($bars, -$window);
        }

        $now = $this->snapshotFor($program, $bars, $ticker);
        $prev = count($bars) >= 2
            ? $this->snapshotFor($program, array_slice($bars, 0, -1), null)
            : null;

        return ['now' => $now, 'prev' => $prev];
    }

    /**
     * 窗口长度 = max(引用指标所需 bar 数) + 1（覆盖 prev），上限 `indicator_window_max`（data-model §5）。
     */
    public function requiredWindow(DslProgram $program): int
    {
        $needed = 2; // 至少 2 根才有 prev

        foreach ($this->operands($program) as $operand) {
            if ($operand->type !== 'indicator' || $operand->indicator === null) {
                continue;
            }
            $base = self::BASE_BARS[$operand->indicator] ?? 1;
            $needed = max($needed, $base + (int) ($operand->period ?? 0));
        }

        return min($needed + 1, $this->windowMax);
    }

    /**
     * @param ExchangeKline[] $bars
     *
     * @return array<string,mixed>
     */
    private function snapshotFor(DslProgram $program, array $bars, ?ExchangeTicker $ticker): array
    {
        $maPeriods = $this->scalarPeriods($program, 'ma');
        $rsiPeriods = $this->scalarPeriods($program, 'rsi');
        $atrPeriods = $this->scalarPeriods($program, 'atr');
        $bollConfigs = $this->bollConfigs($program);
        $macdUsed = $this->uses($program, 'macd');

        $snapshot = [
            'price' => $this->priceBlock($bars, $ticker),
            'ma' => [],
            'macd' => ['dif' => null, 'dea' => null, 'hist' => null, 'hist_prev' => null],
            'rsi' => [],
            'boll' => [],
            'atr' => [],
        ];

        if ($maPeriods === [] && $rsiPeriods === [] && $atrPeriods === [] && $bollConfigs === [] && ! $macdUsed) {
            return $snapshot; // 无指标引用：仅价格可用，免一次计算
        }

        foreach ($this->variants($rsiPeriods, $atrPeriods, $bollConfigs) as $variant) {
            $config = [
                'ma_periods' => $maPeriods,
                'rsi_period' => $variant['rsi'] ?? 14,
                'atr_period' => $variant['atr'] ?? 14,
                'bollinger' => [
                    'period' => $variant['boll']['period'] ?? 20,
                    'std_dev' => $variant['boll']['std_dev'] ?? 2.0,
                ],
            ];

            $out = $this->calculator->compute($bars, $config);

            foreach ($maPeriods as $period) {
                if (! array_key_exists($period, $snapshot['ma']) && array_key_exists($period, $out['ma'])) {
                    $snapshot['ma'][$period] = $out['ma'][$period];
                }
            }

            if ($macdUsed && $snapshot['macd']['hist'] === null) {
                $snapshot['macd'] = $out['macd'];
            }

            if ($variant['rsi'] !== null && ! array_key_exists($variant['rsi'], $snapshot['rsi'])) {
                $snapshot['rsi'][$variant['rsi']] = $out['rsi'];
            }

            if ($variant['atr'] !== null && ! array_key_exists($variant['atr'], $snapshot['atr'])) {
                $snapshot['atr'][$variant['atr']] = $out['atr'];
            }

            if ($variant['boll'] !== null) {
                $key = $this->bollKey($variant['boll']['period'], $variant['boll']['std_dev']);
                if (! array_key_exists($key, $snapshot['boll'])) {
                    $snapshot['boll'][$key] = $out['boll']; // 计算器输出键 `boll` ↔ 操作数 `bollinger`
                }
            }
        }

        return $snapshot;
    }

    /**
     * @param ExchangeKline[] $bars
     *
     * @return array{open:float|null,high:float|null,low:float|null,close:float|null,last:float|null}
     */
    private function priceBlock(array $bars, ?ExchangeTicker $ticker): array
    {
        $last = $bars === [] ? null : $bars[count($bars) - 1];
        $close = $this->num($last?->close);

        return [
            'open' => $this->num($last?->open),
            'high' => $this->num($last?->high),
            'low' => $this->num($last?->low),
            'close' => $close,
            'last' => $ticker !== null ? $this->num($ticker->lastPrice) : $close,
        ];
    }

    /**
     * 去重后的计算变体（(rsi 周期, atr 周期, boll 配置) 组合）；无标量指标时返回单一空变体。
     *
     * @param int[]                          $rsiPeriods
     * @param int[]                          $atrPeriods
     * @param list<array{period:int,std_dev:string}> $bollConfigs
     *
     * @return list<array{rsi:int|null,atr:int|null,boll:array{period:int,std_dev:string}|null}>
     */
    private function variants(array $rsiPeriods, array $atrPeriods, array $bollConfigs): array
    {
        $rsi = $rsiPeriods === [] ? [null] : $rsiPeriods;
        $atr = $atrPeriods === [] ? [null] : $atrPeriods;
        $boll = $bollConfigs === [] ? [null] : $bollConfigs;

        $variants = [];
        foreach ($rsi as $r) {
            foreach ($atr as $a) {
                foreach ($boll as $b) {
                    $variants[] = ['rsi' => $r, 'atr' => $a, 'boll' => $b];
                }
            }
        }

        return $variants;
    }

    /**
     * @return int[] 某指标类型引用的全部 period（去重升序）
     */
    private function scalarPeriods(DslProgram $program, string $indicator): array
    {
        $periods = [];
        foreach ($this->operands($program) as $operand) {
            if ($operand->type === 'indicator' && $operand->indicator === $indicator && $operand->period !== null) {
                $periods[$operand->period] = $operand->period;
            }
        }
        ksort($periods);

        return array_values($periods);
    }

    /**
     * @return list<array{period:int,std_dev:string}>
     */
    private function bollConfigs(DslProgram $program): array
    {
        $configs = [];
        foreach ($this->operands($program) as $operand) {
            if ($operand->type === 'indicator' && $operand->indicator === 'bollinger' && $operand->period !== null) {
                $key = $this->bollKey($operand->period, $operand->stdDev ?? '2');
                $configs[$key] = ['period' => $operand->period, 'std_dev' => $operand->stdDev ?? '2'];
            }
        }

        return array_values($configs);
    }

    private function uses(DslProgram $program, string $indicator): bool
    {
        foreach ($this->operands($program) as $operand) {
            if ($operand->type === 'indicator' && $operand->indicator === $indicator) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return OperandNode[]
     */
    private function operands(DslProgram $program): array
    {
        $out = [];
        foreach ($program->filters as $rule) {
            $this->collect($rule->condition, $out);
        }
        foreach ($program->entries as $rule) {
            $this->collect($rule->condition, $out);
        }
        foreach ($program->exits as $rule) {
            $this->collect($rule->condition, $out);
        }

        return $out;
    }

    /**
     * @param OperandNode[] $out
     */
    private function collect(ConditionNode $node, array &$out): void
    {
        if ($node->left !== null) {
            $out[] = $node->left;
        }
        if ($node->right !== null) {
            $out[] = $node->right;
        }
        foreach ($node->operands as $child) {
            $this->collect($child, $out);
        }
        if ($node->operand !== null) {
            $this->collect($node->operand, $out);
        }
    }

    private function bollKey(int $period, string $stdDev): string
    {
        return $period.'|'.$stdDev;
    }

    private function num(?string $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private static function resolveWindowMax(): int
    {
        try {
            $value = config('strategy.dsl.indicator_window_max');
        } catch (\Throwable) {
            $value = null;
        }

        return is_numeric($value) ? (int) $value : self::DEFAULT_WINDOW_MAX;
    }
}
