<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Dsl;

use App\Domain\Strategy\Custom\Dsl\DslCompiler;
use App\Domain\Strategy\Custom\Dsl\DslProgram;
use App\Domain\Strategy\Custom\Dsl\DslValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DSL 结构校验 + AST 构建测试（tasks T005 / FR-002/005 + spec Edge 上限）。
 */
class DslCompilerTest extends TestCase
{
    private function compiler(): DslCompiler
    {
        return new DslCompiler(DslCompiler::defaultLimits());
    }

    /**
     * @return array<string,mixed>
     */
    private function base(): array
    {
        return [
            'version' => '1',
            'name' => 'MA 金叉开多',
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'direction' => 'long',
            'trigger' => 'on_bar',
            'filter' => [
                ['action' => 'noop', 'when' => ['op' => 'gt', 'left' => ['price' => 'close'], 'right' => ['const' => '0']]],
            ],
            'entry' => [
                [
                    'action' => 'open_long',
                    'when' => ['op' => 'cross_above', 'left' => ['indicator' => 'ma', 'period' => '7'], 'right' => ['indicator' => 'ma', 'period' => '25']],
                    'qty_pct' => '0.30',
                    'note' => '短均线上穿长均线开多',
                ],
            ],
            'exit' => [
                [
                    'action' => 'close_long',
                    'when' => ['op' => 'cross_below', 'left' => ['indicator' => 'ma', 'period' => '7'], 'right' => ['indicator' => 'ma', 'period' => '25']],
                    'qty_pct' => '1.0',
                ],
            ],
            'risk' => [
                'max_position_pct' => '0.50',
                'cooldown_bars' => '1',
                'stop_loss_pct' => '0.02',
                'take_profit_pct' => '0.05',
            ],
        ];
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $mutate
     */
    private function compileMutated(callable $mutate): void
    {
        $this->compiler()->compile($mutate($this->base()), 'hash');
    }

    public function testCompilesValidScriptToProgram(): void
    {
        $program = $this->compiler()->compile($this->base(), 'abc123');

        $this->assertInstanceOf(DslProgram::class, $program);
        $this->assertSame(1, $program->version);
        $this->assertSame('abc123', $program->sourceHash);
        $this->assertSame('on_bar', $program->meta->trigger);
        $this->assertSame('long', $program->meta->direction);
        $this->assertCount(1, $program->filters);
        $this->assertCount(1, $program->entries);
        $this->assertCount(1, $program->exits);
        $this->assertSame('open_long', $program->entries[0]->action);
        $this->assertSame('cross_above', $program->entries[0]->condition->op);
        $this->assertSame('0.30', $program->entries[0]->qtyPct);
        $this->assertNull($program->entries[0]->qty);
        $this->assertSame('ma', $program->entries[0]->condition->left->indicator);
        $this->assertSame(7, $program->entries[0]->condition->left->period);
        $this->assertSame('0.50', $program->risk->maxPositionPct);
        $this->assertSame(1, $program->risk->cooldownBars);
        $this->assertSame('0.02', $program->risk->stopLossPct);
    }

    public function testNoopWithoutWhenBecomesAlways(): void
    {
        $raw = $this->base();
        $raw['filter'] = [['action' => 'noop']];

        $program = $this->compiler()->compile($raw, 'h');

        $this->assertSame('always', $program->filters[0]->condition->type);
    }

    #[DataProvider('invalidRaw')]
    public function testRejectsInvalidStructure(callable $mutate, string $expectField): void
    {
        try {
            $this->compileMutated($mutate);
            $this->fail('应当抛出 DslValidationException');
        } catch (DslValidationException $e) {
            $fields = array_map(static fn ($d): string => $d->field, $e->diagnostics());
            $this->assertContains($expectField, $fields, $e->getMessage());
        }
    }

    /**
     * @return array<string,array{callable,string}>
     */
    public static function invalidRaw(): array
    {
        $with = static function (mixed $patch, string $key): \Closure {
            return static function (array $raw) use ($patch, $key): array {
                $raw[$key] = $patch;

                return $raw;
            };
        };

        return [
            'unknown-top-key' => [$with('x', 'extra_top'), 'extra_top'],
            'missing-section' => [static function (array $raw): array { unset($raw['risk']); return $raw; }, 'risk'],
            'missing-version' => [static function (array $raw): array { unset($raw['version']); return $raw; }, 'version'],
            'version-too-high' => [$with('2', 'version'), 'version'],
            'empty-name' => [$with('', 'name'), 'name'],
            'bad-direction' => [$with('up', 'direction'), 'direction'],
            'bad-trigger' => [$with('on_hour', 'trigger'), 'trigger'],
            'empty-entry' => [$with([], 'entry'), 'entry'],
            'unknown-action' => [$with([
                ['action' => 'open_everything', 'when' => ['op' => 'true'], 'qty' => '1'],
            ], 'entry'), 'entry.0.action'],
            'unknown-op' => [$with([
                ['action' => 'open_long', 'when' => ['op' => 'gtt', 'left' => ['price' => 'close'], 'right' => ['const' => '1']], 'qty' => '1'],
            ], 'entry'), 'entry.0.when.op'],
            'unknown-indicator' => [$with([
                ['action' => 'open_long', 'when' => ['op' => 'gt', 'left' => ['indicator' => 'ema', 'period' => '7'], 'right' => ['const' => '1']], 'qty' => '1'],
            ], 'entry'), 'entry.0.when.left.indicator'],
            'period-zero' => [$with([
                ['action' => 'open_long', 'when' => ['op' => 'gt', 'left' => ['indicator' => 'rsi', 'period' => '0'], 'right' => ['const' => '1']], 'qty' => '1'],
            ], 'entry'), 'entry.0.when.left.period'],
            'qty-not-positive' => [$with([
                ['action' => 'open_long', 'when' => ['op' => 'true'], 'qty' => '0'],
            ], 'entry'), 'entry.0.qty'],
            'qty-pct-out-of-range' => [$with([
                ['action' => 'open_long', 'when' => ['op' => 'true'], 'qty_pct' => '1.5'],
            ], 'entry'), 'entry.0.qty_pct'],
            'both-qty-and-pct' => [$with([
                ['action' => 'open_long', 'when' => ['op' => 'true'], 'qty' => '1', 'qty_pct' => '0.5'],
            ], 'entry'), 'entry.0'],
            'trading-action-without-qty' => [$with([
                ['action' => 'close_long', 'when' => ['op' => 'true']],
            ], 'exit'), 'exit.0'],
            'unknown-rule-key' => [$with([
                ['action' => 'noop', 'when' => ['op' => 'true'], 'extra' => 'x'],
            ], 'filter'), 'filter.0.extra'],
            'unknown-risk-key' => [$with(['leverage' => '5'], 'risk'), 'risk.leverage'],
            'unknown-condition-key' => [$with([
                ['action' => 'open_long', 'when' => ['op' => 'true', 'foo' => 'bar'], 'qty' => '1'],
            ], 'entry'), 'entry.0.when.foo'],
            'unknown-operand-key' => [$with([
                ['action' => 'open_long', 'when' => ['op' => 'gt', 'left' => ['indicator' => 'ma', 'period' => '7', 'band' => 'upper'], 'right' => ['const' => '1']], 'qty' => '1'],
            ], 'entry'), 'entry.0.when.left.band'],
        ];
    }

    public function testRejectsRuleCountOverLimit(): void
    {
        $raw = $this->base();
        $raw['entry'] = [];
        for ($i = 0; $i < 21; $i++) {
            $raw['entry'][] = ['action' => 'open_long', 'when' => ['op' => 'true'], 'qty' => '1'];
        }

        $this->expectException(DslValidationException::class);
        $this->compiler()->compile($raw, 'h');
    }

    public function testRejectsIndicatorCountOverLimit(): void
    {
        $raw = $this->base();
        $operands = [];
        for ($i = 1; $i <= 9; $i++) {
            $operands[] = ['op' => 'gt', 'left' => ['indicator' => 'ma', 'period' => (string) $i], 'right' => ['const' => '1']];
        }
        $raw['entry'] = [['action' => 'open_long', 'when' => ['op' => 'and', 'operands' => $operands], 'qty' => '1']];

        $this->expectException(DslValidationException::class);
        $this->compiler()->compile($raw, 'h');
    }

    public function testRejectsDepthOverLimit(): void
    {
        $cond = ['op' => 'gt', 'left' => ['price' => 'close'], 'right' => ['const' => '1']];
        for ($i = 0; $i < 9; $i++) {
            $cond = ['op' => 'not', 'operand' => $cond];
        }

        $raw = $this->base();
        $raw['entry'] = [['action' => 'open_long', 'when' => $cond, 'qty' => '1']];

        $this->expectException(DslValidationException::class);
        $this->compiler()->compile($raw, 'h');
    }

    public function testDiagnosticsCarryLineNumberWhenLineMapProvided(): void
    {
        $raw = $this->base();
        $raw['entry'][0]['when']['op'] = 'gtt';

        try {
            $this->compiler()->compile($raw, 'h', ['entry.0.when.op' => 12]);
            $this->fail('应当抛出');
        } catch (DslValidationException $e) {
            foreach ($e->diagnostics() as $d) {
                if ($d->field === 'entry.0.when.op') {
                    $this->assertSame(12, $d->line);

                    return;
                }
            }
            $this->fail('未找到 entry.0.when.op 诊断');
        }
    }
}
