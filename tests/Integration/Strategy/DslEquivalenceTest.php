<?php

declare(strict_types=1);

namespace Tests\Integration\Strategy;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Signal\IndicatorCalculator;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\Custom\DslStrategy;
use App\Domain\Strategy\Spot\SpotDcaStrategy;
use App\Domain\Strategy\Spot\SpotGridStrategy;
use PHPUnit\Framework\TestCase;

/**
 * DSL 与内置策略语义等价（spec 006 WS6 T023/T024 / FR-007 / SC-002）。
 *
 * 固定 bar fixture + 固定参数，分别驱动等价 DSL 与内置策略，断言决策序列完全一致：
 *   - SpotDca parity：entry `{op:true}` + qty_pct=1 等价于 amount_per_order/close 的每周期定额买入
 *   - SpotGrid parity：entry close<level / exit close>=level 的单格网格，等价于 SpotGrid 的逐格买卖
 *   - 指标逐点一致：DSL 引用指标与内置 IndicatorCalculator 同源输出（含 NaN→null）
 */
class DslEquivalenceTest extends TestCase
{
    private const AMOUNT = '100';

    private const INVESTMENT = '1000';

    // ------------------------------------------------------------------
    // T023: SpotDca parity
    // ------------------------------------------------------------------

    public function test_dca_parity_decision_sequence_matches(): void
    {
        $bars = $this->linearBars(10, 100_000, 1); // close: 100,101,...,109

        $dca = new SpotDcaStrategy(1, [
            'amount_per_order' => self::AMOUNT,
            'interval_minutes' => 1,
            'max_orders' => 100,
        ], $this->ctx());

        $dsl = new DslStrategy(2, [
            'dsl_script' => $this->dcaScript(),
            'symbol' => 'BTCUSDT',
            'tenant_id' => 1,
            'allocated_capital' => self::AMOUNT,
        ], $this->ctx());

        $dcaSignals = [];
        $dslSignals = [];

        foreach ($bars as $i => $bar) {
            // bar 1：DCA 锚定（不下单），DSL 产出并成交一次以推进状态；自 bar 2 起两者每 bar 定额买入
            $dcaSig = $this->drive($dca, $bar, $i);
            $dslSig = $this->drive($dsl, $bar, $i);

            if ($i === 0) {
                // 首 bar：DCA 锚定无信号；DSL 已成交（warmup），不纳入等价比对
                static::assertNull($dcaSig, 'DCA 首 bar 仅锚定不下单');
                static::assertNotNull($dslSig, 'DSL 首 bar 正常开仓（warmup）');

                continue;
            }

            $dcaSignals[] = $this->normalize($dcaSig, $bar);
            $dslSignals[] = $this->normalize($dslSig, $bar);
        }

        static::assertSame($dcaSignals, $dslSignals, '自第 2 根 bar 起，DCA 与等价 DSL 决策序列（bar/side/qty）100% 一致');
        static::assertNotEmpty($dcaSignals, '应有足够信号证明等价（非空对比）');
    }

    // ------------------------------------------------------------------
    // T024: SpotGrid parity（单格网格：close<level 买 / close>=level 卖）
    // ------------------------------------------------------------------

    public function test_grid_parity_decision_sequence_matches(): void
    {
        // 价格在 level=110 上下往返，形成 buy-sell 循环
        $closes = [115, 105, 115, 105, 115, 105];
        $bars = $this->bars($closes, 100_000);

        $grid = new SpotGridStrategy(3, [
            'lower_price' => '100',
            'upper_price' => '120',
            'grid_count' => 2,
            'investment_amount' => self::INVESTMENT,
        ], $this->ctx());

        $dsl = new DslStrategy(4, [
            'dsl_script' => $this->gridScript(),
            'symbol' => 'BTCUSDT',
            'tenant_id' => 1,
            'allocated_capital' => self::INVESTMENT,
        ], $this->ctx());

        $gridSignals = [];
        $dslSignals = [];

        foreach ($bars as $i => $bar) {
            $gridSig = $this->drive($grid, $bar, $i);
            $dslSig = $this->drive($dsl, $bar, $i);

            $gridSignals[] = $this->normalize($gridSig, $bar);
            $dslSignals[] = $this->normalize($dslSig, $bar);
        }

        static::assertSame($gridSignals, $dslSignals, '单格网格下 SpotGrid 与等价 DSL 决策序列（bar/side/qty）100% 一致');
    }

    // ------------------------------------------------------------------
    // T024: 指标逐点一致（DSL 引用 ma7 与内置 IndicatorCalculator 同源）
    // ------------------------------------------------------------------

