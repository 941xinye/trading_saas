<?php

declare(strict_types=1);

namespace App\Domain\Signal\Contracts;

use App\Domain\Exchange\Contracts\ExchangeKline;

/**
 * AI 信号提示器接口契约。
 *
 * 输入：最新已闭 bar + 上下文（历史 bar / 指标 / 持仓等）
 * 输出：Signal DTO（含方向/入场/出场/止损/置信度/理由）
 *
 * 失败语义：抛 AiUnavailableException，上层（BarClosed 监听器）走 RuleEngineFallback 兜底。
 * 静默异常：在 AbstractAIPrompter 内 report() 后再抛，不阻塞主流程。
 */
interface AIPrompter
{
    /**
     * 分析最新 bar 产出信号。
     *
     * @param ExchangeKline $latestBar 最新已闭 K 线
     * @param array<string,mixed> $context 上下文：historical_bars / indicators / position 等
     *
     * @throws \App\Domain\Signal\Contracts\AiUnavailableException AI 不可用时（限流/超时/网络/JSON 解析失败）
     */
    public function analyze(ExchangeKline $latestBar, array $context): Signal;

    /**
     * Prompter 名称，对应 config/ai.php providers 的 key。
     *
     * @return string openai|deepseek|qwen|local
     */
    public function name(): string;

    /**
     * 当前使用的模型标识（用于审计/计费）。
     */
    public function model(): string;
}
