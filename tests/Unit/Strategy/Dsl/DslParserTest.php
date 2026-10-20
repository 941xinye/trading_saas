<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Dsl;

use App\Domain\Strategy\Custom\Dsl\DslProgram;
use App\Domain\Strategy\Custom\Dsl\DslValidationException;
use App\Domain\Strategy\Custom\DslParser;
use PHPUnit\Framework\TestCase;

/**
 * DSL 解析门面编排测试（tasks T006 / FR-002/005）——contracts §10 worked example 全链路编译。
 */
class DslParserTest extends TestCase
{
    private const WORKED_EXAMPLE = <<<'YAML'
    version: 1
    name: "MA 金叉开多"
    symbol: "BTCUSDT"
    interval: "1m"
    direction: long
    trigger: on_bar

    filter:
      # 前置闸门：收盘价 > 0（恒真示例，可换成趋势过滤）
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
          op: cross_above
          left:
            indicator: ma
            period: 7
          right:
            indicator: ma
            period: 25
        qty_pct: "0.30"
        note: "短均线上穿长均线开多"

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
        qty_pct: "1.0"
        note: "死叉平多"

    risk:
      max_position_pct: "0.50"
      cooldown_bars: 1
      stop_loss_pct: "0.02"
      take_profit_pct: "0.05"
    YAML;

    public function testCompilesWorkedExample(): void
    {
        $script = self::WORKED_EXAMPLE;
        $program = (new DslParser())->parse($script);

        $this->assertInstanceOf(DslProgram::class, $program);
        $this->assertSame(1, $program->version);
        $this->assertSame(sha1($script), $program->sourceHash);
        $this->assertSame('MA 金叉开多', $program->meta->name);
        $this->assertSame('on_bar', $program->meta->trigger);
        $this->assertSame('long', $program->meta->direction);
        $this->assertCount(1, $program->filters);
        $this->assertCount(1, $program->entries);
        $this->assertCount(1, $program->exits);

        $entry = $program->entries[0];
        $this->assertSame('open_long', $entry->action);
        $this->assertSame('cross_above', $entry->condition->op);
        $this->assertSame('ma', $entry->condition->left->indicator);
        $this->assertSame(7, $entry->condition->left->period);
        $this->assertSame(25, $entry->condition->right->period);
        $this->assertSame('0.30', $entry->qtyPct);
        $this->assertNull($entry->qty);

        $this->assertSame('0.50', $program->risk->maxPositionPct);
        $this->assertSame(1, $program->risk->cooldownBars);
    }

    public function testInvalidScriptCarriesLineDiagnostics(): void
    {
        $script = <<<'YAML'
        version: 1
        name: x
        symbol: BTCUSDT
        interval: 1m
        direction: long
        filter:
          - action: noop
        entry:
          - action: open_long
            when:
              op: gtt
              left:
                price: close
              right:
                const: "1"
            qty: "1"
        exit:
          - action: close_long
            when:
              op: true
            qty: "1"

        YAML;

        try {
            (new DslParser())->parse($script);
            $this->fail('应当抛出 DslValidationException');
        } catch (DslValidationException $e) {
            $fields = array_map(static fn ($d): string => $d->field, $e->diagnostics());
            $this->assertContains('entry.0.when.op', $fields, $e->getMessage());

            foreach ($e->diagnostics() as $d) {
                if ($d->field === 'entry.0.when.op') {
                    $this->assertSame(11, $d->line);

                    return;
                }
            }
            $this->fail('未找到 entry.0.when.op 诊断');
        }
    }

    public function testRejectsScriptOverByteLimit(): void
    {
        $script = 'version: 1'.str_repeat(' ', 9000);

        try {
            (new DslParser())->parse($script);
            $this->fail('应当抛出');
        } catch (DslValidationException $e) {
            $this->assertStringContainsString('字节数', $e->getMessage());
        }
    }

    public function testRejectsScriptOverLineLimit(): void
    {
        $script = "version: 1\n".str_repeat("# comment\n", 300);

        try {
            (new DslParser())->parse($script);
            $this->fail('应当抛出');
        } catch (DslValidationException $e) {
            $this->assertStringContainsString('行数', $e->getMessage());
        }
    }
}
