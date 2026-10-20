<?php

declare(strict_types=1);

namespace Tests\Integration\Strategy;

use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\OrderExecutor;
use App\Models\MeterEvent;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * 合约下单执行器测试（spec 005 T037）。
 *
 * 验证 reduceOnly/leverage 透传 adapter + 落库 strategy_orders。
 * QuotaGuard 用 mock（assertEntitled/freezeDaily 放行，不计真实配额）。
 */
class FuturesOrderExecutorTest extends TestCase
{
    private const TENANT_ID = 990070;

    private const INSTANCE_ID = 990270;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scaffold();
    }

    public function test_reduce_only_and_leverage_passed_to_adapter(): void
    {
        $adapter = Mockery::mock(ExchangeAdapter::class);
        $capturedOpts = null;

        $adapter->shouldReceive('symbolFilters')->andReturn([
            'step_size' => '0.001',
            'tick_size' => '0.01',
            'min_notional' => '5',
        ]);
        $adapter->shouldReceive('alignToStep')->andReturnUsing(fn ($v) => $v);
        $adapter->shouldReceive('placeOrder')->once()->andReturnUsing(
            function ($symbol, $side, $type, $qty, $price, $stopPrice, $opts) use (&$capturedOpts) {
                $capturedOpts = $opts;

                return $this->filledOrder($symbol, $side, $qty, $opts['clientOrderId'] ?? 'cid');
            },
        );

        $executor = new OrderExecutor($this->mockQuotaGuard());
        $instance = $this->instanceRow();

        $executor->place(self::TENANT_ID, $instance, $adapter, [
            'side' => 'sell',
            'qty' => '0.01',
            'reduce_only' => true,
            'leverage' => 10,
        ]);

        static::assertTrue($capturedOpts['reduceOnly'] ?? false);
        static::assertSame(10, $capturedOpts['leverage'] ?? null);
    }

    public function test_reduce_only_and_leverage_persisted_to_db(): void
    {
        $adapter = Mockery::mock(ExchangeAdapter::class);
        $adapter->shouldReceive('symbolFilters')->andReturn([
            'step_size' => '0.001',
            'tick_size' => '0.01',
            'min_notional' => '5',
        ]);
        $adapter->shouldReceive('alignToStep')->andReturnUsing(fn ($v) => $v);
        $adapter->shouldReceive('placeOrder')->andReturnUsing(
            fn ($symbol, $side, $type, $qty, $price, $stop, $opts) => $this->filledOrder($symbol, $side, $qty, $opts['clientOrderId'] ?? 'cid'),
        );

        $executor = new OrderExecutor($this->mockQuotaGuard());
        $instance = $this->instanceRow();

        $executor->place(self::TENANT_ID, $instance, $adapter, [
            'side' => 'sell',
            'qty' => '0.01',
            'reduce_only' => true,
            'leverage' => 10,
        ]);

        $row = DB::table('strategy_orders')
            ->where('strategy_instance_id', self::INSTANCE_ID)
            ->first();

        static::assertNotNull($row);
        static::assertSame(1, (int) $row->reduce_only, 'reduce_only 落库为 1');
        static::assertSame(10, (int) $row->leverage, 'leverage 落库为 10');
    }

    public function test_spot_order_has_no_leverage_and_reduce_only_false(): void
    {
        $adapter = Mockery::mock(ExchangeAdapter::class);
        $adapter->shouldReceive('symbolFilters')->andReturn([
            'step_size' => '0.001',
            'tick_size' => '0.01',
            'min_notional' => '5',
        ]);
        $adapter->shouldReceive('alignToStep')->andReturnUsing(fn ($v) => $v);
        $adapter->shouldReceive('placeOrder')->andReturnUsing(
            fn ($symbol, $side, $type, $qty, $price, $stop, $opts) => $this->filledOrder($symbol, $side, $qty, $opts['clientOrderId'] ?? 'cid'),
        );

        $executor = new OrderExecutor($this->mockQuotaGuard());
        $instance = $this->instanceRow();

        $executor->place(self::TENANT_ID, $instance, $adapter, [
            'side' => 'buy',
            'qty' => '0.01',
        ]);

        $row = DB::table('strategy_orders')
            ->where('strategy_instance_id', self::INSTANCE_ID)
            ->first();

        static::assertNotNull($row);
        static::assertSame(0, (int) $row->reduce_only, '现货单 reduce_only 为 false');
        static::assertNull($row->leverage, '现货单 leverage 为 null');
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

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
            exchangeOrderId: 'ex-' . time(),
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
            avgFillPrice: '50000',
            fee: '0.1',
            placedAt: time(),
            filledAt: time(),
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

    private function scaffold(): void
    {
        $now = now();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-foe-990070',
            'slug' => 'poc-foe-990070',
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
            'code' => 'futures_grid', 'name' => '合约网格', 'category' => 'futures',
            'risk_profile' => 'balanced', 'default_params' => json_encode([]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('exchange_accounts')->insert([
            'id' => 990170,
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => $exchangeId,
            'label' => 'poc-foe',
            'market' => 'futures',
            'encrypted_api_key' => 'poc',
            'encrypted_api_secret' => 'poc',
            'permissions' => json_encode(['futures']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('strategy_instances')->insert([
            'id' => self::INSTANCE_ID,
            'tenant_id' => self::TENANT_ID,
            'strategy_id' => $strategyId,
            'exchange_account_id' => 990170,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => json_encode([]),
            'allocated_capital' => '1000',
            'status' => 'running',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
