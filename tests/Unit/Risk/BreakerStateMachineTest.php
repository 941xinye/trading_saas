<?php

declare(strict_types=1);

namespace Tests\Unit\Risk;

use App\Domain\Risk\Breaker\BreakerStateMachine;
use App\Domain\Risk\Breaker\CircuitBreaker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 熔断三态转换合法性纯逻辑测试（poc:verify-risk 断言 1/5/6/12 经由 RiskGuard 走同一状态机）。
 *
 * 合法：closed→open、open→half_open、half_open→closed、half_open→open；同态转换幂等。
 * default 组：仅 new BreakerStateMachine()，无任何外部依赖。
 */
class BreakerStateMachineTest extends TestCase
{
    /**
     * @return iterable<string, array{0:string, 1:string}>
     */
    public static function legalTransitions(): iterable
    {
        yield 'closed → open（触发熔断）' => [CircuitBreaker::STATE_CLOSED, CircuitBreaker::STATE_OPEN];
        yield 'open → half_open（冷却到期）' => [CircuitBreaker::STATE_OPEN, CircuitBreaker::STATE_HALF_OPEN];
        yield 'half_open → closed（探测盈利）' => [CircuitBreaker::STATE_HALF_OPEN, CircuitBreaker::STATE_CLOSED];
        yield 'half_open → open（探测亏损 re-trip）' => [CircuitBreaker::STATE_HALF_OPEN, CircuitBreaker::STATE_OPEN];
    }

    #[DataProvider('legalTransitions')]
    public function test_legal_transitions_pass(string $from, string $to): void
    {
        // 不抛异常即合法
        (new BreakerStateMachine)->assertTransition($from, $to);

        static::assertTrue(true);
    }

    public function test_same_state_transition_is_idempotent(): void
    {
        $machine = new BreakerStateMachine;

        $machine->assertTransition(CircuitBreaker::STATE_CLOSED, CircuitBreaker::STATE_CLOSED);
        $machine->assertTransition(CircuitBreaker::STATE_OPEN, CircuitBreaker::STATE_OPEN);
        $machine->assertTransition(CircuitBreaker::STATE_HALF_OPEN, CircuitBreaker::STATE_HALF_OPEN);

        static::assertTrue(true);
    }

    public function test_closed_cannot_jump_to_half_open(): void
    {
        $this->expectException(RuntimeException::class);

        (new BreakerStateMachine)->assertTransition(
            CircuitBreaker::STATE_CLOSED,
            CircuitBreaker::STATE_HALF_OPEN,
        );
    }

    public function test_open_cannot_jump_back_to_closed(): void
    {
        $this->expectException(RuntimeException::class);

        (new BreakerStateMachine)->assertTransition(
            CircuitBreaker::STATE_OPEN,
            CircuitBreaker::STATE_CLOSED,
        );
    }

    public function test_unknown_source_state_cannot_transition(): void
    {
        $this->expectException(RuntimeException::class);

        (new BreakerStateMachine)->assertTransition('bogus', CircuitBreaker::STATE_OPEN);
    }
}
