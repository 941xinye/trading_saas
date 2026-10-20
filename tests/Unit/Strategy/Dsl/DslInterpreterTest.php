<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Dsl;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Strategy\Custom\Dsl\DslEvalContext;
use App\Domain\Strategy\Custom\Dsl\DslEvalResult;
use App\Domain\Strategy\Custom\Dsl\DslExecutionException;
use App\Domain\Strategy\Custom\Dsl\DslProgram;
use App\Domain\Strategy\Custom\Dsl\WhitelistInterpreter;
use App\Domain\Strategy\Custom\DslParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 沙箱白名单解释器测试（tasks T009 / FR-003/004/008/017 / SC-009）。
 */
class DslInterpreterTest extends TestCase
{
    private function parse(string $yaml): DslProgram
    {
        return (new DslParser())->parse($yaml);
    }

    /**
     * @param array<string,mixed>      $now
     * @param array<string,mixed>|null $prev
     */
    private function ctx(array $now = [], ?array $prev = null, ?ExchangeKline $bar = null): DslEvalContext
    {
        return new DslEvalContext(
            tenantId: 1,
            instanceId: 1,
            trigger: 'on_bar',
            bar: $bar,
            ticker: null,
            recentBars: [],
            position: null,
            indicatorSnapshot: ['now' => $now, 'prev' => $prev],
            allocatedCapital: '1000',
        );
    }

