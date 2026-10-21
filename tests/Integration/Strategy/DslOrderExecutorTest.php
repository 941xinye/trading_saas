<?php

declare(strict_types=1);

namespace Tests\Integration\Strategy;

use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Observability\MetricsStore;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Custom\DslStrategy;
use App\Domain\Strategy\OrderExecutor;
use App\Models\MeterEvent;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * DSL 端到端闭环测试（tasks T013 / FR-006/009 / SC-001）。
 *
 * onBar → onFill（成交入账）→ shouldClose → OrderExecutor::place（幂等落库 + cid 可查回）→ onFill
 * 全链路走真实 OrderExecutor + Mockery 假适配器；同一 clientOrderId 重放不产生第二行。
 */
class DslOrderExecutorTest extends TestCase
{
    private const TENANT_ID = 990080;

    private const INSTANCE_ID = 990280;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scaffold();
    }

    public function test_open_then_close_round_trip_persists_orders_once(): void
    {
        $adapter = $this->recordingAdapter();
        $executor = new OrderExecutor($this->mockQuotaGuard(), app(MetricsStore::class));
        $strategy = $this->strategy();

        // 1. onBar → shouldOpen（qty_pct 0.5 @110 = 4.54545454）
        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx());
        static::assertNotNull($open);
        static::assertSame('buy', $open['side']);

        // 2. 绑定 cid → 下单 → onFill 入账
        $openCid = OrderExecutor::generateClientOrderId(self::TENANT_ID, self::INSTANCE_ID);
        $strategy->attachClientOrderId($openCid, (string) $open['side'], (string) $open['qty']);
        $order = $executor->place(self::TENANT_ID, $this->instanceRow(), $adapter, $open + ['client_order_id' => $openCid]);
        static::assertNotNull($order);
        static::assertSame($openCid, $order->clientOrderId);
        $strategy->onFill($order);

        // 订单落库且 clientOrderId 可查回
        $row = DB::table('strategy_orders')->where('client_order_id', $openCid)->first();
        static::assertNotNull($row, '订单已按 clientOrderId 落库');
        static::assertSame(self::INSTANCE_ID, (int) $row->strategy_instance_id);
        static::assertSame('filled', (string) $row->status);

        // 3. 持仓入账：4.54545454
        static::assertSame(
            (string) $open['qty'],
            $strategy->getState()->positionSnapshot['position']['qty'],
            '成交后持仓数量等于信号数量',
        );

        // 4. 跌破阈值 → shouldClose → 平仓下单 + onFill
        $strategy->onBar($this->bar(1_760_000_100_000, '90'));
        $close = $strategy->shouldClose($this->ctx());
        static::assertNotNull($close);
        static::assertSame('sell', $close['side']);

        $closeCid = OrderExecutor::generateClientOrderId(self::TENANT_ID, self::INSTANCE_ID);
        $strategy->attachClientOrderId($closeCid, (string) $close['side'], (string) $close['qty']);
        $closeOrder = $executor->place(self::TENANT_ID, $this->instanceRow(), $adapter, $close + ['client_order_id' => $closeCid]);
        static::assertNotNull($closeOrder);
        $strategy->onFill($closeOrder);

        static::assertSame('0', $strategy->getState()->positionSnapshot['position']['qty'], '平仓后持仓归零');
        static::assertSame(2, (int) DB::table('strategy_orders')->where('strategy_instance_id', self::INSTANCE_ID)->count());
    }

    public function test_replaying_same_client_order_id_does_not_create_second_row(): void
    {
        $adapter = $this->recordingAdapter();
        $executor = new OrderExecutor($this->mockQuotaGuard(), app(MetricsStore::class));
        $strategy = $this->strategy();

        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx());
        static::assertNotNull($open);

        $cid = OrderExecutor::generateClientOrderId(self::TENANT_ID, self::INSTANCE_ID);
        $order = $executor->place(self::TENANT_ID, $this->instanceRow(), $adapter, $open + ['client_order_id' => $cid]);
        static::assertNotNull($order);
        $strategy->onFill($order);

        static::assertSame(1, (int) DB::table('strategy_orders')->where('client_order_id', $cid)->count());

        // 崩溃重放：同 cid 再次 place → 已有 filled 行 → 幂等跳过（返回 null，不新增行）
        $replay = $executor->place(self::TENANT_ID, $this->instanceRow(), $adapter, $open + ['client_order_id' => $cid]);

        static::assertNull($replay, '已在交易所生效的同 cid 订单幂等跳过');
        static::assertSame(1, (int) DB::table('strategy_orders')->where('client_order_id', $cid)->count(), '不产生重复行');
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function recordingAdapter(): ExchangeAdapter
    {
        $adapter = Mockery::mock(ExchangeAdapter::class);
        $adapter->shouldReceive('symbolFilters')->andReturn([
            'step_size' => '0.001',
            'tick_size' => '0.01',
            'min_notional' => '5',
        ]);
        $adapter->shouldReceive('alignToStep')->andReturnUsing(fn ($v) => (string) $v);
        $adapter->shouldReceive('getTicker')->andReturnUsing(
            fn (string $symbol) => new ExchangeTicker($symbol, '110', '0', '0', '0', '0', 1_760_000_000_000),
        );
        $adapter->shouldReceive('placeOrder')->andReturnUsing(
            fn ($symbol, $side, $type, $qty, $price, $stopPrice, $opts) => $this->filledOrder(
                $symbol,
                $side,
                (string) $qty,
                (string) ($opts['clientOrderId'] ?? 'cid'),
            ),
        );

        return $adapter;
    }

    private function mockQuotaGuard(): QuotaGuard
    {
        $guard = Mockery::mock(QuotaGuard::class);
        $guard->shouldReceive('assertEntitled')->andReturnNull();
        $guard->shouldReceive('freezeDaily')->andReturn(new MeterEvent);
        $guard->shouldReceive('settle')->andReturnNull();
        $guard->shouldReceive('release')->andReturnNull();

        return $guard;
    }

    private function filledOrder(string $symbol, string $side, string $qty, string $clientOrderId): ExchangeOrder
    {
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
            avgFillPrice: '110',
            fee: '0.1',
            placedAt: time(),
            filledAt: time(),
        );
    }

    private function strategy(): DslStrategy
    {
        $params = [
            'dsl_script' => $this->script(),
            'symbol' => 'BTCUSDT',
            'tenant_id' => self::TENANT_ID,
            'allocated_capital' => '1000',
        ];

        return new DslStrategy(self::INSTANCE_ID, $params, new StrategyContext(self::INSTANCE_ID, $params, null, null, [], null));
    }

    private function ctx(): StrategyContext
    {
        return new StrategyContext(self::INSTANCE_ID, [], null, null, [], null);
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

    private function instanceRow(): object
    {
        return (object) [
            'id' => self::INSTANCE_ID,
            'tenant_id' => self::TENANT_ID,
            'symbol' => 'BTCUSDT',
        ];
    }

    private function script(): string
    {
        return implode("\n", [
            'version: 1',
            'name: e2e',
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

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-dsl-990080',
            'slug' => 'poc-dsl-990080',
            'exchange_dek' => 'dummy-dek',
            'status' => 'active',
            'plan_code' => 'pro',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot', 'futures']),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'custom_dsl', 'name' => '自定义 DSL', 'category' => 'spot',
            'risk_profile' => 'balanced', 'default_params' => json_encode([]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('exchange_accounts')->insert([
            'id' => 990180,
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => $exchangeId,
            'label' => 'poc-dsl',
            'market' => 'spot',
            'encrypted_api_key' => 'poc',
            'encrypted_api_secret' => 'poc',
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('strategy_instances')->insert([
            'id' => self::INSTANCE_ID,
            'tenant_id' => self::TENANT_ID,
            'strategy_id' => $strategyId,
            'exchange_account_id' => 990180,
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
