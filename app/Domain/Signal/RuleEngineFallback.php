<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Signal\Contracts\Signal;

/**
 * AI 失败兜底规则引擎。
 *
 * 触发场景：
 *  - AIPrompter.analyze() 抛 AiUnavailableException
 *  - 限流桶耗尽（fallback_on_throttle=true）
 *  - LLM JSON Schema 解析失败
 *
 * 产出：基于技术指标的确定性 Signal：
 *  - MA7/MA25/MA99 金叉死叉 → long/short
 *  - RSI>70 超买 / RSI<30 超卖
 *  - MACD 柱状图反转
 *  - Bollinger 突破上下轨
 *  - ATR 用于止损位计算
 *
 * 输出 Signal.source = 'rule_fallback'，便于审计区分。
 */
class RuleEngineFallback
{
    public function __construct()
    {
    }

    /**
     * 兜底分析。
     *
     * @param ExchangeKline $latestBar 最新已闭 bar
     * @param array<string,mixed> $context 上下文：historical_bars / indicators 等
     */
    public function analyze(ExchangeKline $latestBar, array $context): Signal
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P2 POC.');
    }
}