    private function bar(string $close): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '1m',
            openTime: 1_759_999_940_000,
            closeTime: 1_760_000_000_000,
            open: $close,
            high: $close,
            low: $close,
            close: $close,
            volume: '1',
            quoteVolume: '1',
            tradesCount: 1,
        );
    }

    private function comparisonProgram(string $op, string $left, string $right): DslProgram
    {
        return $this->parse(<<<YAML
        version: 1
        name: cmp
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
              op: {$op}
              left:
                const: "{$left}"
              right:
                const: "{$right}"
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
    }

    private function entryOf(DslEvalResult $result): ?\App\Domain\Strategy\Custom\Dsl\DslSignal
    {
        foreach ($result->signals as $signal) {
            if ($signal->section === 'entry') {
                return $signal;
            }
        }

        return null;
    }

    #[DataProvider('comparisonCases')]
    public function testComparisonTruthTable(string $op, string $left, string $right, bool $expected): void
    {
        $result = (new WhitelistInterpreter())->evaluate($this->comparisonProgram($op, $left, $right), $this->ctx());

        $this->assertSame($expected, $this->entryOf($result) !== null);
    }

    /**
     * @return array<string,array{string,string,string,bool}>
     */
    public static function comparisonCases(): array
    {
        return [
            'gt true' => ['gt', '5', '3', true],
            'gt false' => ['gt', '3', '5', false],
            'gt equal false' => ['gt', '3', '3', false],
            'gte equal true' => ['gte', '3', '3', true],
            'lt true' => ['lt', '3', '5', true],
            'lt equal false' => ['lt', '5', '5', false],
            'lte equal true' => ['lte', '5', '5', true],
            'eq true' => ['eq', '5', '5', true],
            'eq false' => ['eq', '5', '4', false],
            'ne true' => ['ne', '5', '4', true],
            'ne false' => ['ne', '5', '5', false],
        ];
    }

    public function testAlwaysConditionEmitsSignal(): void
    {
        $program = $this->parse(<<<'YAML'
        version: 1
        name: always
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
              op: true
            qty_pct: "0.3"
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

        $result = (new WhitelistInterpreter())->evaluate($program, $this->ctx());

        $entry = $this->entryOf($result);
        $this->assertNotNull($entry);
        $this->assertSame('open_long', $entry->action);
        $this->assertSame('0.3', $entry->qtyPct);
        $this->assertNull($entry->qty);
        $this->assertSame(0, $entry->ruleIndex);
        $this->assertGreaterThan(0, $result->costSteps);
    }

    public function testLogicalAndOrNot(): void
    {
        $program = $this->parse(<<<'YAML'
        version: 1
        name: logical
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
                - op: or
                  operands:
                    - op: gt
                      left:
                        const: "1"
                      right:
                        const: "2"
                    - op: lt
                      left:
                        const: "1"
                      right:
                        const: "2"
                - op: not
                  operand:
                    op: gt
                    left:
                      const: "1"
                    right:
                      const: "2"
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

        $result = (new WhitelistInterpreter())->evaluate($program, $this->ctx());

        // or: false || true = true；not: !(1>2) = true；and → true
        $this->assertNotNull($this->entryOf($result));
    }

    public function testCrossAboveWithBuiltinMaPrev(): void
    {
        $now = [
            'price' => ['open' => null, 'high' => null, 'low' => null, 'close' => 10.0, 'last' => 10.0],
            'ma' => [7 => ['v' => 10.0, 'prev' => 1.0], 25 => ['v' => 5.0, 'prev' => 9.0]],
            'macd' => ['dif' => null, 'dea' => null, 'hist' => null, 'hist_prev' => null],
            'rsi' => [],
            'boll' => [],
            'atr' => [],
        ];

        $program = $this->parse(<<<'YAML'
        version: 1
        name: cross
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
              op: cross_above
              left:
                indicator: ma
                period: 7
              right:
                indicator: ma
                period: 25
            qty: "1"
        exit:
          - action: close_long
            when:
              op: cross_below
              left:
                indicator: ma
                period: 7
              right:
                indicator: ma
                period: 25
            qty: "1"
        risk:
          cooldown_bars: 0
        YAML);

        $result = (new WhitelistInterpreter())->evaluate($program, $this->ctx($now));

        $this->assertNotNull($this->entryOf($result)); // 1 <= 9 且 10 > 5 → 金叉
    }

    public function testCrossAboveWithShiftedRsiSnapshot(): void
    {
        $now = [
            'price' => [],
            'ma' => [],
            'macd' => ['dif' => null, 'dea' => null, 'hist' => null, 'hist_prev' => null],
            'rsi' => [14 => 60.0],
            'boll' => [],
            'atr' => [],
        ];
        $prev = [
            'price' => [],
            'ma' => [],
            'macd' => ['dif' => null, 'dea' => null, 'hist' => null, 'hist_prev' => null],
            'rsi' => [14 => 40.0],
            'boll' => [],
            'atr' => [],
        ];

        $program = $this->parse(<<<'YAML'
        version: 1
        name: rsi-cross
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
              op: cross_above
              left:
                indicator: rsi
                period: 14
              right:
                const: "50"
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

        $result = (new WhitelistInterpreter())->evaluate($program, $this->ctx($now, $prev));

        $this->assertNotNull($this->entryOf($result)); // 40 <= 50 且 60 > 50
    }

    public function testNullOperandYieldsFalse(): void
    {
        $program = $this->parse(<<<'YAML'
        version: 1
        name: price-filter
        symbol: BTCUSDT
        interval: 1m
        direction: long
        filter:
          - action: noop
            when:
              op: gt
              left:
                price: close
              right:
                const: "0"
        entry:
          - action: open_long
            when:
              op: true
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

        // bar 缺失且快照为空 → price/close 为 null → 闸门条件 false → 整 bar 无信号
        $result = (new WhitelistInterpreter())->evaluate($program, $this->ctx());
        $this->assertSame([], $result->signals);

        // 有 bar 时可触发
        $result = (new WhitelistInterpreter())->evaluate($program, $this->ctx(bar: $this->bar('100')));
        $this->assertNotNull($this->entryOf($result));
    }

    public function testFilterGateBlocksWhenFilterFalse(): void
    {
        $program = $this->parse(<<<'YAML'
        version: 1
        name: gate
        symbol: BTCUSDT
        interval: 1m
        direction: long
        filter:
          - action: noop
            when:
              op: gt
              left:
                const: "0"
              right:
                const: "1"
        entry:
          - action: open_long
            when:
              op: true
            qty: "1"
        exit:
          - action: close_long
            when:
              op: true
            qty: "1"
        risk:
          cooldown_bars: 0
        YAML);

        $result = (new WhitelistInterpreter())->evaluate($program, $this->ctx());

        $this->assertSame([], $result->signals);
    }

    public function testStepBudgetExceededThrows(): void
    {
        $program = $this->parse(<<<'YAML'
        version: 1
        name: budget
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
                    const: "1"
                  right:
                    const: "0"
                - op: gt
                  left:
                    const: "1"
                  right:
                    const: "0"
                - op: gt
                  left:
                    const: "1"
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

        $interpreter = new WhitelistInterpreter(stepBudget: 2);

        try {
            $interpreter->evaluate($program, $this->ctx());
            $this->fail('应当抛出 DslExecutionException');
        } catch (DslExecutionException $e) {
            $this->assertSame(DslExecutionException::KIND_STEP, $e->limitKind);
            $this->assertSame(3, $e->costSteps);
            $this->assertStringContainsString('步数超过上限', $e->getMessage());
        }
    }
}
