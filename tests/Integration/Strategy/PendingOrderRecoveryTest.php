<?php

declare(strict_types=1);

namespace Tests\Integration\Strategy;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyState;
use App\Domain\Strategy\Recovery\PendingOrderRecovery;
use App\Domain\Strategy\Spot\SpotGridStrategy;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeExchangeAdapter;
use Tests\TestCase;

/**
 * 崩溃恢复 pending 单核实（P5.5 步骤 1 验收 #9-#11）。
 *
 * 直接驱动 PendingOrderRecovery（无 Swoole、无网络）：
 *  filled → 入账；canceled/查无 → 释放格位；查单异常 → 保守保持阻塞。
 */
class PendingOrderRecoveryTest extends TestCase
{
    private const PARAMS = ['lower_price' => '58000', 'upper_price' => '63000', 'grid_count' => 10, 'investment_amount' => '1000'];

    private PendingOrderRecovery $recovery;

    private int $tenantId = 990020;

    private int $instanceId = 990320;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recovery = app(PendingOrderRecovery::class);
        $this->scaffold();
    }

    public function test_filled_pending_order_is_booked_and_cleared(): void
    {
        $cid = '990020_990320_1760000000_aaa';
        $strategy = $this->strategyWithPendingCell($cid, 4);

        $adapter = new FakeExchangeAdapter;
        $adapter->ordersByCid[$cid] = $this->order($cid, 'filled', '0.001', '60000');

        $stats = $this->recovery->recover($strategy, $adapter, 'BTCUSDT', $this->tenantId, $this->instanceId);

        static::assertSame(['filled' => 1, 'released' => 0, 'submitted' => 0, 'unknown' => 0], $stats);
        static::assertSame([], $strategy->pendingClientOrderIds());

        $snap = $strategy->getState()->positionSnapshot;
        static::assertSame('0.001', $snap['grids']['4']['qty']);
        static::assertSame('60000', $snap['grids']['4']['cost']);
        static::assertFalse($snap['grids']['4']['pending']);

        $row = DB::table('strategy_orders')->where('client_order_id', $cid)->first();
        static::assertSame('filled', $row->status);
        // SQLite decimal 列经 DB::table 取回为原生数值，统一转字符串后按数值断言
        static::assertSame(0, bccomp((string) $row->filled_qty, '0.001', 8));
        static::assertSame(0, bccomp((string) $row->avg_fill_price, '60000', 8));
    }

    public function test_canceled_pending_order_releases_cell_for_retrigger(): void
    {
        $cid = '990020_990320_1760000001_bbb';
        $strategy = $this->strategyWithPendingCell($cid, 4);

        $adapter = new FakeExchangeAdapter;
        $adapter->ordersByCid[$cid] = $this->order($cid, 'canceled', '0', null);

        $stats = $this->recovery->recover($strategy, $adapter, 'BTCUSDT', $this->tenantId, $this->instanceId);

        static::assertSame(['filled' => 0, 'released' => 1, 'submitted' => 0, 'unknown' => 0], $stats);
        static::assertSame([], $strategy->pendingClientOrderIds());
        $snap = $strategy->getState()->positionSnapshot;
        static::assertArrayNotHasKey('4', $snap['grids']);

        static::assertSame('canceled', DB::table('strategy_orders')->where('client_order_id', $cid)->value('status'));

        // 下一根 bar 下穿进入该格 → 可重新产生买单
        $this->assertNextBarProducesBuy($strategy);
    }

    public function test_order_not_found_releases_cell_and_marks_row_failed(): void
    {
        $cid = '990020_990320_1760000002_ccc';
        $strategy = $this->strategyWithPendingCell($cid, 4);

        $adapter = new FakeExchangeAdapter;
        // ordersByCid 不注入 → getOrderByClientOrderId 返回 null（查无此单）

        $stats = $this->recovery->recover($strategy, $adapter, 'BTCUSDT', $this->tenantId, $this->instanceId);

        static::assertSame(['filled' => 0, 'released' => 1, 'submitted' => 0, 'unknown' => 0], $stats);
        $snap = $strategy->getState()->positionSnapshot;
        static::assertArrayNotHasKey('4', $snap['grids']);

        $row = DB::table('strategy_orders')->where('client_order_id', $cid)->first();
        static::assertSame('failed', $row->status);
        static::assertStringContainsString('not found', (string) $row->error_message);

        $this->assertNextBarProducesBuy($strategy);
    }

    public function test_network_error_kept_pending_blocks_retrigger(): void
    {
        $cid = '990020_990320_1760000003_ddd';
        $strategy = $this->strategyWithPendingCell($cid, 4);

        $adapter = new FakeExchangeAdapter;
        $adapter->throwOnGetOrder = true;

        $stats = $this->recovery->recover($strategy, $adapter, 'BTCUSDT', $this->tenantId, $this->instanceId);

        static::assertSame(['filled' => 0, 'released' => 0, 'submitted' => 0, 'unknown' => 1], $stats);
        static::assertSame([$cid], $strategy->pendingClientOrderIds());

        // 后续 bar 不得重复开单：格位仍 pending → 下行无 buy
        $bar = $this->bar('60100', 1_760_496_540_000);
        $strategy->onBar($bar);
        static::assertNull($strategy->shouldOpen(new StrategyContext($this->instanceId, self::PARAMS, null, null, [], null)));
    }

    public function test_submitted_order_keeps_pending_without_change(): void
    {
        $cid = '990020_990320_1760000004_eee';
        $strategy = $this->strategyWithPendingCell($cid, 4);

        $adapter = new FakeExchangeAdapter;
        $adapter->ordersByCid[$cid] = $this->order($cid, 'submitted', '0', null);

        $stats = $this->recovery->recover($strategy, $adapter, 'BTCUSDT', $this->tenantId, $this->instanceId);

        static::assertSame(['filled' => 0, 'released' => 0, 'submitted' => 1, 'unknown' => 0], $stats);
        static::assertSame([$cid], $strategy->pendingClientOrderIds());
        static::assertSame('pending', DB::table('strategy_orders')->where('client_order_id', $cid)->value('status'));
    }

    // ------------------------------------------------------------------

    private function strategyWithPendingCell(string $cid, int $cell): SpotGridStrategy
    {
        $strategy = new SpotGridStrategy(
            $this->instanceId,
            self::PARAMS,
            new StrategyContext($this->instanceId, self::PARAMS, null, null, [], null),
        );
        $strategy->restoreState(StrategyState::fromArray([
            'schema_version' => 1,
            'params' => self::PARAMS,
            'position_snapshot' => [
                'grid_step' => '500',
                // last_index=cell+1：使一根落在 cell 的 bar 构成「下穿进入」
                'last_index' => $cell + 1,
                'grids' => [
                    (string) $cell => ['qty' => '0', 'cost' => '0', 'pending' => true, 'cid' => $cid],
                ],
            ],
            'realized_pnl' => '0',
            'consecutive_losses' => 0,
            'last_bar_time' => null,
        ]));

        $now = now();
        DB::table('strategy_orders')->insert([
            'tenant_id' => $this->tenantId,
            'strategy_instance_id' => $this->instanceId,
            'exchange_order_id' => null,
            'client_order_id' => $cid,
            'symbol' => 'BTCUSDT', 'side' => 'buy', 'type' => 'market', 'quantity' => '0.001',
            'status' => 'pending', 'filled_qty' => '0', 'fee' => '0',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $strategy;
    }

    private function assertNextBarProducesBuy(SpotGridStrategy $strategy): void
    {
        // 60100 → index floor((60100-58000)/500)=4；由 5 下穿进入 4，格位空闲 → buy
        $strategy->onBar($this->bar('60100', 1_760_496_600_000));
        $signal = $strategy->shouldOpen(new StrategyContext($this->instanceId, self::PARAMS, null, null, [], null));
        static::assertNotNull($signal);
        static::assertSame('buy', $signal['side']);
        // 每格 = (1000/10)/60100 ≈ 0.00166389（8 位截断）
        static::assertSame('0.00166389', $signal['qty']);
    }

    private function bar(string $close, int $closeTimeMs): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '1m',
            openTime: $closeTimeMs - 60_000,
            closeTime: $closeTimeMs,
            open: $close,
            high: $close,
            low: $close,
            close: $close,
            volume: '1',
            quoteVolume: '1',
            tradesCount: 1,
        );
    }

    private function order(string $cid, string $status, string $filledQty, ?string $avgPrice): ExchangeOrder
    {
        return new ExchangeOrder(
            exchangeOrderId: 'ex-'.$cid,
            clientOrderId: $cid,
            symbol: 'BTCUSDT',
            side: 'buy',
            type: 'market',
            quantity: '0.001',
            price: null,
            stopPrice: null,
            leverage: null,
            reduceOnly: false,
            status: $status,
            filledQty: $filledQty,
            avgFillPrice: $avgPrice,
            fee: '0',
            placedAt: 1_760_000_000,
            filledAt: $status === 'filled' ? 1_760_000_001 : null,
        );
    }

    private function scaffold(): void
    {
        $now = now();
        DB::table('tenants')->insert([
            'id' => $this->tenantId, 'name' => 'POC recovery', 'slug' => 'poc-recovery-990020',
            'exchange_dek' => 'dummy', 'status' => 'active', 'plan_code' => 'pro',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance-rec', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid', 'name' => '现货网格', 'category' => 'spot',
            'risk_profile' => 'conservative', 'default_params' => json_encode([]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $accountId = (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => $this->tenantId, 'exchange_id' => $exchangeId, 'label' => 'poc',
            'market' => 'spot', 'encrypted_api_key' => 'k', 'encrypted_api_secret' => 's',
            'dek_version' => 1, 'permissions' => json_encode([]), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('strategy_instances')->insert([
            'id' => $this->instanceId, 'tenant_id' => $this->tenantId, 'strategy_id' => $strategyId,
            'exchange_account_id' => $accountId, 'symbol' => 'BTCUSDT', 'interval' => '1m',
            'params' => json_encode(self::PARAMS), 'allocated_capital' => '1000',
            'status' => 'running', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
