<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Domain\Risk\Contracts\RiskDecision;
use App\Domain\Risk\Contracts\RiskGuard as RiskGuardContract;
use App\Domain\Risk\Contracts\RiskRule;

/**
 * 风控守卫实现。
 *
 * 编排多个 RiskRule（单笔/日内/回撤/连亏）+ CircuitBreaker 状态机。
 * 在 Strategy.shouldOpen 与 ExchangeAdapter.placeOrder 之间拦截。
 *
 * 流程：
 *  1. check() 先读 BreakerMetrics 快照 → 4 个 Rule 顺序检查
 *  2. 任一 Rule 触发 → CircuitBreaker.trip() 转 OPEN → 返回 RiskDecision(allowed=false)
 *  3. 熔断状态下 check() 直接拒绝（仅放行 close/probe 动作）
 *  4. recordTrade() 累计指标；attemptRecovery() 推进 HALF_OPEN → CLOSED
 *
 * 关键约束：仅业务亏损触发熔断，API 异常不触发。
 */
class RiskGuard implements RiskGuardContract
{
    /**
     * @param RiskRule[]          $rules        4 维度规则集合
     * @param CircuitBreaker      $breaker      熔断状态机
     */
    public function __construct(
        private readonly array $rules,
        private readonly CircuitBreaker $breaker,
    ) {
    }

    public function check(int $instanceId, string $action, array $payload = []): RiskDecision
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }

    public function recordTrade(int $instanceId, ExchangeOrder $fill): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }

    public function attemptRecovery(int $instanceId): bool
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }

    public function getBreakerState(int $instanceId): string
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }
}
