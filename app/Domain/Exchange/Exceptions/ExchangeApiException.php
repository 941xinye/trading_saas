<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Exceptions;

use RuntimeException;

/**
 * 交易所 API 异常。
 *
 * 可降级 vs 硬失败分类（架构 §5.1）：
 *  - retryable=true（网络超时/5xx/限流）：可重试，可走缓存兜底
 *  - retryable=false（签名错误/参数错误/余额不足）：硬失败，重试无意义
 *
 * 注意：API 异常不计入熔断计数（仅业务亏损触发熔断，config('risk.count_api_errors')=false）。
 */
class ExchangeApiException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        private readonly bool $retryable = false,
        private readonly ?array $context = null,
    ) {
        parent::__construct($message, $code);
    }

    /**
     * 是否可重试（网络类/服务端类错误）。
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    // ------------------------------------------------------------------
    // 语义化命名构造器
    // ------------------------------------------------------------------

    public static function rateLimited(string $exchange, string $endpoint): self
    {
        return new self("[{$exchange}] rate limited: {$endpoint}", 429, retryable: true);
    }

    public static function networkError(string $exchange, string $endpoint, string $detail): self
    {
        return new self("[{$exchange}] network error on {$endpoint}: {$detail}", 0, retryable: true);
    }

    public static function serverError(string $exchange, string $endpoint, string $body): self
    {
        return new self("[{$exchange}] server error on {$endpoint}: {$body}", 500, retryable: true);
    }

    public static function clientError(string $exchange, string $endpoint, int $status, string $body): self
    {
        return new self("[{$exchange}] client error on {$endpoint} ({$status}): {$body}", $status, retryable: false);
    }

    /**
     * 附加调试上下文（endpoint/响应体等）。
     */
    public function context(): ?array
    {
        return $this->context;
    }
}