    public function test_dsl_indicator_matches_builtin_calculator_pointwise(): void
    {
        $bars = $this->linearBars(30, 100, 1); // 足够填满 ma7 窗口
        $calculator = new IndicatorCalculator();

        $dsl = new DslStrategy(5, [
            'dsl_script' => $this->maScript(),
            'symbol' => 'BTCUSDT',
            'tenant_id' => 1,
            'allocated_capital' => self::AMOUNT,
        ], $this->ctx());

        foreach ($bars as $bar) {
            $dsl->onBar($bar);
        }

        // 取 DSL 最近一次求值使用的指标快照：通过反射读 private recentBars 后重算，
        // 断言 DSL 的 ma7 当前值 == IndicatorCalculator::compute 在同样 bars 上的 ma7.v
        $reflection = new \ReflectionClass($dsl);
        $prop = $reflection->getProperty('recentBars');
        /** @var ExchangeKline[] $recentBars */
        $recentBars = $prop->getValue($dsl);

        $dslSnapshot = $this->dslSnapshot($dsl, $recentBars);
        $builtin = $calculator->compute($recentBars, ['ma_periods' => [7]])['ma'][7]['v'] ?? null;

        $dslMa7 = $dslSnapshot['now']['ma'][7]['v'] ?? null;

        static::assertSame($this->nullableNum($dslMa7), $this->nullableNum($builtin), 'DSL ma7 当前值与内置计算器逐点一致');
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    /**
     * 驱动单 bar：onBar → shouldOpen/shouldClose → attachCid → onFill（成交）。
     *
     * @return array{side:string,qty:string}|null
     */
    private function drive(StrategyInterface $strategy, ExchangeKline $bar, int $seq): ?array
    {
        $strategy->onBar($bar);
        $ctx = $this->ctx();

        $signal = $strategy->shouldOpen($ctx) ?? $strategy->shouldClose($ctx);
        if ($signal === null) {
            return null;
        }

        $cid = 'cid-'.$seq;
        $strategy->attachClientOrderId($cid, (string) $signal['side'], (string) $signal['qty']);
        $strategy->onFill($this->filledOrder($cid, (string) $signal['side'], (string) $signal['qty'], $bar->close, intdiv($bar->closeTime, 1000)));

        return ['side' => (string) $signal['side'], 'qty' => (string) $signal['qty']];
    }

    /**
     * @param array{side:string,qty:string}|null $signal
     *
     * @return array{bar:int,side:string,qty:string}|null
     */
    private function normalize(?array $signal, ExchangeKline $bar): ?array
    {
        if ($signal === null) {
            return null;
        }

        return ['bar' => $bar->openTime, 'side' => $signal['side'], 'qty' => $signal['qty']];
    }

    private function ctx(): StrategyContext
    {
        return new StrategyContext(1, [], null, null, [], null);
    }

    private function filledOrder(string $cid, string $side, string $qty, string $price, int $filledAt): ExchangeOrder
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
            placedAt: $filledAt,
            filledAt: $filledAt,
        );
    }

    /**
     * 生成 close 线性递增的 1 分钟 bar。
     *
     * @return ExchangeKline[]
     */
    private function linearBars(int $count, int $startOpenTime, int $startClose): array
    {
        $closes = [];
        for ($i = 0; $i < $count; $i++) {
            $closes[] = (string) ($startClose + $i);
        }

        return $this->bars($closes, $startOpenTime);
    }

    /**
     * @param list<string|int|float> $closes
     *
     * @return ExchangeKline[]
     */
    private function bars(array $closes, int $startOpenTime): array
    {
        $bars = [];
        $openTime = $startOpenTime;
        foreach ($closes as $close) {
            $close = (string) $close;
            $bars[] = new ExchangeKline(
                exchangeCode: 'binance',
                symbol: 'BTCUSDT',
                interval: '1m',
                openTime: $openTime,
                closeTime: $openTime + 59_999,
                open: $close,
                high: $close,
                low: $close,
                close: $close,
                volume: '1',
                quoteVolume: '1',
                tradesCount: 1,
            );
            $openTime += 60_000;
        }

        return $bars;
    }

    /**
     * 反射读取 DslStrategy 的 IndicatorSnapshotBuilder，对给定 bars 构建快照。
     *
     * @param ExchangeKline[] $bars
     *
     * @return array{now:array<string,mixed>,prev:array<string,mixed>|null}
     */
    private function dslSnapshot(DslStrategy $dsl, array $bars): array
    {
        $reflection = new \ReflectionClass($dsl);
        $builderProp = $reflection->getProperty('snapshotBuilder');
        /** @var \App\Domain\Strategy\Custom\Dsl\IndicatorSnapshotBuilder $builder */
        $builder = $builderProp->getValue($dsl);

        $programProp = $reflection->getProperty('program');
        /** @var \App\Domain\Strategy\Custom\Dsl\DslProgram $program */
        $program = $programProp->getValue($dsl);

        return $builder->build($program, $bars, null);
    }

    private function nullableNum(mixed $value): ?float
    {
        if ($value === null || $value === '' || (! is_numeric($value) && ! is_float($value) && ! is_int($value))) {
            return null;
        }

        return round((float) $value, 8);
    }

    private function dcaScript(): string
    {
        return implode("\n", [
            'version: 1',
            'name: dca-parity',
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
            '      op: true',
            '    qty_pct: "1"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: gt',
            '      left:',
            '        const: "0"',
            '      right:',
            '        const: "1"',
            '    qty_pct: "1.0"',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);
    }

    private function gridScript(): string
    {
        return implode("\n", [
            'version: 1',
            'name: grid-parity',
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
            '      op: lt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "110"',
            '    qty_pct: "0.5"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: gte',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "110"',
            '    qty_pct: "1.0"',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);
    }

    private function maScript(): string
    {
        return implode("\n", [
            'version: 1',
            'name: ma-indicator',
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
            '        indicator: ma',
            '        period: 7',
            '      right:',
            '        const: "0"',
            '    qty_pct: "0.5"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: true',
            '    qty_pct: "1.0"',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);
    }
}
