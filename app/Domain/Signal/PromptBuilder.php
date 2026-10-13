<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;

/**
 * AI Prompt 模板组装器。
 *
 * 输入：最新 bar + 历史 bar 列表 + 指标计算结果
 * 输出：组装好的 prompt 文本（含上下文窗口、截断保护）
 *
 * 截断策略（架构方案 §9.3）：
 *  - 估算 token 数，超 context_limit * truncate_ratio（默认 60%）时按时间窗口裁剪历史 bar
 *  - 历史保留最近 N 条，更早的按等距采样
 *
 * 输出格式遵循架构 Context §1：
 *   {交易对} {指标周期} {开单方向} {入场价格} {出场价格}
 */
class PromptBuilder
{
    public function __construct()
    {
    }

    /**
     * 组装 prompt 文本。
     *
     * @param ExchangeKline $latestBar      最新已闭 bar
     * @param ExchangeKline[] $historicalBars 历史 bar 列表（按时间升序）
     * @param array<string,mixed> $indicators 指标计算结果（MA/RSI/MACD/Bollinger/ATR）
     */
    public function build(ExchangeKline $latestBar, array $historicalBars, array $indicators): string
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P2 POC.');
    }
}
