<?php

declare(strict_types=1);

namespace Tests\Unit\Strategy\Dsl;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyState;
use App\Domain\Strategy\Custom\DslStrategy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * DslStrategy 状态迁移测试（tasks T011 / FR-010 / constitution IV）。
 *
 * v2 状态 round-trip、v1→v2 迁移、高版本拒绝、恢复一次性标志（recovered）。
 */
class DslStateMigrationTest extends TestCase
{
    public function test_v2_state_round_trip_preserves_dsl_keys(): void
    {
        $script = $this->script();
        $strategy = $this->strategy($script);

        $strategy->onBar($this->bar(1_760_000_040_000, '110'));
        $open = $strategy->shouldOpen($this->ctx());
        static::assertNotNull($open);
        $strategy->attachClientOrderId('cid-1', (string) $open['side'], (string) $open['qty']);

        $state = $strategy->getState();

        static::assertSame(2, $state->schemaVersion);
        static::assertArrayHasKey('dsl', $state->positionSnapshot);
        $dsl = $state->positionSnapshot['dsl'];
        static::assertSame(sha1($script), $dsl['compiled_hash']);
        static::assertSame(1_760_000_040_000, $dsl['last_signal_bar']);
        static::assertSame(0, $dsl['cooldown_remaining']);
        static::assertSame('cid-1', $dsl['pending_signal']['client_order_id']);
        static::assertFalse($dsl['recovered']);

        $restored = $this->strategy($script);
        $restored->restoreState($state);

        $snapshot = $restored->getState()->positionSnapshot;
        static::assertSame('cid-1', $snapshot['dsl']['pending_signal']['client_order_id']);
        static::assertSame(1_760_000_040_000, $snapshot['dsl']['last_signal_bar']);
        static::assertSame(sha1($script), $snapshot['dsl']['compiled_hash']);
        static::assertFalse($snapshot['dsl']['recovered'], '新进程恢复后一次性标志复位（须重新核实）');
        static::assertSame(['cid-1'], $restored->pendingClientOrderIds());
    }

    public function test_recovered_flag_is_applied_once_and_clears_pending(): void
    {
        $script = $this->script();
        $strategy = $this->strategy($script);

        $strategy->restoreState(new StrategyState(
            schemaVersion: 2,
            params: [],
            positionSnapshot: [
                'dsl' => [
                    'compiled_hash' => sha1($script),
                    'last_signal_bar' => 1_760_000_040_000,
                    'cooldown_remaining' => 0,
                    'pending_signal' => [
                        'section' => 'entry',
                        'action' => 'open_long',
                        'kind' => 'open',
                        'side' => 'buy',
                        'qty' => '1',
                        'qty_pct' => null,
                        'rule_index' => 0,
                        'note' => null,
                        'client_order_id' => 'cid-rec',
                        'bar_time' => 1_760_000_040_000,
                    ],
                    'recovered' => false,
                ],
                'position' => ['qty' => '0', 'direction' => null, 'entry_price' => null],
            ],
            realizedPnl: '0',
            consecutiveLosses: 0,
            lastBarTime: null,
        ));

        // 崩溃恢复核实：filled → 入账 + 释放占位 + 一次性标志置位
        $strategy->applyRecoveredOrder($this->filled('cid-rec', 'buy', '1', '110'));

        $snapshot = $strategy->getState()->positionSnapshot;
        static::assertTrue($snapshot['dsl']['recovered']);
        static::assertNull($snapshot['dsl']['pending_signal']);
        static::assertSame('1', $snapshot['position']['qty']);
        static::assertSame('110', $snapshot['position']['entry_price']);
        static::assertSame([], $strategy->pendingClientOrderIds());
    }

    public function test_v1_snapshot_is_migrated_to_v2_without_data_loss(): void
    {
        $strategy = $this->strategy($this->script());

        $strategy->restoreState(new StrategyState(
            schemaVersion: 1,
            params: [],
            positionSnapshot: ['legacy_key' => 'kept'],
            realizedPnl: '12.5',
            consecutiveLosses: 2,
            lastBarTime: 1_760_000_040_000,
        ));

        $state = $strategy->getState();

        static::assertSame(2, $state->schemaVersion);
        static::assertSame('kept', $state->positionSnapshot['legacy_key'], 'v1 既有字段不静默丢弃');
        static::assertArrayHasKey('dsl', $state->positionSnapshot);
        static::assertSame('0', $state->positionSnapshot['position']['qty']);
        static::assertSame('12.5', $state->realizedPnl);
        static::assertSame(2, $state->consecutiveLosses);
        static::assertSame(1_760_000_040_000, $state->lastBarTime);
    }

    public function test_higher_schema_version_is_rejected(): void
    {
        $strategy = $this->strategy($this->script());

        $this->expectException(RuntimeException::class);

        $strategy->restoreState(new StrategyState(
            schemaVersion: 3,
            params: [],
            positionSnapshot: null,
            realizedPnl: '0',
            consecutiveLosses: 0,
            lastBarTime: null,
        ));
    }

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

    private function script(): string
    {
        return implode("\n", [
            'version: 1',
            'name: migration',
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
            '        const: "100"',
            '    qty: "1"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: lt',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "100"',
            '    qty: "1"',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);
    }
}
