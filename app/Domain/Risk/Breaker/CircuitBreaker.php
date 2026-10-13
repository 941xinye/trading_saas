<?php

declare(strict_types=1);

namespace App\Domain\Risk\Breaker;

/**
 * 熔断状态机。
 *
 * 三态转换：
 *   CLOSED ─触发─► OPEN ─冷却到─► HALF_OPEN ─探测盈利─► CLOSED
 *                                       └─探测亏损─► OPEN（重置计时）
 *
 * 状态存储：Redis Hash `tenant:{tid}:breaker:{iid}`
 * 字段：state / opened_at / triggered_rule / triggered_value / attempts / next_attempt_at
 *
 * 关键约束：
 *  - 进程崩溃恢复从 Redis 读状态，无丢失
 *  - 冷却窗口用 Redis TTL 而非本地计时（进程崩溃后窗口仍准确）
 *  - 仅业务亏损触发熔断，API 异常不触发（count_api_errors=false）
 */
class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private readonly BreakerStateMachine $stateMachine,
    ) {
    }

    /**
     * 触发熔断：CLOSED/HALF_OPEN → OPEN。
     *
     * @param string $ruleType 触发的规则类型
     */
    public function trip(string $ruleType): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }

    /**
     * HALF_OPEN 状态下放行探测单。
     *
     * @return bool true=允许放行（达到冷却窗口），false=仍在冷却中
     */
    public function attemptProbe(): bool
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }

    /**
     * 探测成功：HALF_OPEN → CLOSED。
     */
    public function recover(): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }

    /**
     * 当前状态。
     *
     * @return string closed|open|half_open
     */
    public function state(): string
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }
}
