<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Dsl;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Custom\DslStrategy;
use PHPUnit\Framework\TestCase;

/**
 * DslStrategy 数量换算精度测试（tasks T012 / FR-009 / SC-010，constitution II）。
 *
 * `qty_pct` 换算全走 bcmath（开仓=分配资金名义额比例，平仓=持仓数量比例）：
 * 截断到 8 位、结果 ≤ 0 不产出、显式 `qty` 原样透传，绝不经过 float。
 */
class DslPrecisionTest extends TestCase
{
    public function test_tiny_qty_pct_truncates_to_zero_and_produces_no_signal(): void
    {
        // bcmul(1000, 0.00000001, 8)=0.00001000；bcdiv(0.00001000, 1000000, 8)=0.00000000 → 不产出
        $strategy = $this->strategy($this->script('    qty_pct: "0.00000001"'));

        $strategy->onBar($this->bar(1_760_000_040_000, '1000000'));

        static::assertNull($strategy->shouldOpen($this->ctx()), '截断为 0 的数量不产出开仓信号');
    }

    public function test_huge_notional_keeps_bcmath_precision_without_float_loss(): void
    {
        // bcdiv(1000000000000, 3, 8)=333333333333.33333333；float 会丢精度，bcmath 零损
        $strategy = $this->strategy($this->script('    qty_pct: "1"', '    qty: "1"', '0'), '1000000000000');

        $strategy->onBar($this->bar(1_760_000_040_000, '3'));
        $signal = $strategy->shouldOpen($this->ctx());

        static::assertNotNull($signal);
        static::assertSame('333333333333.33333333', $signal['qty']);
    }

    public function test_qty_pct_result_is_truncated_at_scale_eight(): void
    {
        // bcmul(1000, 0.1, 8)=100.00000000；bcdiv(100.00000000, 7, 8)=14.28571428（截断非四舍五入）
        $strategy = $this->strategy($this->script('    qty_pct: "0.1"', '    qty: "1"', '0'));

        $strategy->onBar($this->bar(1_760_000_040_000, '7'));
        $signal = $strategy->shouldOpen($this->ctx());

        static::assertNotNull($signal);
        static::assertSame('14.28571428', $signal['qty']);
    }

    public function test_close_qty_pct_uses_position_quantity(): void
    {
        $strategy = $this->strategy($this->script('    qty_pct: "0.5"', '    qty_pct: "0.25"'));

        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx());
        static::assertNotNull($open);
        $strategy->attachClientOrderId('cid-open', (string) $open['side'], (string) $open['qty']);
        $strategy->onFill($this->filled('cid-open', 'buy', (string) $open['qty'], '110'));

        // 持仓 4.54545454 × 0.25 = 1.136363635 → 截断 1.13636363
        $strategy->onBar($this->bar(1_760_000_100_000, '90'));
        $close = $strategy->shouldClose($this->ctx());

        static::assertNotNull($close);
        static::assertSame('1.13636363', $close['qty']);
    }

    public function test_close_qty_pct_returns_null_when_position_is_empty(): void
    {
        // 无持仓（未成交）时 qty_pct 平仓不可得 → 不产出
        $strategy = $this->strategy($this->script('    qty_pct: "0.5"', '    qty_pct: "1"'));
        $strategy->onBar($this->bar(1_760_000_040_000, '90'));

        static::assertNull($strategy->shouldClose($this->ctx()));
    }

    public function test_explicit_qty_is_passed_through_verbatim(): void
    {
        // 显式 qty 原样透传（不经 bcmath 换算），保留全部有效位
        $strategy = $this->strategy($this->script('    qty: "0.00123456789"'));

        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $signal = $strategy->shouldOpen($this->ctx());

        static::assertNotNull($signal);
        static::assertSame('0.00123456789', $signal['qty']);
    }

    public function test_explicit_close_qty_is_passed_through_verbatim(): void
    {
        $strategy = $this->strategy($this->script('    qty: "1"', '    qty: "0.5"'));

        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx());
        static::assertNotNull($open);
        $strategy->attachClientOrderId('cid-open', (string) $open['side'], (string) $open['qty']);
        $strategy->onFill($this->filled('cid-open', 'buy', '1', '110'));

        $strategy->onBar($this->bar(1_760_000_100_000, '90'));
        $close = $strategy->shouldClose($this->ctx());

        static::assertNotNull($close);
        static::assertSame('0.5', $close['qty']);
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function strategy(string $script, string $capital = '1000'): DslStrategy
    {
        $params = [
            'dsl_script' => $script,
            'symbol' => 'BTCUSDT',
            'tenant_id' => 1,
            'allocated_capital' => $capital,
        ];

        return new DslStrategy(1, $params, new StrategyContext(1, $params, null, null, [], null));
    }

    private function ctx(): StrategyContext
    {
        return new StrategyContext(1, [], null, null, [], null);
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

    private function filled(string $cid, string $side, string $qty, string $price): ExchangeOrder
    {
        return new ExchangeOrder(
            exchangeOrderId: 'ex-'.$cid,
            clientOrderId: $cid,
            symbol: 'BTCUSDT',
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
            placedAt: 1_760_000_000,
            filledAt: 1_760_000_001,
        );
    }

    /**
     * 精度脚本：close > entryConst 开多；close < 100 平多；数量字段由调用方注入。
     */
    private function script(string $entryQtyLine, string $exitQtyLine = '    qty: "1"', string $entryConst = '100'): string
    {
        return implode("\n", [
            'version: 1',
            'name: precision',
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
            '        const: "'.$entryConst.'"',
            $entryQtyLine,
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: lt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            $exitQtyLine,
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);
    }
}
