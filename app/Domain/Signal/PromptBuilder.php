<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;

/**
 * AI Prompt 模板组装器。
 *
 * 输入：最新 bar + 历史 bar 列表 + 指标计算结果
 * 输出：systemPrompt() 固定角色与 JSON 契约；build() 组装 user 文本
 *
 * 截断策略（架构 §9.3）：
 *  - 估算混合中英文本 token（保守按 2 字符/token）
 *  - 超 context_limit * truncate_ratio（默认 60%）时按 60→40→30→20 根递减历史 bar
 *  - 指标快照与最新 bar 永远保留
 *
 * 输出 JSON 契约（与 signals 表 / Signal DTO 对齐）：
 *  {"direction":"long|short|neutral","entry_price":number,
 *   "exit_price":number|null,"stop_loss":number|null,
 *   "confidence":0-100,"rationale":"≤120字"}
 */
class PromptBuilder
{
    /** 历史 bar 最多拼入根数（超出按此为起点递减裁剪） */
    private const MAX_BARS = 60;

    /** 兜底最小保留根数（低于此数不再裁剪，指标本身已含长周期信息） */
    private const MIN_BARS = 20;

    public function systemPrompt(): string
    {
        return <<<'TXT'
你是一名严谨的数字货币现货量化分析助手。基于给定的 K 线与技术指标做短线方向判断，禁止使用 K 线之外的任何信息。

只输出一个 JSON 对象（不要输出 Markdown、代码块或任何解释文字），字段如下：
{
  "direction": "long | short | neutral",
  "entry_price": number,          // 建议入场价（正数）
  "exit_price": number | null,   // 目标止盈价（正数），neutral 时为 null
  "stop_loss": number | null,    // 止损价（正数），neutral 时为 null
  "confidence": integer,          // 0-100 的整数置信度
  "rationale": "string"           // 不超过 120 字的中文理由，必须引用具体指标
}

规则：
1. 信号方向必须由指标共振支撑（MA 排列/金叉死叉、RSI 超买超卖、MACD 柱反转、布林突破中至少两项）。
2. 证据不足或多空矛盾时输出 neutral，不要强行给方向。
3. entry_price 参考最新收盘价；long 的 exit > entry > stop，short 反之。
4. confidence 反映把握程度：强共振 ≥75，一般共振 50-74，勉强信号 <50；neutral 时给 ≤30。
TXT;
    }

    /**
     * 组装 user prompt。
     *
     * @param ExchangeKline   $latestBar      最新已闭 bar
     * @param ExchangeKline[] $historicalBars 历史 bar（升序；含 latest 与否都可，内部去尾）
     * @param array<string,mixed> $indicators IndicatorCalculator::compute() 结果
     * @param array{context_limit?:int,truncate_ratio?:float} $limits 上下文限制
     */
    public function build(
        ExchangeKline $latestBar,
        array $historicalBars,
        array $indicators,
        array $limits = [],
    ): string {
        $contextLimit = (int) ($limits['context_limit'] ?? 32000);
        $ratio = (float) ($limits['truncate_ratio'] ?? 0.6);
        $charBudget = (int) floor($contextLimit * $ratio * 2); // 保守 2 字符/token

        $header = $this->headerBlock($latestBar);
        $indicatorBlock = "技术指标（最新一根）：\n" . $this->json($indicators) . "\n\n";
        $footer = "请输出 JSON：";

        $take = min(self::MAX_BARS, max(self::MIN_BARS, count($historicalBars)));
        $barsBlock = '';
        for (;;) {
            $slice = array_slice(array_values($historicalBars), -$take);
            $barsBlock = $this->barsBlock($slice);
            $totalLen = mb_strlen($header) + mb_strlen($indicatorBlock) + mb_strlen($barsBlock) + mb_strlen($footer);
            if ($totalLen <= $charBudget || $take <= self::MIN_BARS) {
                break;
            }
            $take -= 10;
            if ($take < self::MIN_BARS) {
                $take = self::MIN_BARS;
            }
        }

        return $header . $indicatorBlock . $barsBlock . $footer;
    }

    private function headerBlock(ExchangeKline $bar): string
    {
        $closeUtc = gmdate('Y-m-d H:i:s', intdiv($bar->closeTime, 1000));

        return sprintf(
            "交易对：%s\n周期：%s\n最新已闭 K 线（UTC close=%s）：\n%s\n\n",
            $bar->symbol,
            $bar->interval,
            $closeUtc,
            $this->json([
                'open_time_utc' => gmdate('Y-m-d H:i:s', intdiv($bar->openTime, 1000)),
                'open' => $bar->open,
                'high' => $bar->high,
                'low' => $bar->low,
                'close' => $bar->close,
                'volume' => $bar->volume,
            ]),
        );
    }

    /**
     * 历史 bar 精简序列：每行 [UTC时间, O,H,L,C,V]，最旧→最新。
     *
     * @param ExchangeKline[] $bars
     */
    private function barsBlock(array $bars): string
    {
        if ($bars === []) {
            return '';
        }

        $lines = ['最近 ' . count($bars) . ' 根 K 线（[UTC, O, H, L, C, V]，最旧→最新）：'];
        foreach ($bars as $b) {
            $lines[] = sprintf(
                '[%s, %s, %s, %s, %s, %s]',
                gmdate('m-d H:i', intdiv($b->openTime, 1000)),
                $b->open,
                $b->high,
                $b->low,
                $b->close,
                $b->volume,
            );
        }

        return implode("\n", $lines) . "\n\n";
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
