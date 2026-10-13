<?php

declare(strict_types=1);

namespace App\Domain\Signal\Prompters;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\RateLimiter;
use App\Domain\Signal\Contracts\AIPrompter;
use App\Domain\Signal\Contracts\Signal;

/**
 * AI Prompter 抽象基类。
 *
 * 公共能力（P2 POC 落地）：
 *  - Redis 令牌桶限流（按 tenant:provider 维度，超限直接抛 AiUnavailableException）
 *  - prompt 截断（超 context_limit 60% 时按时间窗口裁剪历史 bar）
 *  - 30s 超时（HTTP 客户端 timeout）
 *  - 静默异常：report() 后抛 AiUnavailableException
 *  - JSON Schema 解析容错（重试 + 校验）
 *
 * 子类（OpenAI/DeepSeek/Qwen/LocalOllama）实现 HTTP 调用细节。
 *
 * 注意：本类注入的是 App\Domain\Exchange\RateLimiter（交易所限流器），
 * 内部使用其 tryAcquire() 方法做 AI 调用限流（key 维度不同但机制复用）。
 * P2 POC 落地时若发现需要差异化，可拆出独立 AiRateLimiter。
 */
abstract class AbstractAIPrompter implements AIPrompter
{
    public function __construct(
        protected readonly RateLimiter $rateLimiter,
    ) {
    }

    public function analyze(ExchangeKline $latestBar, array $context): Signal
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P2 POC.');
    }
}
