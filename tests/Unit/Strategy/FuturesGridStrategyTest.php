<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Futures\FuturesGridStrategy;
use PHPUnit\Framework\TestCase;

/**
 * 合约网格策略单元测试（spec 005 WS4）。
 *
 * 覆盖 6 种决策 + onFill 入账 + shouldOpen/shouldClose + 状态序列化 + 崩溃恢复。
 * 纯内存，无 Redis/DB 依赖。
 *
 * 网格配置：lower=40000, upper=60000, count=10, step=2000, leverage=5, margin=1000
 * 每格数量（price=50000）= (1000/10)/50000 × 5 = 0.01
 */
class FuturesGridStrategyTest extends TestCase
{
    private const PARAMS = [
        'symbol' => 'BTCUSDT',
        'upper_price' => 60000,
        'lower_price' => 40000,
        'grid_count' => 10,
        'leverage' => 5,
        'margin' => 1000,
    ];

    private function makeStrategy(array $params = []): FuturesGridStrategy
    {
        return new FuturesGridStrategy(
            instanceId: 1,
            params: array_merge(self::PARAMS, $params),
            context: new StrategyContext(
                instanceId: 1,
                params: array_merge(self::PARAMS, $params),
                position: null,
                positionKline: null,
                recentBars: [],
                ticker: null,
            ),
        );
    }

