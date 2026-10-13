<?php

declare(strict_types=1);

namespace App\Domain\Signal\Contracts;

use RuntimeException;

/**
 * AI Provider 不可用异常。
 *
 * 由 AbstractAIPrompter 在以下场景抛出：
 *  - Redis 限流桶耗尽
 *  - HTTP 超时（30s）
 *  - 网络异常
 *  - JSON Schema 解析失败
 *  - API Key 无效
 *
 * 上层（BarClosed 监听器 / AiAnalyzeJob）捕获后走 RuleEngineFallback 兜底，
 * 静默异常：report() 后再抛，不阻塞主流程。
 */
class AiUnavailableException extends RuntimeException
{
}
