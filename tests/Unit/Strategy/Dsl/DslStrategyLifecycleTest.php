<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Dsl;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Custom\Dsl\DslValidationException;
use App\Domain\Strategy\Custom\DslStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DslStrategy 生命周期测试（tasks T010 / FR-006/009/013）。
 *
 * 覆盖 onBar 求值 → pending_signal → shouldOpen/shouldClose、5 动作枚举映射、
 * cooldown / max_position_pct / stop_loss / take_profit 风控闸门与 trigger=on_fill 语义。
 */
class DslStrategyLifecycleTest extends TestCase
{
    public function test_on_bar_produces_open_signal_with_qty_pct(): void
    {
        $strategy = $this->strategy($this->thresholdScript());
        $strategy->onBar($this->bar(1_760_000_040_000, '110'));

        $signal = $strategy->shouldOpen($this->ctx());

        static::assertNotNull($signal);
        static::assertSame('buy', $signal['side']);
        // bcdiv(bcmul("1000", "0.5", 8)=500.00000000, "110", 8) = 4.54545454
        static::assertSame('4.54545454', $signal['qty']);
        static::assertNull($signal['price'], '市价单不透传参考价');
        static::assertNull($strategy->shouldClose($this->ctx()), 'open 信号不得被当作平仓信号');
    }

    public function test_close_signal_uses_position_qty(): void
    {
        $strategy = $this->strategy($this->thresholdScript());

        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx());
        static::assertNotNull($open);
        $strategy->attachClientOrderId('cid-open', (string) $open['side'], (string) $open['qty']);
        $strategy->onFill($this->filled('cid-open', 'buy', (string) $open['qty'], '110'));

        // 死叉/跌破阈值 bar → close_long（qty_pct="1.0" → 全平）
        $strategy->onBar($this->bar(1_760_000_100_000, '90'));
        $close = $strategy->shouldClose($this->ctx());

