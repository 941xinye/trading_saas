<?php

declare(strict_types=1);

namespace Tests\Integration\Strategy;

use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Risk\Breaker\BreakerStateMachine;
use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Domain\Risk\RiskGuard;
use App\Domain\Risk\Rules\MaxLossPerTradeRule;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Custom\DslStrategy;
use App\Domain\Strategy\OrderExecutor;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Support\RedisCleanup;
use Tests\TestCase;

/**
 * DSL 计费/熔断接线（tasks T034 / FR-013/014 / SC-007 / constitution V）。
 *
 * 验证 DSL 下单同受既有闸门约束（复用 OrderExecutor + QuotaGuard + RiskGuard，不新增接线）：
 *  1. 日配额耗尽 → DSL 开仓交易所零调用、计数不变、不落单
 *  2. 熔断 OPEN → DSL 开仓 100% 拦截；DSL 平仓 100% 放行（平仓不受熔断拦截）
 *
 * @group redis：QuotaGuard 日配额（Lua）与熔断状态机均依赖 Redis，不可用时自动 skip。
 */
#[Group('redis')]
class DslBillingBreakerTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private const TENANT_QUOTA = 990120;

    private const INSTANCE_QUOTA = 990220;

    private const TENANT_BREAKER = 990121;

    private const INSTANCE_BREAKER = 990221;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireRedis();
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        // 清理先于 parent::tearDown() 回滚事务（docs/pitfalls #24）
        $this->cleanupRedis($this->redisKeys());

        parent::tearDown();
    }

    /**
     * 本用例写入的 Redis 键（逻辑名，trait 自动拼前缀）。
     *
     * @return string[]
     */
    private function redisKeys(): array
    {
        $day = gmdate('Ymd');

        return [
            sprintf('tenant:%d:meter:order_placed:%s', self::TENANT_QUOTA, $day),
            sprintf('tenant:%d:meter:order_placed:%s', self::TENANT_BREAKER, $day),
            sprintf('tenant:%d:breaker:%d', self::TENANT_BREAKER, self::INSTANCE_BREAKER),
        ];
    }

    // ------------------------------------------------------------------
    // 断言 1：配额耗尽 → 交易所零调用
    // ------------------------------------------------------------------

    public function test_exhausted_daily_quota_blocks_dsl_open_with_zero_exchange_calls(): void
    {
        // 把 pro 套餐日下单配额降到 1，并预先占满
        config()->set('plans.plans.pro.features.orders_per_day', 1);
        $meter = app(TwoPhaseMeter::class);
        static::assertNotNull(
            app(QuotaGuard::class)->freezeDaily(self::TENANT_QUOTA, 'order_placed', 'prefreeze-quota'),
        );
        static::assertSame(1, $meter->usedToday(self::TENANT_QUOTA, 'order_placed'));

        $queryCalls = 0;
        $orderCalls = 0;
        $adapter = $this->recordingAdapter($queryCalls, $orderCalls);

        // DSL 产出开仓信号（close 110 > 100）
        $strategy = $this->strategy(self::TENANT_QUOTA, self::INSTANCE_QUOTA);
        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx(self::INSTANCE_QUOTA));
        static::assertNotNull($open, '前置：DSL 已产出开仓信号');

        $cid = OrderExecutor::generateClientOrderId(self::TENANT_QUOTA, self::INSTANCE_QUOTA);
        $strategy->attachClientOrderId($cid, (string) $open['side'], (string) $open['qty']);

        $order = app(OrderExecutor::class)->place(
            self::TENANT_QUOTA,
            $this->instanceRow(self::TENANT_QUOTA, self::INSTANCE_QUOTA),
            $adapter,
            $open + ['client_order_id' => $cid],
        );

        static::assertNotNull($order);
        static::assertSame('failed', $order->status, '配额拦截 → 失败单，不触达交易所');
        static::assertSame(0, $queryCalls, '配额耗尽时不下发任何交易所查询');
        static::assertSame(0, $orderCalls, '配额耗尽时交易所下单零调用');
        static::assertSame(
            0,
            (int) DB::table('strategy_orders')->where('client_order_id', $cid)->count(),
            '配额拦截不落单',
        );
        static::assertSame(1, $meter->usedToday(self::TENANT_QUOTA, 'order_placed'), '配额计数不变');

        // Runner 语义：失败单回调 → 释放 pending 占位，不卡死在途
        $strategy->onFill($order);
        static::assertSame('0', $strategy->getState()->positionSnapshot['position']['qty']);
    }

    // ------------------------------------------------------------------
    // 断言 2：熔断 OPEN 下平仓放行、开仓拦截
    // ------------------------------------------------------------------

    public function test_breaker_open_blocks_dsl_open_but_allows_dsl_close(): void
    {
        $queryCalls = 0;
        $orderCalls = 0;
        $executor = app(OrderExecutor::class);
        $adapter = $this->recordingAdapter($queryCalls, $orderCalls);
        $risk = $this->riskGuard();

        // 触发熔断（单笔亏损 3% > 2%）
        $risk->recordTrade(self::INSTANCE_BREAKER, $this->filledOrder('buy', '1', '100'));
        $risk->metrics()->recordTradeWithDate('-3', '100', gmdate('Y-m-d'));
        static::assertFalse($risk->check(self::INSTANCE_BREAKER, 'open')->allowed, '触发后熔断 OPEN');
        static::assertFalse($risk->check(self::INSTANCE_BREAKER, 'open')->allowed, 'OPEN 持续拦截开仓');
        static::assertTrue($risk->check(self::INSTANCE_BREAKER, 'close')->allowed, '平仓永远放行');

        $strategy = $this->strategy(self::TENANT_BREAKER, self::INSTANCE_BREAKER);

        // 1. 开仓信号 + OPEN → 拦截，零交易所调用
        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx(self::INSTANCE_BREAKER));
        static::assertNotNull($open, '前置：DSL 已产出开仓信号');

        $openCid = OrderExecutor::generateClientOrderId(self::TENANT_BREAKER, self::INSTANCE_BREAKER);
        $strategy->attachClientOrderId($openCid, (string) $open['side'], (string) $open['qty']);
        static::assertFalse($risk->check(self::INSTANCE_BREAKER, 'open')->allowed);
        static::assertSame(0, $orderCalls, '熔断拦截时交易所下单零调用');

        // 模拟 Runner：拦截 → 失败单回调释放占位
        $strategy->onFill($this->failedOrder($openCid, (string) $open['side'], (string) $open['qty']));

        // 2. 建仓（模拟熔断前已成交的仓位回报）
        $strategy->onBar($this->bar(1_760_000_100_000, '110'));
        $openAgain = $strategy->shouldOpen($this->ctx(self::INSTANCE_BREAKER));
        static::assertNotNull($openAgain);
        $filledCid = OrderExecutor::generateClientOrderId(self::TENANT_BREAKER, self::INSTANCE_BREAKER);
        $strategy->attachClientOrderId($filledCid, 'buy', (string) $openAgain['qty']);
        $strategy->onFill($this->filledOrder('buy', (string) $openAgain['qty'], '110', $filledCid));
        static::assertSame(
            (string) $openAgain['qty'],
            $strategy->getState()->positionSnapshot['position']['qty'],
            '前置：持仓入账',
        );

        // 3. 平仓信号（close 90 < 100）+ OPEN → 放行并真实下单
        $strategy->onBar($this->bar(1_760_000_160_000, '90'));
        $close = $strategy->shouldClose($this->ctx(self::INSTANCE_BREAKER));
        static::assertNotNull($close, 'DSL 平仓信号在熔断 OPEN 下仍产出');

        $closeCid = OrderExecutor::generateClientOrderId(self::TENANT_BREAKER, self::INSTANCE_BREAKER);
        $strategy->attachClientOrderId($closeCid, (string) $close['side'], (string) $close['qty']);
        static::assertTrue($risk->check(self::INSTANCE_BREAKER, 'close')->allowed, '平仓不被熔断拦截');

        $closeOrder = $executor->place(
            self::TENANT_BREAKER,
            $this->instanceRow(self::TENANT_BREAKER, self::INSTANCE_BREAKER),
            $adapter,
            $close + ['client_order_id' => $closeCid],
        );

        static::assertNotNull($closeOrder);
        static::assertSame('filled', $closeOrder->status, '平仓单真实下单');
        static::assertSame(1, $orderCalls, '熔断 OPEN 下平仓交易所调用一次');
        static::assertSame(1, (int) DB::table('strategy_orders')->where('client_order_id', $closeCid)->count());

        $strategy->onFill($closeOrder);
        static::assertSame('0', $strategy->getState()->positionSnapshot['position']['qty'], '平仓后持仓归零');
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function riskGuard(): RiskGuard
    {
        return new RiskGuard(
            [new MaxLossPerTradeRule('2')],
            new CircuitBreaker(new BreakerStateMachine, self::TENANT_BREAKER, self::INSTANCE_BREAKER),
            '1000',
            self::TENANT_BREAKER,
            self::INSTANCE_BREAKER,
        );
    }

    /**
     * 记录型适配器：分别统计「下单前置查询」与「真实下单」调用次数。
     */
    private function recordingAdapter(int &$queryCalls, int &$orderCalls): ExchangeAdapter
    {
        $adapter = Mockery::mock(ExchangeAdapter::class);
        $adapter->shouldReceive('symbolFilters')->andReturnUsing(function () use (&$queryCalls): array {
            $queryCalls++;

            return ['step_size' => '0.001', 'tick_size' => '0.01', 'min_notional' => '5'];
        });
        $adapter->shouldReceive('alignToStep')->andReturnUsing(fn ($v) => (string) $v);
        $adapter->shouldReceive('getTicker')->andReturnUsing(function (string $symbol) use (&$queryCalls): ExchangeTicker {
            $queryCalls++;

            return new ExchangeTicker($symbol, '110', '0', '0', '0', '0', 1_760_000_000_000);
        });
        $adapter->shouldReceive('placeOrder')->andReturnUsing(
            function ($symbol, $side, $type, $qty, $price, $stopPrice, $opts) use (&$orderCalls): ExchangeOrder {
                $orderCalls++;

                return $this->filledOrder(
                    (string) $side,
                    (string) $qty,
                    '110',
                    (string) ($opts['clientOrderId'] ?? 'cid'),
                    (string) $symbol,
                );
            },
        );

        return $adapter;
    }

    private function filledOrder(
        string $side,
        string $qty,
        string $price,
        string $clientOrderId = 'cid',
        string $symbol = 'BTCUSDT',
    ): ExchangeOrder {
        return new ExchangeOrder(
            exchangeOrderId: 'ex-'.$clientOrderId,
            clientOrderId: $clientOrderId,
            symbol: $symbol,
            side: $side,
            type: 'market',
            quantity: $qty,
            price: null,
            stopPrice: null,
            leverage: null,
            reduceOnly: false,
            status: 'filled',
            filledQty: $qty,
            avgFillPrice: $price,
            fee: '0',
            placedAt: time(),
            filledAt: time(),
        );
    }

    private function failedOrder(string $clientOrderId, string $side, string $qty): ExchangeOrder
    {
        return new ExchangeOrder(
            exchangeOrderId: '0',
            clientOrderId: $clientOrderId,
            symbol: 'BTCUSDT',
            side: $side,
            type: 'market',
            quantity: $qty,
            price: null,
            stopPrice: null,
            leverage: null,
            reduceOnly: false,
            status: 'failed',
            filledQty: '0',
            avgFillPrice: null,
            fee: null,
            placedAt: time(),
            filledAt: null,
        );
    }

    private function strategy(int $tenantId, int $instanceId): DslStrategy
    {
        $params = [
            'dsl_script' => $this->script(),
            'symbol' => 'BTCUSDT',
            'tenant_id' => $tenantId,
            'allocated_capital' => '1000',
        ];

        return new DslStrategy($instanceId, $params, new StrategyContext($instanceId, $params, null, null, [], null));
    }

    private function ctx(int $instanceId): StrategyContext
    {
        return new StrategyContext($instanceId, [], null, null, [], null);
    }

    private function bar(int $openTimeMs, string $close): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '1m',
            openTime: $openTimeMs,
            closeTime: $openTimeMs + 59_999,
            open: $close,
            high: $close,
            low: $close,
            close: $close,
            volume: '1',
            quoteVolume: '1',
            tradesCount: 1,
        );
    }

    private function instanceRow(int $tenantId, int $instanceId): object
    {
        return (object) [
            'id' => $instanceId,
            'tenant_id' => $tenantId,
            'symbol' => 'BTCUSDT',
        ];
    }

    private function script(): string
    {
        return implode("\n", [
            'version: 1',
            'name: billing-breaker',
            'symbol: BTCUSDT',
            'interval: 1m',
            'direction: long',
            'trigger: on_bar',
            'filter:',
            '  - action: noop',
            '    when:',
            '      op: true',
            'entry:',
            '  - action: open_long',
            '    when:',
            '      op: gt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            '    qty_pct: "0.5"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: lt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            '    qty_pct: "1.0"',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);
    }

    private function scaffold(): void
    {
        $now = now();

        foreach ([[self::TENANT_QUOTA, self::INSTANCE_QUOTA], [self::TENANT_BREAKER, self::INSTANCE_BREAKER]] as [$tenantId, $instanceId]) {
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'name' => "poc-dsl-bb-{$tenantId}",
                'slug' => "poc-dsl-bb-{$tenantId}",
                'exchange_dek' => 'dummy-dek',
                'status' => 'active',
                'plan_code' => 'pro',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $exchangeId = (int) DB::table('exchanges')->insertGetId([
                'code' => "binance-dsl-bb-{$tenantId}", 'name' => 'Binance',
                'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
                'markets' => json_encode(['spot']),
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $strategyId = (int) DB::table('strategies')->insertGetId([
                'code' => "custom_dsl_bb_{$tenantId}", 'name' => '自定义 DSL', 'category' => 'spot',
                'risk_profile' => 'custom', 'default_params' => json_encode(['dsl_script' => null]),
                'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $accountId = (int) DB::table('exchange_accounts')->insertGetId([
                'tenant_id' => $tenantId,
                'exchange_id' => $exchangeId,
                'label' => 'poc-dsl-bb',
                'market' => 'spot',
                'encrypted_api_key' => 'poc',
                'encrypted_api_secret' => 'poc',
                'permissions' => json_encode(['spot']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('strategy_instances')->insert([
                'id' => $instanceId,
                'tenant_id' => $tenantId,
                'strategy_id' => $strategyId,
                'exchange_account_id' => $accountId,
                'symbol' => 'BTCUSDT',
                'interval' => '1m',
                'params' => json_encode(['dsl_script' => $this->script()]),
                'allocated_capital' => '1000',
                'status' => 'running',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
