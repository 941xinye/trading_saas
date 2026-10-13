<?php

declare(strict_types=1);

namespace App\Domain\Signal\Contracts;

/**
 * AI/规则引擎产出的信号 DTO。
 *
 * 与 signals 表字段对齐，source 标记 ai|rule_fallback 便于审计。
 * direction 枚举：long|short|neutral（neutral 表示观望）。
 *
 * 末尾三个持久化元信息为可选：Prompter/规则引擎产出时不填，
 * 由 SignalPipeline 落库后回填（dispatcher 需要 tenantId / signalId）。
 */
readonly class Signal
{
    public function __construct(
        public string $symbol,
        public string $interval,
        public string $direction,
        public ?string $entryPrice,
        public ?string $exitPrice,
        public ?string $stopLoss,
        public int $confidence,
        public ?string $rationale,
        /** ai|rule_fallback */
        public string $source,
        /** @var array<string,mixed>|null 原始 LLM 响应载荷，用于审计 */
        public ?array $rawPayload,
        public ?int $tenantId = null,
        public ?int $subscriptionId = null,
        public ?int $persistedId = null,
    ) {
    }

    /**
     * 落库后复制一份带持久化元信息的信号。
     */
    public function withPersistence(int $tenantId, int $subscriptionId, int $signalId): self
    {
        return new self(
            $this->symbol,
            $this->interval,
            $this->direction,
            $this->entryPrice,
            $this->exitPrice,
            $this->stopLoss,
            $this->confidence,
            $this->rationale,
            $this->source,
            $this->rawPayload,
            $tenantId,
            $subscriptionId,
            $signalId,
        );
    }
}
