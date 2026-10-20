<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Dsl;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Signal\IndicatorCalculator;
use App\Domain\Strategy\Custom\Dsl\IndicatorSnapshotBuilder;
use App\Domain\Strategy\Custom\DslParser;
use PHPUnit\Framework\TestCase;

/**
 * DSL 指标快照测试（tasks T008 / FR-008 / data-model §5）。
 */
class DslIndicatorSnapshotTest extends TestCase
{
    private function program(string $yaml): \App\Domain\Strategy\Custom\Dsl\DslProgram
    {
        return (new DslParser())->parse($yaml);
    }

    /**
     * @param float[] $closes
     *
     * @return ExchangeKline[]
     */
    private function bars(array $closes): array
    {
        $t0 = 1_760_000_000;
        $bars = [];
        foreach (array_values($closes) as $i => $close) {
            $sec = $t0 + $i * 60;
            $bars[] = new ExchangeKline(
                exchangeCode: 'binance',
                symbol: 'BTCUSDT',
                interval: '1m',
                openTime: ($sec - 60) * 1000,
                closeTime: $sec * 1000,
                open: (string) $close,
                high: (string) $close,
                low: (string) $close,
                close: (string) $close,
                volume: '1',
                quoteVolume: '1',
                tradesCount: 1,
            );
        }

        return $bars;
    }

    private function yaml(string $section): string
    {
        return <<<YAML
        version: 1
        name: snap
        symbol: BTCUSDT
        interval: 1m
        direction: long
        filter:
          - action: noop
            when:
              op: true
        {$section}
        exit:
          - action: close_long
            when:
              op: gt
              left:
                const: "0"
              right:
                const: "1"
            qty: "1"
        risk:
          cooldown_bars: 0
        YAML;
    }

    public function testMaReusesBuiltinPrevAndMatchesCalculator(): void
    {
        $program = $this->program($this->yaml(<<<'YAML'
        entry:
          - action: open_long
            when:
              op: gt
              left:
                indicator: ma
                period: 7
              right:
                const: "0"
            qty: "1"
        YAML));

        $closes = range(1, 30);
        $bars = $this->bars($closes);

        $snapshot = (new IndicatorSnapshotBuilder())->build($program, $bars);
        $expected = (new IndicatorCalculator())->compute($bars, ['ma_periods' => [7]])['ma'][7];

        $this->assertSame($expected['v'], $snapshot['now']['ma'][7]['v']);
        $this->assertSame($expected['prev'], $snapshot['now']['ma'][7]['prev']);
        $this->assertNotNull($snapshot['now']['ma'][7]['prev']);
        $this->assertNotNull($snapshot['prev']['ma'][7]['prev']);
    }

    public function testMacdHistPrevPresent(): void
    {
        $program = $this->program($this->yaml(<<<'YAML'
        entry:
          - action: open_long
            when:
              op: gt
              left:
                indicator: macd
                source: hist
              right:
                const: "0"
            qty: "1"
        YAML));

        $snapshot = (new IndicatorSnapshotBuilder())->build($program, $this->bars(range(1, 60)));

        $this->assertNotNull($snapshot['now']['macd']['hist']);
        $this->assertNotNull($snapshot['now']['macd']['hist_prev']);
    }

    public function testBollingerOperandMapsToCalculatorBollKey(): void
    {
        $program = $this->program($this->yaml(<<<'YAML'
        entry:
          - action: open_long
            when:
              op: gt
              left:
                indicator: bollinger
                period: 20
                band: upper
              right:
                const: "0"
            qty: "1"
        YAML));

        $bars = $this->bars(range(1, 40));
        $snapshot = (new IndicatorSnapshotBuilder())->build($program, $bars);
        $expected = (new IndicatorCalculator())->compute($bars, ['bollinger' => ['period' => 20, 'std_dev' => 2.0]])['boll'];

        $this->assertArrayHasKey('20|2', $snapshot['now']['boll']);
        $this->assertSame($expected['upper'], $snapshot['now']['boll']['20|2']['upper']);
        $this->assertNotNull($snapshot['now']['boll']['20|2']['upper']);
    }

    public function testShiftedSnapshotForRsiBollAtrAndPrice(): void
    {
        // 覆盖 rsi/atr/boll/price 的 shifted 分支
        $program = $this->program(<<<'YAML'
        version: 1
        name: snap
        symbol: BTCUSDT
        interval: 1m
        direction: long
        filter:
          - action: noop
            when:
              op: true
        entry:
          - action: open_long
            when:
              op: and
              operands:
                - op: gt
                  left:
                    indicator: rsi
                    period: 14
                  right:
                    const: "0"
                - op: gt
                  left:
                    indicator: atr
                    period: 14
                  right:
                    const: "0"
                - op: gt
                  left:
                    indicator: bollinger
                    period: 20
                    band: lower
                  right:
                    const: "0"
                - op: gt
                  left:
                    price: close
                  right:
                    const: "0"
            qty: "1"
        exit:
          - action: close_long
            when:
              op: gt
              left:
                const: "0"
              right:
                const: "1"
            qty: "1"
        risk:
          cooldown_bars: 0
        YAML);

        $closes = array_map(static fn (int $i): float => 100.0 + $i, range(1, 60));
        $bars = $this->bars($closes);
        $shifted = array_slice($bars, 0, -1);

        $snapshot = (new IndicatorSnapshotBuilder())->build($program, $bars);
        $this->assertNotNull($snapshot['prev']);

        $prevRsi = (new IndicatorCalculator())->compute($shifted, ['rsi_period' => 14])['rsi'];
        $prevAtr = (new IndicatorCalculator())->compute($shifted, ['atr_period' => 14])['atr'];
        $prevBoll = (new IndicatorCalculator())->compute($shifted, ['bollinger' => ['period' => 20, 'std_dev' => 2.0]])['boll'];

        $this->assertSame($prevRsi, $snapshot['prev']['rsi'][14]);
        $this->assertSame($prevAtr, $snapshot['prev']['atr'][14]);
        $this->assertSame($prevBoll['lower'], $snapshot['prev']['boll']['20|2']['lower']);
        $this->assertSame($closes[58], $snapshot['prev']['price']['close']);
    }

    public function testInsufficientWindowYieldsNull(): void
    {
        $program = $this->program($this->yaml(<<<'YAML'
        entry:
          - action: open_long
            when:
              op: gt
              left:
                indicator: ma
                period: 99
              right:
                const: "0"
            qty: "1"
        YAML));

        $snapshot = (new IndicatorSnapshotBuilder())->build($program, $this->bars(range(1, 10)));

        $this->assertNull($snapshot['now']['ma'][99]['v']);
        $this->assertNull($snapshot['now']['ma'][99]['prev']);
        $this->assertNull($snapshot['prev']['ma'][99]['v']);
    }

    public function testRequiredWindowCapsAtWindowMax(): void
    {
        $program = $this->program($this->yaml(<<<'YAML'
        entry:
          - action: open_long
            when:
              op: gt
              left:
                indicator: ma
                period: 99
              right:
                const: "0"
            qty: "1"
        YAML));

        $this->assertSame(101, (new IndicatorSnapshotBuilder())->requiredWindow($program));
        $this->assertSame(30, (new IndicatorSnapshotBuilder(null, 30))->requiredWindow($program));
    }
}
