<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyState;
use App\Domain\Strategy\Spot\SpotDcaStrategy;
use PHPUnit\Framework\TestCase;

/**
 * 现货 DCA 策略纯单元测试（P5.5 步骤 2 验收 #1-#8 + 崩溃恢复语义）。
 *
 * 不启动 Laravel、无外部依赖；时间用注入的 bar closeTime（UTC），金额 bcmath。
 */
class SpotDcaStrategyTest extends TestCase
{
    private const T0 = 1_760_000_000; // 任意 UTC 秒锚点

    private function dca(array $override = []): SpotDcaStrategy
    {
        $params = $override + [
            'amount_per_order' => '20',
            'interval_minutes' => 1,
            'max_orders' => 3,
        ];

        return new SpotDcaStrategy(
            990401,
            $params,
            new StrategyContext(990401, $params, null, null, [], null),
        );
    }

    private function bar(string $close, int $sec): ExchangeKline
    {
        $ms = $sec * 1000;

        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '1m',
            openTime: $ms - 60_000,
            closeTime: $ms,
            open: $close,
            high: $close,
            low: $close,
            close: $close,
            volume: '1',
            quoteVolume: '1',
            tradesCount: 1,
        );
    }

    /**
     * 驱动一根 bar 并消费买单信号；返回信号（无信号 null）。
     */
    private function driveBuy(SpotDcaStrategy $dca, string $close, int $sec): ?array
    {
        $dca->onBar($this->bar($close, $sec));
        $signal = $dca->shouldOpen(new StrategyContext(990401, [], null, null, [], null));
        if ($signal !== null) {
            // 模拟 Runner：信号消费后绑定 cid
            $dca->attachClientOrderId('cid-'.$sec, 'buy', $signal['qty']);
        }

        return $signal;
    }

    private function fill(SpotDcaStrategy $dca, string $cid, string $qty, string $price, int $filledAt): void
    {
        $dca->onFill(new ExchangeOrder(
            exchangeOrderId: 'ex-'.$cid,
            clientOrderId: $cid,
            symbol: 'BTCUSDT',
            side: 'buy',
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
            placedAt: $filledAt,
            filledAt: $filledAt,
        ));
    }

    public function test_first_bar_only_anchors_no_signal(): void
    {
        $dca = $this->dca();

        static::assertNull($this->driveBuy($dca, '60000', self::T0));
        static::assertSame((string) self::T0, (string) $dca->getState()->positionSnapshot['anchor_at']);
        static::assertSame(0, $dca->getState()->positionSnapshot['orders_count']);
    }

    public function test_before_interval_no_signal(): void
    {
        $dca = $this->dca();
        $this->driveBuy($dca, '60000', self::T0); // anchor

        static::assertNull($this->driveBuy($dca, '59000', self::T0 + 30));
        static::assertNull($this->driveBuy($dca, '59000', self::T0 + 59));
    }

    public function test_buy_at_interval_with_amount_over_price_qty(): void
    {
        $dca = $this->dca();
        $this->driveBuy($dca, '60000', self::T0);

        // 20 / 60000 = 0.000333333... → 8 位截断 0.00033333
        $signal = $this->driveBuy($dca, '60000', self::T0 + 60);
        static::assertNotNull($signal);
        static::assertSame('buy', $signal['side']);
        static::assertSame('0.00033333', $signal['qty']);
        static::assertSame(['cid-'.(self::T0 + 60)], $dca->pendingClientOrderIds());
    }

    public function test_filled_advances_count_spent_and_cooldown(): void
    {
        $dca = $this->dca();
        $this->driveBuy($dca, '60000', self::T0);
        $this->driveBuy($dca, '60000', self::T0 + 60);
        $this->fill($dca, 'cid-'.(self::T0 + 60), '0.00033333', '60000', self::T0 + 61);

        $snap = $dca->getState()->positionSnapshot;
        static::assertSame(1, $snap['orders_count']);
        static::assertSame(0, bccomp((string) $snap['total_spent'], '19.9998', 8)); // 0.00033333*60000
        static::assertSame((string) (self::T0 + 61), (string) $snap['last_order_at']);
        static::assertNull($snap['pending_cid']);

        // 冷却未到（距成交 30s）→ 无信号
        static::assertNull($this->driveBuy($dca, '60000', self::T0 + 91));
        // 冷却到（距成交 60s）→ 第二笔
        static::assertNotNull($this->driveBuy($dca, '50000', self::T0 + 121));
    }

    public function test_second_buy_uses_latest_price_and_spent_accumulates(): void
    {
        $dca = $this->dca();
        $this->driveBuy($dca, '60000', self::T0);
        $this->driveBuy($dca, '60000', self::T0 + 60);
        $this->fill($dca, 'cid-'.(self::T0 + 60), '0.00033333', '60000', self::T0 + 61);

        // 第二笔：价跌到 50000 → qty = 20/50000 = 0.0004
        $signal = $this->driveBuy($dca, '50000', self::T0 + 121);
        static::assertSame(0, bccomp($signal['qty'], '0.0004', 8));
        $this->fill($dca, 'cid-'.(self::T0 + 121), '0.0004', '50000', self::T0 + 122);

        $snap = $dca->getState()->positionSnapshot;
        static::assertSame(2, $snap['orders_count']);
        static::assertSame(0, bccomp((string) $snap['total_spent'], '39.9998', 8));
    }

    public function test_max_orders_then_permanent_stop(): void
    {
        $dca = $this->dca();
        $this->driveBuy($dca, '60000', self::T0);

        // 成交时刻比信号晚 1s，冷却基准随之顺延：信号点 60/121/182，成交 61/122/183
        $buySecs = [self::T0 + 60, self::T0 + 121, self::T0 + 182];
        foreach ($buySecs as $i => $sec) {
            $signal = $this->driveBuy($dca, '60000', $sec);
            static::assertNotNull($signal, '第 '.($i + 1).' 笔应产生信号');
            $this->fill($dca, 'cid-'.$sec, $signal['qty'], '60000', $sec + 1);
        }

        // 后续周期：达 max_orders=3，永久停止
        static::assertNull($this->driveBuy($dca, '60000', self::T0 + 243));
        static::assertNull($this->driveBuy($dca, '10000', self::T0 + 303));
        static::assertSame(3, $dca->getState()->positionSnapshot['orders_count']);
    }

    public function test_failed_fill_keeps_count_and_cooldown_open_for_retry(): void
    {
        $dca = $this->dca();
        $this->driveBuy($dca, '60000', self::T0);
        $this->driveBuy($dca, '60000', self::T0 + 60);

        // 下单失败：不计次数、冷却不推进
        $dca->onFill(new ExchangeOrder(
            exchangeOrderId: 'ex-x', clientOrderId: 'cid-'.(self::T0 + 60),
            symbol: 'BTCUSDT', side: 'buy', type: 'market', quantity: '0.00033333',
            price: null, stopPrice: null, leverage: null, reduceOnly: false,
            status: 'failed', filledQty: '0', avgFillPrice: null, fee: null,
            placedAt: self::T0 + 60, filledAt: null,
        ));

        $snap = $dca->getState()->positionSnapshot;
        static::assertSame(0, $snap['orders_count']);
        static::assertSame((string) self::T0, (string) $snap['anchor_at']); // 锚点仍可作为冷却基准

        // 失败发生在 T0+60，锚点 T0；T0+120 距锚点 120s ≥ 间隔 → 可重试
        $retry = $this->driveBuy($dca, '60000', self::T0 + 120);
        static::assertNotNull($retry);
    }

    public function test_state_roundtrip_continues_count_and_cooldown(): void
    {
        $dca = $this->dca();
        $this->driveBuy($dca, '60000', self::T0);
        $this->driveBuy($dca, '60000', self::T0 + 60);
        $this->fill($dca, 'cid-'.(self::T0 + 60), '0.00033333', '60000', self::T0 + 61);
        $this->driveBuy($dca, '50000', self::T0 + 121);
        $this->fill($dca, 'cid-'.(self::T0 + 121), '0.0004', '50000', self::T0 + 122);

        // 模拟进程重启：用快照构造新实例
        $restored = $this->dca();
        $restored->restoreState(StrategyState::fromArray($dca->getState()->toArray()));

        $snap = $restored->getState()->positionSnapshot;
        static::assertSame(2, $snap['orders_count']);
        static::assertSame((string) (self::T0 + 122), (string) $snap['last_order_at']);

        // 冷却未到无信号，到点产生第 3 笔（而非从第 1 笔重新开始）
        static::assertNull($this->driveBuy($restored, '40000', self::T0 + 150));
        $third = $this->driveBuy($restored, '40000', self::T0 + 182);
        static::assertNotNull($third);
        static::assertSame(0, bccomp($third['qty'], '0.0005', 8)); // 20/40000
    }

    public function test_amount_too_small_truncated_to_zero_gives_no_signal(): void
    {
        // 0.0001 USDT / 60000 = 1.66e-9，8 位截断为 0 → 不产生废单
        $dca = $this->dca(['amount_per_order' => '0.0001', 'interval_minutes' => 1, 'max_orders' => 3]);
        $this->driveBuy($dca, '60000', self::T0);
        static::assertNull($this->driveBuy($dca, '60000', self::T0 + 60));
    }

    public function test_crash_recovery_filled_pending_books_order(): void
    {
        // 崩溃前：已锚定 + 有一笔在途买单
        $dca = $this->dca();
        $this->driveBuy($dca, '60000', self::T0);
        $this->driveBuy($dca, '60000', self::T0 + 60);
        static::assertSame(['cid-'.(self::T0 + 60)], $dca->pendingClientOrderIds());

        $restored = $this->dca();
        $restored->restoreState(StrategyState::fromArray($dca->getState()->toArray()));
        static::assertSame(['cid-'.(self::T0 + 60)], $restored->pendingClientOrderIds());

        // 交易所核实：已成交 → 入账并推进冷却
        $restored->applyRecoveredOrder(new ExchangeOrder(
            exchangeOrderId: 'ex-r', clientOrderId: 'cid-'.(self::T0 + 60),
            symbol: 'BTCUSDT', side: 'buy', type: 'market', quantity: '0.00033333',
            price: null, stopPrice: null, leverage: null, reduceOnly: false,
            status: 'filled', filledQty: '0.00033333', avgFillPrice: '60000', fee: '0',
            placedAt: self::T0 + 60, filledAt: self::T0 + 61,
        ));

        static::assertSame([], $restored->pendingClientOrderIds());
        static::assertSame(1, $restored->getState()->positionSnapshot['orders_count']);
    }

    public function test_crash_recovery_canceled_clears_pending_and_retries_next_interval(): void
    {
        $dca = $this->dca();
        $this->driveBuy($dca, '60000', self::T0);
        $this->driveBuy($dca, '60000', self::T0 + 60);

        $restored = $this->dca();
        $restored->restoreState(StrategyState::fromArray($dca->getState()->toArray()));

        // 交易所核实：订单不存在（合成 failed）→ 清除在途，冷却不推进
        $restored->applyRecoveredOrder(new ExchangeOrder(
            exchangeOrderId: '0', clientOrderId: 'cid-'.(self::T0 + 60),
            symbol: 'BTCUSDT', side: 'buy', type: 'market', quantity: '0.00033333',
            price: null, stopPrice: null, leverage: null, reduceOnly: false,
            status: 'failed', filledQty: '0', avgFillPrice: null, fee: null,
            placedAt: self::T0 + 60, filledAt: null,
        ));

        static::assertSame([], $restored->pendingClientOrderIds());
        static::assertSame(0, $restored->getState()->positionSnapshot['orders_count']);

        // 下一周期（相对 anchor 又过一个 interval）可重新买入
        static::assertNotNull($this->driveBuy($restored, '60000', self::T0 + 120));
    }

    public function test_unresolved_pending_cid_blocks_new_signals(): void
    {
        $dca = $this->dca();
        $this->driveBuy($dca, '60000', self::T0);
        $this->driveBuy($dca, '60000', self::T0 + 60); // 在途未决

        // 多个后续周期都不得再产生信号（单飞防重复下单）
        static::assertNull($this->driveBuy($dca, '60000', self::T0 + 120));
        static::assertNull($this->driveBuy($dca, '10000', self::T0 + 180));
        static::assertSame(['cid-'.(self::T0 + 60)], $dca->pendingClientOrderIds());
    }
}
