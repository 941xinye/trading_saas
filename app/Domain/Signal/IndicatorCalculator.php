<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;

/**
 * 技术指标计算器（纯函数，无 IO）。
 *
 * 供两处复用：
 *  - PromptBuilder：把指标快照拼进 AI prompt
 *  - RuleEngineFallback：AI 不可用时的确定性规则判断
 *
 * 边界约定（架构 §9.6 / P2 POC）：
 *  - 指标只用于信号判断、不涉及资金，允许使用 float（bcmath 无法做开方/指数）
 *  - 输入 bar 必须按 openTime 升序；至少需要 max(ma 长周期, boll/atr 等) 根
 *  - 输出统一 round，避免 float 长尾进入 prompt / 持久化
 *
 * 指标算法：
 *  - MA：简单移动平均；EMA：前 period 根 SMA 播种后递推
 *  - RSI：Wilder 平滑（14）
 *  - MACD：DIF=EMA12-EMA26，DEA=EMA9(DIF)，HIST=DIF-DEA（同时给 prev_hist 判反转）
 *  - BOLL：SMA20 ± 2 倍总体标准差
 *  - ATR：Wilder 平滑（14）
 */
class IndicatorCalculator
{
    private const ROUND = 4;

    /**
     * 计算最新一根 bar 的全部指标。
     *
     * @param ExchangeKline[]   $bars 升序 K 线（建议 ≥120 根以覆盖 MA99）
     * @param array<string,mixed>|null $config 取 config('ai.fallback.indicators')，null 时内部读
     *
     * @return array{
     *   close:float|null,
     *   ma:array<int,array{v:float|null,prev:float|null}>,
     *   rsi:float|null,
     *   macd:array{dif:float|null,dea:float|null,hist:float|null,hist_prev:float|null},
     *   boll:array{upper:float|null,middle:float|null,lower:float|null},
     *   atr:float|null
     * }
     */
    public function compute(array $bars, ?array $config = null): array
    {
        $config ??= (array) config('ai.fallback.indicators');
        $maPeriods = array_map('intval', (array) ($config['ma_periods'] ?? [7, 25, 99]));
        $rsiPeriod = (int) ($config['rsi_period'] ?? 14);
        $macdFast = (int) ($config['macd']['fast'] ?? 12);
        $macdSlow = (int) ($config['macd']['slow'] ?? 26);
        $macdSignal = (int) ($config['macd']['signal'] ?? 9);
        $bollPeriod = (int) ($config['bollinger']['period'] ?? 20);
        $bollStdDev = (float) ($config['bollinger']['std_dev'] ?? 2);
        $atrPeriod = (int) ($config['atr_period'] ?? 14);

        $bars = array_values($bars);
        $closes = array_map(static fn (ExchangeKline $b): float => (float) $b->close, $bars);
        $highs = array_map(static fn (ExchangeKline $b): float => (float) $b->high, $bars);
        $lows = array_map(static fn (ExchangeKline $b): float => (float) $b->low, $bars);

        $n = count($bars);

        // ---- MA（当前值 + 前一根，用于金叉/死叉） ----
        $ma = [];
        foreach ($maPeriods as $p) {
            $v = $n >= $p ? $this->smaAt($closes, $n - 1, $p) : NAN;
            $prev = $n >= $p + 1 ? $this->smaAt($closes, $n - 2, $p) : NAN;
            $ma[$p] = ['v' => $this->r($v), 'prev' => $this->r($prev)];
        }

        // ---- RSI（Wilder） ----
        $rsi = $this->rsiWilder($closes, $rsiPeriod);

        // ---- MACD ----
        $emaFast = $this->emaSeries($closes, $macdFast);
        $emaSlow = $this->emaSeries($closes, $macdSlow);
        $difSeries = [];
        for ($i = 0; $i < $n; $i++) {
            if ($emaFast[$i] !== null && $emaSlow[$i] !== null) {
                $difSeries[$i] = $emaFast[$i] - $emaSlow[$i];
            }
        }
        $difSeries = array_values($difSeries); // 重新索引为连续数组（从首个双 EMA 有效位起）
        $deaSeries = $this->emaSeries($difSeries, $macdSignal);
        $difLast = $difSeries[count($difSeries) - 1] ?? NAN;
        $deaLast = $deaSeries[count($deaSeries) - 1] ?? NAN;
        $histLast = is_finite($difLast) && is_finite($deaLast) ? $difLast - $deaLast : NAN;
        $histPrev = NAN;
        if (count($difSeries) >= 2 && isset($deaSeries[count($deaSeries) - 2])) {
            $histPrev = $difSeries[count($difSeries) - 2] - $deaSeries[count($deaSeries) - 2];
        }

        // ---- BOLL ----
        $middle = $n >= $bollPeriod ? $this->smaAt($closes, $n - 1, $bollPeriod) : NAN;
        $upper = $lower = NAN;
        if (is_finite($middle)) {
            $slice = array_slice($closes, $n - $bollPeriod, $bollPeriod);
            $variance = 0.0;
            foreach ($slice as $c) {
                $variance += ($c - $middle) ** 2;
            }
            $std = sqrt($variance / $bollPeriod); // 总体标准差
            $upper = $middle + $bollStdDev * $std;
            $lower = $middle - $bollStdDev * $std;
        }

        // ---- ATR（Wilder） ----
        $atr = $this->atrWilder($highs, $lows, $closes, $atrPeriod);

        return [
            'close' => $this->r($closes[$n - 1] ?? NAN),
            'ma' => $ma,
            'rsi' => $this->r($rsi),
            'macd' => [
                'dif' => $this->r($difLast),
                'dea' => $this->r($deaLast),
                'hist' => $this->r($histLast),
                'hist_prev' => $this->r($histPrev),
            ],
            'boll' => [
                'upper' => $this->r($upper),
                'middle' => $this->r($middle),
                'lower' => $this->r($lower),
            ],
            'atr' => $this->r($atr),
        ];
    }

