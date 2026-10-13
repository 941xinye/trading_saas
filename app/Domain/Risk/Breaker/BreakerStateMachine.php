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
        throw new RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }
}
