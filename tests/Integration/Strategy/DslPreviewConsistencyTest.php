<?php

declare(strict_types=1);

namespace Tests\Integration\Strategy;

use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Custom\Dsl\Preview\HistoricalPreviewService;
use App\Domain\Strategy\Custom\DslParser;
use App\Domain\Strategy\Custom\DslStrategy;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * DSL 预览与实跑信号一致性（spec 006 WS6 T029 / FR-012 / SC-006）。
 *
 * 同一份 DSL 在同一组历史 K 线上：预览服务产出的信号序列 必须等于
 * 直接驱动 DslStrategy（onBar→shouldOpen/shouldClose→onFill）产出的信号序列。
 * 证明预览复用同一解释器与指标计算器，不存在「预览一套、实跑一套」的语义漂移。
 */
class DslPreviewConsistencyTest extends TestCase
{
    use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    public function test_preview_signals_equal_real_run_signals(): void
    {
        $closes = [115, 105, 115, 105, 115, 105, 120, 100, 110, 105];
        $bars = $this->bars($closes, 1_700_000_000_000);

        $script = $this->gridScript();

        // 1. 预览：桩 HistoricalQuery 返回同一批 bar（归档行格式）
        $historical = Mockery::mock(HistoricalQuery::class);
        $historical->shouldReceive('query')
            ->once()
            ->withArgs(function (HistoricalQueryRequest $req): bool {
                return $req->symbol === 'BTCUSDT' && $req->interval === '1m';
            })
            ->andReturn(new HistoricalQueryResult(
                rows: $this->toRows($bars),
                nextPageCursor: null,
                servedFrom: HistoricalQueryResult::HOT,
            ));

        $preview = new HistoricalPreviewService($historical, new DslParser());
        $result = $preview->preview(
            tenantId: 1,
            script: $script,
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: new \DateTimeImmutable('@1700000000', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('@1700001000', new \DateTimeZone('UTC')),
        );

        // 2. 实跑：直接驱动 DslStrategy
        $realSignals = $this->driveRealRun($script, $bars);

        static::assertSame(
            array_map(fn (array $s): string => $s['action'].'@'.$s['barTime'].'|'.$s['qty'], $result->signals),
            array_map(fn (array $s): string => $s['action'].'@'.$s['barTime'].'|'.$s['qty'], $realSignals),
            '预览信号序列（action@barTime|qty）与实跑 100% 一致',
        );
        static::assertSame(count($realSignals), $result->signalCount);
        static::assertSame(count($bars), $result->barCount, '预览覆盖全部 bar');
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    /**
     * 直接驱动 DslStrategy 跑完整 bar 序列，返回与预览同构的信号列表。
     *
     * @param ExchangeKline[] $bars
     *
     * @return list<array{barTime:int,action:string,qty:string,ruleIndex:int}>
     */
    private function driveRealRun(string $script, array $bars): array
    {
        $strategy = new DslStrategy(
            0,
            [
                'dsl_script' => $script,
                'symbol' => 'BTCUSDT',
                'tenant_id' => 1,
                'allocated_capital' => '10000',
            ],
            new StrategyContext(0, [], null, null, [], null),
        );

        $signals = [];
        foreach ($bars as $i => $bar) {
            $strategy->onBar($bar);

            $reflection = new \ReflectionClass($strategy);
            $pendingProp = $reflection->getProperty('pendingSignal');
            /** @var array<string,mixed>|null $meta */
            $meta = $pendingProp->getValue($strategy);

            $ctx = new StrategyContext(0, [], null, null, [], null);
            $decision = $strategy->shouldOpen($ctx) ?? $strategy->shouldClose($ctx);
            if ($decision === null) {
                continue;
            }

            $cid = 'real-'.$i;
            $strategy->attachClientOrderId($cid, (string) $decision['side'], (string) $decision['qty']);
            $strategy->onFill($this->filledOrder($cid, (string) $decision['side'], (string) $decision['qty'], $bar->close));

            $signals[] = [
                'barTime' => $bar->openTime,
                'action' => $meta['action'] ?? ($decision['side'] === 'buy' ? 'open_long' : 'close_long'),
                'qty' => (string) $decision['qty'],
                'ruleIndex' => (int) ($meta['rule_index'] ?? 0),
            ];
        }

        return $signals;
    }

    /**
     * @param ExchangeKline[] $bars
     *
     * @return list<array<string, string|null>>
     */
    private function toRows(array $bars): array
    {
        $rows = [];
        foreach ($bars as $i => $bar) {
            $rows[] = [
                'id' => (string) ($i + 1),
                'exchange_code' => $bar->exchangeCode,
                'symbol' => $bar->symbol,
                'interval' => $bar->interval,
                'open_time' => $this->ts($bar->openTime),
                'close_time' => $this->ts($bar->closeTime),
                'open' => $bar->open,
                'high' => $bar->high,
                'low' => $bar->low,
                'close' => $bar->close,
                'volume' => $bar->volume,
                'quote_volume' => $bar->quoteVolume,
                'trades_count' => (string) $bar->tradesCount,
                'created_at' => $this->ts($bar->openTime),
                'updated_at' => $this->ts($bar->openTime),
            ];
        }

        return $rows;
    }

    private function ts(int $ms): string
    {
        return (new \DateTimeImmutable('@'.intdiv($ms, 1000), new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
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

    private function filledOrder(string $cid, string $side, string $qty, string $price): ExchangeOrder
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
            placedAt: 0,
            filledAt: 0,
        );
    }

    private function gridScript(): string
    {
        return implode("\n", [
            'version: 1',
            'name: preview-grid',
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
}
