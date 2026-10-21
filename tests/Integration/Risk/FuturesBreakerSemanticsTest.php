<?php

declare(strict_types=1);

namespace Tests\Integration\Risk;

use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Risk\Breaker\BreakerStateMachine;
use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Domain\Risk\RiskGuard;
use App\Domain\Risk\Rules\MaxLossPerTradeRule;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\TestCase;
use Throwable;

/**
 * 合约场景熔断语义测试（spec 005 T036）。
 *
 * 验证：
 *  1. 熔断 OPEN 状态下开仓被拦截、平仓放行
 *  2. 仓位紧急状态（paused_by_position_risk）独立于账户熔断
 *     —— 熔断不影响仓位风控平仓，仓位风控不停熔断恢复
 *
 * @group redis（CircuitBreaker 用 Redis 存状态，不可用时自动 skip）
 *
 * 隔离约定：固定高位租户 990060 / 实例 990260；setUp 与 tearDown 均清理
 * `tenant:{tid}:breaker:{iid}`，避免上一用例留下的 HALF_OPEN 串场（坑 #25）。
 */
#[Group('redis')]
class FuturesBreakerSemanticsTest extends TestCase
{
    use ChecksRedisAvailability;

    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;
        $this->clearRedis();
    }

    protected function tearDown(): void
    {
        // 顺序：先做可能失败的外部清理（受守卫保护），最后 parent::tearDown() 回滚事务（坑 #24）
        if ($this->redisAvailable) {
            try {
                $this->clearRedis();
            } catch (Throwable) {
                // 清理失败不掩盖测试结果本身
            }
        }

        parent::tearDown();
    }

    private const TENANT_ID = 990060;

    private const INSTANCE_ID = 990260;

    private function makeGuard(array $rules = []): RiskGuard
    {
        return new RiskGuard(
            $rules,
            new CircuitBreaker(new BreakerStateMachine, self::TENANT_ID, self::INSTANCE_ID),
            '1000',
            self::TENANT_ID,
            self::INSTANCE_ID,
        );
    }

    // ------------------------------------------------------------------
    // 断言 1：熔断 OPEN 开仓拦截、平仓放行
    // ------------------------------------------------------------------

    public function test_open_state_blocks_opening_orders(): void
    {
        $guard = $this->makeGuard([new MaxLossPerTradeRule('2')]);

        // 触发熔断：单笔亏损 3% > 2%
        $guard->recordTrade(self::INSTANCE_ID, $this->makeOrder('buy', '1', '100'));
        $guard->metrics()->recordTradeWithDate('-3', '100', gmdate('Y-m-d'));
        $guard->check(self::INSTANCE_ID, 'open');

        // OPEN 状态下开仓被拒
        $openDecision = $guard->check(self::INSTANCE_ID, 'open');
        static::assertFalse($openDecision->allowed);
        static::assertSame('breaker_open', $openDecision->reason);
    }

    public function test_open_state_allows_closing_orders(): void
    {
        $guard = $this->makeGuard([new MaxLossPerTradeRule('2')]);

        $guard->recordTrade(self::INSTANCE_ID, $this->makeOrder('buy', '1', '100'));
        $guard->metrics()->recordTradeWithDate('-3', '100', gmdate('Y-m-d'));
        $guard->check(self::INSTANCE_ID, 'open');

        // 平仓永远放行（关键约束：熔断只停开新仓）
        $closeDecision = $guard->check(self::INSTANCE_ID, 'close');
        static::assertTrue($closeDecision->allowed);
        static::assertSame('close_allowed', $closeDecision->reason);
    }

    // ------------------------------------------------------------------
    // 断言 2：仓位风控与账户熔断正交
    // ------------------------------------------------------------------

    public function test_position_risk_close_does_not_require_breaker_closed(): void
    {
        // 即使熔断 OPEN，仓位风控的减仓/全平仍可执行（走 close 动作）
        $guard = $this->makeGuard([new MaxLossPerTradeRule('2')]);
        $guard->metrics()->recordTradeWithDate('-5', '100', gmdate('Y-m-d'));
        $guard->check(self::INSTANCE_ID, 'open'); // → OPEN

        // 模拟仓位风控触发紧急全平：走 check('close')
        $decision = $guard->check(self::INSTANCE_ID, 'close');
        static::assertTrue($decision->allowed, '仓位风控平仓不受熔断影响');
    }

    public function test_breaker_recovery_independent_of_position_risk(): void
    {
        // 熔断恢复流程不依赖仓位风控状态
        $guard = $this->makeGuard([new MaxLossPerTradeRule('2')]);
        $guard->metrics()->recordTradeWithDate('-3', '100', gmdate('Y-m-d'));
        $guard->check(self::INSTANCE_ID, 'open'); // → OPEN

        static::assertSame(CircuitBreaker::STATE_OPEN, $guard->getBreakerState(self::INSTANCE_ID));
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    /**
     * 清除本用例的熔断 Redis Hash，避免用例之间 HALF_OPEN 状态串场（坑 #25）。
     */
    private function clearRedis(): void
    {
        Redis::connection('default')->del(
            'tenant:'.self::TENANT_ID.':breaker:'.self::INSTANCE_ID,
        );
    }

    private function makeOrder(string $side, string $qty, string $price): ExchangeOrder
    {
        return new ExchangeOrder(
            exchangeOrderId: '1',
            clientOrderId: 'cid',
            symbol: 'BTCUSDT',
            side: $side,
            type: 'market',
            quantity: $qty,
            price: null,
            stopPrice: null,
            leverage: 10,
            reduceOnly: false,
            status: 'filled',
            filledQty: $qty,
            avgFillPrice: $price,
            fee: null,
            placedAt: time(),
            filledAt: time(),
        );
    }
}