    // ------------------------------------------------------------------
    // 算法
    // ------------------------------------------------------------------

    /**
     * 截至 $endIdx（含）最后 $period 个值的简单平均。
     *
     * @param float[] $values
     */
    private function smaAt(array $values, int $endIdx, int $period): float
    {
        if ($endIdx + 1 < $period) {
            return NAN;
        }
        $sum = 0.0;
        for ($i = $endIdx - $period + 1; $i <= $endIdx; $i++) {
            $sum += $values[$i];
        }

        return $sum / $period;
    }

    /**
     * EMA 序列：前 $period 个有效位置返回 null（用第 $period 根的 SMA 播种）。
     *
     * @param float[] $values
     *
     * @return (float|null)[] 与输入等长、同索引
     */
    private function emaSeries(array $values, int $period): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($n < $period) {
            return $out;
        }

        $seed = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $seed += $values[$i];
        }
        $seed /= $period;
        $out[$period - 1] = $seed;

        $multiplier = 2 / ($period + 1);
        $prev = $seed;
        for ($i = $period; $i < $n; $i++) {
            $prev = ($values[$i] - $prev) * $multiplier + $prev;
            $out[$i] = $prev;
        }

        return $out;
    }

    /**
     * Wilder RSI。
     *
     * @param float[] $closes
     */
    private function rsiWilder(array $closes, int $period): float
    {
        $n = count($closes);
        if ($n < $period + 1) {
            return NAN;
        }

        $avgGain = 0.0;
        $avgLoss = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $avgGain += max($diff, 0.0);
            $avgLoss += max(-$diff, 0.0);
        }
        $avgGain /= $period;
        $avgLoss /= $period;

        for ($i = $period + 1; $i < $n; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gain = max($diff, 0.0);
            $loss = max(-$diff, 0.0);
            $avgGain = ($avgGain * ($period - 1) + $gain) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $loss) / $period;
        }

        if ($avgLoss == 0.0) {
            return $avgGain == 0.0 ? 50.0 : 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return 100.0 - (100.0 / (1.0 + $rs));
    }

    /**
     * Wilder ATR。
     *
     * @param float[] $highs
     * @param float[] $lows
     * @param float[] $closes
     */
    private function atrWilder(array $highs, array $lows, array $closes, int $period): float
    {
        $n = count($closes);
        if ($n < $period + 1) {
            return NAN;
        }

        $trs = [];
        for ($i = 1; $i < $n; $i++) {
            $trs[] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1]),
            );
        }

        // 前 period 个 TR（对应原索引 1..period）SMA 播种
        $atr = array_sum(array_slice($trs, 0, $period)) / $period;
        for ($i = $period, $m = count($trs); $i < $m; $i++) {
            $atr = ($atr * ($period - 1) + $trs[$i]) / $period;
        }

        return $atr;
    }

    private function r(float $v): ?float
    {
        return is_finite($v) ? round($v, self::ROUND) : null;
    }
}