        static::assertNotNull($close);
        static::assertSame('sell', $close['side']);
        static::assertSame(bcmul('4.54545454', '1.0', 8), $close['qty']);
    }

    #[DataProvider('actionCases')]
    public function test_five_action_enum_mapping(string $action, ?string $kind, ?string $side): void
    {
        $strategy = $this->strategy($this->actionScript($action));
        $strategy->onBar($this->bar(1_760_000_040_000, '110'));

        $pending = $strategy->getState()->positionSnapshot['dsl']['pending_signal'];

        if ($kind === null) {
            static::assertNull($pending, 'noop 不产信号');

            return;
        }

        static::assertIsArray($pending);
        static::assertSame($action, $pending['action']);
        static::assertSame($kind, $pending['kind']);
        static::assertSame($side, $pending['side']);
    }

    /**
     * @return array<string,array{string,?string,?string}>
     */
    public static function actionCases(): array
    {
        return [
            'open_long' => ['open_long', 'open', 'buy'],
            'open_short' => ['open_short', 'open', 'sell'],
            'close_long' => ['close_long', 'close', 'sell'],
            'close_short' => ['close_short', 'close', 'buy'],
            'noop' => ['noop', null, null],
        ];
    }

    public function test_meta_symbol_mismatch_is_rejected(): void
    {
        $script = str_replace('symbol: BTCUSDT', 'symbol: ETHUSDT', $this->thresholdScript());

        $this->expectException(DslValidationException::class);

        $this->strategy($script);
    }

    public function test_invalid_script_is_rejected_at_construction(): void
    {
        $this->expectException(DslValidationException::class);

        $this->strategy("version: 1\nname: broken\nsymbol: BTCUSDT\n");
    }

    public function test_cooldown_blocks_next_bar_entry(): void
    {
        $strategy = $this->strategy($this->thresholdScript('on_bar', '  cooldown_bars: 1'));

        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx());
        static::assertNotNull($open);
        $strategy->attachClientOrderId('cid-open', (string) $open['side'], (string) $open['qty']);
        $strategy->onFill($this->filled('cid-open', 'buy', (string) $open['qty'], '110'));

        // cooldown_bars=1：紧随其后的 bar 仍被冷却闸门拦截
        $strategy->onBar($this->bar(1_760_000_100_000, '120'));
        static::assertNull($strategy->shouldOpen($this->ctx()), '冷却期开仓信号被拦截');

        // 冷却消耗完毕 → 恢复产出
        $strategy->onBar($this->bar(1_760_000_160_000, '130'));
        static::assertNotNull($strategy->shouldOpen($this->ctx()), '冷却结束后恢复开仓信号');
    }

    public function test_max_position_pct_blocks_oversized_entry(): void
    {
        // 分配资金 1000 × 0.1 = 100 名义额上限；qty_pct 0.5 @110 = 500 > 100 → 拒
        $strategy = $this->strategy($this->thresholdScript('on_bar', "  cooldown_bars: 0\n  max_position_pct: \"0.1\""));

        $strategy->onBar($this->bar(1_760_000_040_000, '110'));

        static::assertNull($strategy->shouldOpen($this->ctx()), '超出 max_position_pct 不产出开仓信号');
    }

    public function test_stop_loss_triggers_synthetic_close(): void
    {
        $strategy = $this->strategy($this->thresholdScript('on_bar', "  cooldown_bars: 0\n  stop_loss_pct: \"0.02\""));

        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx());
        static::assertNotNull($open);
        $strategy->attachClientOrderId('cid-open', (string) $open['side'], (string) $open['qty']);
        $strategy->onFill($this->filled('cid-open', 'buy', (string) $open['qty'], '110'));

        // (100-110)/110 = -0.0909 <= -0.02 → 合成止损平仓
        $strategy->onBar($this->bar(1_760_000_100_000, '100'));

        static::assertNull($strategy->shouldOpen($this->ctx()));
        $close = $strategy->shouldClose($this->ctx());
        static::assertNotNull($close);
        static::assertSame('sell', $close['side']);
        static::assertSame('4.54545454', $close['qty']);
    }

    public function test_take_profit_triggers_synthetic_close(): void
    {
        $strategy = $this->strategy($this->thresholdScript('on_bar', "  cooldown_bars: 0\n  take_profit_pct: \"0.05\""));

        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx());
        static::assertNotNull($open);
        $strategy->attachClientOrderId('cid-open', (string) $open['side'], (string) $open['qty']);
        $strategy->onFill($this->filled('cid-open', 'buy', (string) $open['qty'], '110'));

        // (120-110)/110 = 0.0909 >= 0.05 → 合成止盈平仓
        $strategy->onBar($this->bar(1_760_000_100_000, '120'));

        $close = $strategy->shouldClose($this->ctx());
        static::assertNotNull($close);
        static::assertSame('sell', $close['side']);
    }

    public function test_on_fill_trigger_reevaluates_after_fill(): void
    {
        // trigger=on_fill：回报入账后 onFill 重跑四段，condition 仍成立且单 bar 允许 2 笔 → 再生产 pending 信号
        $risk = "  cooldown_bars: 0\n  max_entries_per_bar: 2";
        $strategy = $this->strategy($this->thresholdScript('on_fill', $risk));

        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx());
        static::assertNotNull($open);
        $strategy->attachClientOrderId('cid-open', (string) $open['side'], (string) $open['qty']);

        $strategy->onFill($this->filled('cid-open', 'buy', (string) $open['qty'], '110'));

        $pending = $strategy->getState()->positionSnapshot['dsl']['pending_signal'];
        static::assertIsArray($pending, 'on_fill 触发器在回报后重跑四段求值');
        static::assertSame('entry', $pending['section']);
        static::assertSame('open', $pending['kind']);

        // 对照组：trigger=on_bar 的同一序列不重跑
        $onBar = $this->strategy($this->thresholdScript('on_bar', $risk));
        $onBar->onBar($this->bar(1_760_000_040_000, '110'));
        $openBar = $onBar->shouldOpen($this->ctx());
        static::assertNotNull($openBar);
        $onBar->attachClientOrderId('cid-open', (string) $openBar['side'], (string) $openBar['qty']);
        $onBar->onFill($this->filled('cid-open', 'buy', (string) $openBar['qty'], '110'));

        static::assertNull($onBar->getState()->positionSnapshot['dsl']['pending_signal']);
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function strategy(string $script): DslStrategy
    {
        $params = [
            'dsl_script' => $script,
            'symbol' => 'BTCUSDT',
            'tenant_id' => 1,
            'allocated_capital' => '1000',
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
     * 阈值脚本：close > 100 开多（qty_pct 0.5）；close < 100 平多（qty_pct 1.0）。
     */
    private function thresholdScript(string $trigger = 'on_bar', string $riskLines = '  cooldown_bars: 0'): string
    {
        return implode("\n", [
            'version: 1',
            'name: lifecycle',
            'symbol: BTCUSDT',
            'interval: 1m',
            'direction: long',
            'trigger: '.$trigger,
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
            $riskLines,
            '',
        ]);
    }

    /**
     * 单动作映射脚本（entry 恒真，exit 为 noop）；noop 不接受数量字段（编译期红线）。
     */
    private function actionScript(string $action): string
    {
        $lines = [
            'version: 1',
            'name: mapping',
            'symbol: BTCUSDT',
            'interval: 1m',
            'direction: both',
            'trigger: on_bar',
            'filter:',
            '  - action: noop',
            '    when:',
            '      op: true',
            'entry:',
            '  - action: '.$action,
            '    when:',
            '      op: true',
        ];

        if ($action !== 'noop') {
            $lines[] = '    qty: "1"';
        }

        return implode("\n", array_merge($lines, [
            'exit:',
            '  - action: noop',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]));
    }
}
