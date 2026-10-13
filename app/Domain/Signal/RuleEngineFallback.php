<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Signal\Contracts\Signal;

/**
 * AI 失败兜底规则引擎（确定性、无 IO、可单测）。
 *
 * 触发场景（架构 §9.3）：
 *  - AIPrompter 抛 AiUnavailableException（超时/网络/鉴权/JSON 失败）
 *  - 租户 AI 限流命中（fallback_on_throttle=true）
 *
 * 打分模型（多空对称，单根 bar 信号）：
 *  +2  MA7 上穿 MA25（金叉）            -2 MA7 下穿 MA25（死叉）
 *  +1  MA7>MA25>MA99 多头排列（无交叉时） -1 空头排列
 *  +2  RSI<30 超卖（均值回归做多）        -2 RSI>70 超买
 *  +2  MACD 柱由负转正                  -2 MACD 柱由正转负
 *  +1  MACD 红柱（无反转时）             -1 绿柱
 *  +1  收盘跌破 BOLL 下轨               -1 涨破上轨
 *
 * 阈值：score ≥ 3 → long；≤ -3 → short；其余 neutral（证据不足不强行给方向）。
 * 止盈止损：ATR(14) 的 1.5 倍止损 / 2.25 倍止盈（盈亏比 1.5）；ATR 无效时退化 0.5%/0.75%。
 *
 * 产出 Signal.source='rule_fallback'，confidence=60（固定，区别于 AI 的主观置信度）。
 */
class RuleEngineFallback
{
    private const SCORE_THRESHOLD = 3;

    /**
     * @param ExchangeKline $latestBar 最新已闭 bar
     * @param array<string,mixed> $context 必须含 indicators（IndicatorCalculator::compute 结果）
     */
    public function analyze(ExchangeKline $latestBar, array $context): Signal
    {
        $ind = (array) ($context['indicators'] ?? []);
        $score = 0;
        /** @var string[] */
        $triggers = [];

        $close = (float) $latestBar->close;

        // ---- MA 金叉/死叉 + 排列 ----
        $ma = (array) ($ind['ma'] ?? []);
        $ma7 = $ma[7]['v'] ?? null;
        $ma7p = $ma[7]['prev'] ?? null;
        $ma25 = $ma[25]['v'] ?? null;
        $ma25p = $ma[25]['prev'] ?? null;
        $ma99 = $ma[99]['v'] ?? null;

        if ($this->isNum($ma7p) && $this->isNum($ma25p) && $this->isNum($ma7) && $this->isNum($ma25)) {
            if ($ma7p <= $ma25p && $ma7 > $ma25) {
                $score += 2;
                $triggers[] = 'MA7上穿MA25金叉';
            } elseif ($ma7p >= $ma25p && $ma7 < $ma25) {
                $score -= 2;
                $triggers[] = 'MA7下穿MA25死叉';
            } elseif ($this->isNum($ma99) && $ma7 > $ma25 && $ma25 > $ma99) {
                $score += 1;
                $triggers[] = 'MA多头排列';
            } elseif ($this->isNum($ma99) && $ma7 < $ma25 && $ma25 < $ma99) {
                $score -= 1;
                $triggers[] = 'MA空头排列';
            }
        }

        // ---- RSI ----
        $rsi = $ind['rsi'] ?? null;
        if ($this->isNum($rsi)) {
            if ($rsi < 30) {
                $score += 2;
                $triggers[] = sprintf('RSI=%.1f超卖', $rsi);
            } elseif ($rsi > 70) {
                $score -= 2;
                $triggers[] = sprintf('RSI=%.1f超买', $rsi);
            }
        }

        // ---- MACD 柱反转 ----
        $macd = (array) ($ind['macd'] ?? []);
        $hist = $macd['hist'] ?? null;
        $histPrev = $macd['hist_prev'] ?? null;
        if ($this->isNum($hist) && $this->isNum($histPrev)) {
            if ($hist > 0 && $histPrev <= 0) {
                $score += 2;
                $triggers[] = 'MACD柱负转正';
            } elseif ($hist < 0 && $histPrev >= 0) {
                $score -= 2;
                $triggers[] = 'MACD柱正转负';
            } elseif ($hist > 0) {
                $score += 1;
                $triggers[] = 'MACD红柱';
            } else {
                $score -= 1;
                $triggers[] = 'MACD绿柱';
            }
        }

        // ---- BOLL ----
        $boll = (array) ($ind['boll'] ?? []);
        $lower = $boll['lower'] ?? null;
        $upper = $boll['upper'] ?? null;
        if ($this->isNum($lower) && $close < $lower) {
            $score += 1;
            $triggers[] = '收盘破布林下轨';
        } elseif ($this->isNum($upper) && $close > $upper) {
            $score -= 1;
            $triggers[] = '收盘破布林上轨';
        }

        // ---- 决策 ----
        if ($score >= self::SCORE_THRESHOLD) {
            return $this->buildSignal($latestBar, 'long', $score, $triggers, $ind);
        }
        if ($score <= -self::SCORE_THRESHOLD) {
            return $this->buildSignal($latestBar, 'short', $score, $triggers, $ind);
        }

        return new Signal(
            symbol: $latestBar->symbol,
            interval: $latestBar->interval,
            direction: 'neutral',
            entryPrice: $this->price($close),
            exitPrice: null,
            stopLoss: null,
            confidence: 30,
            rationale: sprintf('规则引擎信号不足（score=%d：%s），观望', $score, $triggers ? implode('、', $triggers) : '指标缺失'),
            source: 'rule_fallback',
            rawPayload: ['score' => $score, 'triggers' => $triggers],
        );
    }

    /**
     * @param string[] $triggers
     * @param array<string,mixed> $ind
     */
    private function buildSignal(ExchangeKline $bar, string $direction, int $score, array $triggers, array $ind): Signal
    {
        $entry = (float) $bar->close;
        $atr = $ind['atr'] ?? null;

        if (! $this->isNum($atr) || $atr <= 0) {
            // ATR 不可用时按百分比退化
            $stopDistance = $entry * 0.005;
            $targetDistance = $entry * 0.0075;
        } else {
            $stopDistance = $atr * 1.5;
            $targetDistance = $atr * 2.25;
        }

        if ($direction === 'long') {
            $exit = $entry + $targetDistance;
            $stop = $entry - $stopDistance;
        } else {
            $exit = $entry - $targetDistance;
            $stop = $entry + $stopDistance;
        }

        return new Signal(
            symbol: $bar->symbol,
            interval: $bar->interval,
            direction: $direction,
            entryPrice: $this->price($entry),
            exitPrice: $this->price($exit),
            stopLoss: $this->price($stop),
            confidence: 60,
            rationale: sprintf(
                '%s（score=%d：%s；ATR=%.4g）',
                $direction === 'long' ? '规则引擎做多' : '规则引擎做空',
                $score,
                implode('、', $triggers) ?: '无',
                $this->isNum($atr) ? $atr : 0.0,
            ),
            source: 'rule_fallback',
            rawPayload: ['score' => $score, 'triggers' => $triggers, 'atr' => $atr],
        );
    }

    private function isNum(mixed $v): bool
    {
        return is_numeric($v) && is_finite((float) $v);
    }

    private function price(float $v): string
    {
        return number_format($v, 8, '.', '');
    }
}
