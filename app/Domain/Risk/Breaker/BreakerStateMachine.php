<?php

declare(strict_types=1);

namespace App\Domain\Risk\Breaker;

use RuntimeException;

/**
 * 熔断状态机合法性校验器。
 *
 * 校验 CLOSED / OPEN / HALF_OPEN 之间的状态转换是否合法：
 *   CLOSED → OPEN              合法（触发熔断）
 *   OPEN  → HALF_OPEN          合法（冷却窗口到期）
 *   HALF_OPEN → CLOSED         合法（探测盈利）
 *   HALF_OPEN → OPEN           合法（探测亏损，重置计时）
 *   其他转换                   非法
 *
 * 配合 CircuitBreaker 使用，避免非法状态转换导致数据不一致。
 */
class BreakerStateMachine
{
    /** @var array<string, string[]> from → 允许的 to 列表 */
    private const TRANSITIONS = [
        CircuitBreaker::STATE_CLOSED => [CircuitBreaker::STATE_OPEN],
        CircuitBreaker::STATE_OPEN => [CircuitBreaker::STATE_HALF_OPEN],
        CircuitBreaker::STATE_HALF_OPEN => [CircuitBreaker::STATE_CLOSED, CircuitBreaker::STATE_OPEN],
    ];

    /**
     * 校验状态转换合法性。
     *
     * @param string $from closed|open|half_open
     * @param string $to   closed|open|half_open
     *
     * @throws RuntimeException 非法转换
     */
    public function assertTransition(string $from, string $to): void
    {
        if ($from === $to) {
            return; // 幂等：同态转换视为无害
        }

        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new RuntimeException(
                "Illegal breaker state transition: {$from} → {$to}"
            );
        }
    }
}