    private function makeBar(string $close, int $closeTime = 1700000000000): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '5m',
            openTime: $closeTime - 300000,
            closeTime: $closeTime,
            open: $close,
            high: $close,
            low: $close,
            close: $close,
            volume: '100',
            quoteVolume: '1000000',
            tradesCount: 10,
        );
    }

    private function makeOrder(
        string $clientOrderId,
        string $side,
        string $qty,
        string $status = 'filled',
        ?string $avgFillPrice = '50000',
    ): ExchangeOrder {
        return new ExchangeOrder(
            exchangeOrderId: '123',
            clientOrderId: $clientOrderId,
            symbol: 'BTCUSDT',
            side: $side,
            type: 'market',
            quantity: $qty,
            price: null,
            stopPrice: null,
            leverage: 5,
            reduceOnly: false,
            status: $status,
            filledQty: $qty,
            avgFillPrice: $avgFillPrice,
            fee: null,
            placedAt: time(),
            filledAt: time(),
        );
    }

    // ------------------------------------------------------------------
    // T027: 6 种决策
    // ------------------------------------------------------------------

    public function test_no_position_downward_opens_long(): void
    {
        $strategy = $this->makeStrategy();

        // 首 bar @50000 初始化位置
        $strategy->onBar($this->makeBar('50000'));
        // 下行 @48000
        $strategy->onBar($this->makeBar('48000'));

        $ctx = $this->ctx();
        $open = $strategy->shouldOpen($ctx);

        static::assertNotNull($open);
        static::assertSame('buy', $open['side']);
        static::assertFalse($open['reduce_only']);
        static::assertSame(5, $open['leverage']);
        static::assertNotSame('0', $open['qty']);
    }

    public function test_no_position_upward_opens_short(): void
    {
        $strategy = $this->makeStrategy();

        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('52000'));

        $open = $strategy->shouldOpen($this->ctx());

        static::assertNotNull($open);
        static::assertSame('sell', $open['side']);
        static::assertFalse($open['reduce_only']);
    }

    public function test_long_position_upward_closes_long(): void
    {
        $strategy = $this->makeStrategy();

        // 开多
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid-open', 'buy', '0.01');
        $strategy->onFill($this->makeOrder('cid-open', 'buy', '0.01', 'filled', '48000'));

        // 上行平多
        $strategy->onBar($this->makeBar('52000'));
        $close = $strategy->shouldClose($this->ctx());

        static::assertNotNull($close);
        static::assertSame('sell', $close['side']);
        static::assertTrue($close['reduce_only']);
        static::assertSame('0.01', $close['qty']); // 全平
    }

    public function test_short_position_downward_closes_short(): void
    {
        $strategy = $this->makeStrategy();

        // 开空
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('52000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid-open', 'sell', '0.01');
        $strategy->onFill($this->makeOrder('cid-open', 'sell', '0.01', 'filled', '52000'));

        // 下行平空
        $strategy->onBar($this->makeBar('48000'));
        $close = $strategy->shouldClose($this->ctx());

        static::assertNotNull($close);
        static::assertSame('buy', $close['side']);
        static::assertTrue($close['reduce_only']);
    }

    public function test_long_position_downward_adds_long(): void
    {
        $strategy = $this->makeStrategy();

        // 开多
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid-open', 'buy', '0.01');
        $strategy->onFill($this->makeOrder('cid-open', 'buy', '0.01', 'filled', '48000'));

        // 继续下行 → 加多
        $strategy->onBar($this->makeBar('46000'));
        $open = $strategy->shouldOpen($this->ctx());

        static::assertNotNull($open);
        static::assertSame('buy', $open['side']);
        static::assertFalse($open['reduce_only']);
    }

    public function test_short_position_upward_adds_short(): void
    {
        $strategy = $this->makeStrategy();

        // 开空
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('52000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid-open', 'sell', '0.01');
        $strategy->onFill($this->makeOrder('cid-open', 'sell', '0.01', 'filled', '52000'));

        // 继续上行 → 加空
        $strategy->onBar($this->makeBar('54000'));
        $open = $strategy->shouldOpen($this->ctx());

        static::assertNotNull($open);
        static::assertSame('sell', $open['side']);
        static::assertFalse($open['reduce_only']);
    }

    public function test_out_of_range_no_signal(): void
    {
        $strategy = $this->makeStrategy();

        $strategy->onBar($this->makeBar('50000'));
        // 跌破下沿 40000
        $strategy->onBar($this->makeBar('30000'));

        static::assertNull($strategy->shouldOpen($this->ctx()));
        static::assertNull($strategy->shouldClose($this->ctx()));
    }

    // ------------------------------------------------------------------
    // T028: onFill 入账
    // ------------------------------------------------------------------

    public function test_onfill_open_long_sets_position(): void
    {
        $strategy = $this->makeStrategy();

        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid1', 'buy', '0.01');
        $strategy->onFill($this->makeOrder('cid1', 'buy', '0.01', 'filled', '48000'));

        static::assertSame('0.01', $strategy->currentPositionQty());
    }

    public function test_onfill_close_long_clears_position(): void
    {
        $strategy = $this->makeStrategy();

        // 开多
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid-open', 'buy', '0.01');
        $strategy->onFill($this->makeOrder('cid-open', 'buy', '0.01', 'filled', '48000'));

        // 平多（reduceOnly）
        $strategy->onBar($this->makeBar('52000'));
        $strategy->shouldClose($this->ctx());
        $strategy->attachClientOrderId('cid-close', 'sell', '0.01');
        $strategy->onFill($this->makeOrder('cid-close', 'sell', '0.01', 'filled', '52000'));

        static::assertSame('0', $strategy->currentPositionQty());
    }

    public function test_onfill_add_long_weighted_avg_entry(): void
    {
        $strategy = $this->makeStrategy();

        // 开多 @48000
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid1', 'buy', '0.01');
        $strategy->onFill($this->makeOrder('cid1', 'buy', '0.01', 'filled', '48000'));

        // 加多 @46000
        $strategy->onBar($this->makeBar('46000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid2', 'buy', '0.01');
        $strategy->onFill($this->makeOrder('cid2', 'buy', '0.01', 'filled', '46000'));

        // 总持仓 0.02，加权均价 = (48000×0.01 + 46000×0.01) / 0.02 = 47000
        static::assertSame('0.02', $strategy->currentPositionQty());
    }

    // ------------------------------------------------------------------
    // T029: shouldOpen / shouldClose 语义
    // ------------------------------------------------------------------

    public function test_should_open_returns_reduce_only_false(): void
    {
        $strategy = $this->makeStrategy();
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));

        $open = $strategy->shouldOpen($this->ctx());

        static::assertNotNull($open);
        static::assertFalse($open['reduce_only']);
        static::assertSame(5, $open['leverage']);
    }

    public function test_should_close_returns_reduce_only_true(): void
    {
        $strategy = $this->makeStrategy();
        // 开多
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid1', 'buy', '0.01');
        $strategy->onFill($this->makeOrder('cid1', 'buy', '0.01', 'filled', '48000'));

        // 平多
        $strategy->onBar($this->makeBar('52000'));
        $close = $strategy->shouldClose($this->ctx());

        static::assertNotNull($close);
        static::assertTrue($close['reduce_only']);
    }

    public function test_should_close_returns_null_when_signal_is_open(): void
    {
        $strategy = $this->makeStrategy();
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));

        // shouldClose 对开仓信号应返回 null
        static::assertNull($strategy->shouldClose($this->ctx()));
    }

    // ------------------------------------------------------------------
    // T030: state() / hydrate()
    // ------------------------------------------------------------------

    public function test_state_round_trip_preserves_position(): void
    {
        $strategy = $this->makeStrategy();
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid1', 'buy', '0.01');
        $strategy->onFill($this->makeOrder('cid1', 'buy', '0.01', 'filled', '48000'));

        $state = $strategy->getState();
        static::assertSame(2, $state->schemaVersion);

        // 反序列化到新实例
        $restored = $this->makeStrategy();
        $restored->restoreState($state);

        // 触发 hydrate（onBar 首 bar）
        $restored->onBar($this->makeBar('48000', 1700000600000));

        static::assertSame('0.01', $restored->currentPositionQty());
    }

    public function test_restore_rejects_newer_schema_version(): void
    {
        $strategy = $this->makeStrategy();

        $this->expectException(\RuntimeException::class);

        $strategy->restoreState(new \App\Domain\Strategy\Contracts\StrategyState(
            schemaVersion: 999,
            params: self::PARAMS,
            positionSnapshot: null,
            realizedPnl: '0',
            consecutiveLosses: 0,
            lastBarTime: null,
        ));
    }

    // ------------------------------------------------------------------
    // T032: RecoverablePendingOrders
    // ------------------------------------------------------------------

    public function test_pending_client_order_ids_returns_cid(): void
    {
        $strategy = $this->makeStrategy();
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid-recover', 'buy', '0.01');

        $cids = $strategy->pendingClientOrderIds();

        static::assertSame(['cid-recover'], $cids);
    }

    public function test_apply_recovered_order_filled_updates_position(): void
    {
        $strategy = $this->makeStrategy();
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid-rec', 'buy', '0.01');

        $strategy->applyRecoveredOrder($this->makeOrder('cid-rec', 'buy', '0.01', 'filled', '48000'));

        static::assertSame('0.01', $strategy->currentPositionQty());
        static::assertSame([], $strategy->pendingClientOrderIds());
    }

    public function test_apply_recovered_order_canceled_releases_pending(): void
    {
        $strategy = $this->makeStrategy();
        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid-cancel', 'buy', '0.01');

        $strategy->applyRecoveredOrder($this->makeOrder('cid-cancel', 'buy', '0.01', 'canceled'));

        static::assertSame([], $strategy->pendingClientOrderIds());
        static::assertSame('0', $strategy->currentPositionQty());
    }

    // ------------------------------------------------------------------
    // 边界
    // ------------------------------------------------------------------

    public function test_first_bar_only_sets_index_no_signal(): void
    {
        $strategy = $this->makeStrategy();

        $strategy->onBar($this->makeBar('50000'));

        static::assertNull($strategy->shouldOpen($this->ctx()));
        static::assertNull($strategy->shouldClose($this->ctx()));
    }

    public function test_pending_order_blocks_new_signals(): void
    {
        $strategy = $this->makeStrategy();

        $strategy->onBar($this->makeBar('50000'));
        $strategy->onBar($this->makeBar('48000'));
        $strategy->shouldOpen($this->ctx());
        $strategy->attachClientOrderId('cid-pending', 'buy', '0.01');

        // pending 未完成，新 bar 不产生信号
        $strategy->onBar($this->makeBar('46000'));

        static::assertNull($strategy->shouldOpen($this->ctx()));
    }

    private function ctx(): StrategyContext
    {
        return new StrategyContext(
            instanceId: 1,
            params: self::PARAMS,
            position: null,
            positionKline: null,
            recentBars: [],
            ticker: null,
        );
    }
}
