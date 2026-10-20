<?php

declare(strict_types=1);

namespace Tests\Integration\Backtest;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;
use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Services\BacktestRunner;
use App\Domain\Backtest\Services\EquityCurveBuilder;
use App\Domain\Backtest\Services\MatchingEngine;
use App\Domain\Strategy\StrategyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US2 四策略覆盖：spot_dca / futures_grid / custom_dsl 各一份 fixture 回测跑通。
 *
 * @covers \App\Domain\Backtest\Services\BacktestRunner
 */
final class BacktestRunnerFourStrategiesTest extends TestCase
{
    use RefreshDatabase;

    /** 10 分钟下降+回升 K 线（9 bar → 10m 理论 = 90% 覆盖率） */
    private array $bars;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bars = $this->makeBars(
            [
                100, 98, 96, 94, 92, 92, 95, 98, 100, 102,
            ],
            '2023-11-14 22:00:00',
        );
    }

    // ------------------------------------------------------------------
    // spot_dca
    // ------------------------------------------------------------------

    public function test_spot_dca_backtest_completes(): void
    {
        $result = $this->runBacktest(
            'spot_dca',
            [
                'amount_per_order' => '100',
                'interval_minutes' => 1,
                'max_orders' => 3,
            ],
        );

        static::assertSame(10, $result->barCount);
        static::assertGreaterThanOrEqual(1, count($result->trades), 'DCA 至少应有 1 笔定投');
        static::assertSame('buy', $result->trades[0]->side, 'DCA 第一笔应为买入');
        static::assertNotEmpty($result->equityPoints);
    }

    // ------------------------------------------------------------------
    // futures_grid
    // ------------------------------------------------------------------

    public function test_futures_grid_backtest_completes(): void
    {
        $result = $this->runBacktest(
            'futures_grid',
            [
                'lower_price' => '90',
                'upper_price' => '110',
                'grid_count' => 5,
                'leverage' => 5,
                'margin' => '1000',
                'symbol' => 'BTCUSDT',
            ],
        );

        static::assertSame(10, $result->barCount);
        static::assertGreaterThanOrEqual(2, count($result->trades));
        static::assertNotEmpty($result->equityPoints);

        // 资产说明：futures 回测未含资金费率影响
        // （见 spec-008 R-010，资金费率由合约层按月收取，回测引擎按 bar 级决策忽略该因素）
    }

    // ------------------------------------------------------------------
    // custom_dsl
    // ------------------------------------------------------------------

    public function test_custom_dsl_backtest_completes(): void
    {
        // DSL YAML 格式：close<95 开多，close>=99 平多
        $script = implode("\n", [
            'version: 1',
            'name: bt-dsl',
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
            '        const: "95"',
            '    qty_pct: "0.5"',
            'exit:',
            '  - action: close_long',
            '    when:',
            '      op: gte',
            '      left:',
            '        price: close',
            '      right:',
            '        const: "99"',
            '    qty_pct: "1.0"',
            'risk:',
            '  cooldown_bars: 0',
            '',
        ]);

        $result = $this->runBacktest(
            'custom_dsl',
            [
                'dsl_script' => $script,
                'symbol' => 'BTCUSDT',
                'tenant_id' => 1,
                'allocated_capital' => '1000',
            ],
        );

        static::assertSame(10, $result->barCount);
        static::assertNotEmpty($result->equityPoints);

        // 断言：close<95 的 bar 数量为 3 (94,92,92) → 至少 1 笔 buy
        $buyTrades = array_filter(
            $result->trades,
            static fn ($t): bool => $t->side === 'buy',
        );
        static::assertGreaterThanOrEqual(1, count($buyTrades), 'DSL buy_signal 应触发买入');
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function runBacktest(string $code, array $params): \App\Domain\Backtest\Dto\BacktestRunResult
    {
        $historical = new class($this->bars) implements HistoricalQuery {
            /** @param list<array<string, string>> $bars */
            public function __construct(private readonly array $bars)
            {
            }

            public function query(HistoricalQueryRequest $request): HistoricalQueryResult
            {
                $rows = [];
                foreach ($this->bars as $i => $bar) {
                    $rows[] = [
                        'id' => (string) ($i + 1),
                        'exchange_code' => 'binance',
                        'symbol' => $request->symbol ?? 'BTCUSDT',
                        'interval' => $request->interval ?? '1m',
                        'open_time' => $bar['open_time'],
                        'close_time' => $bar['close_time'],
                        'open' => $bar['open'],
                        'high' => $bar['high'],
                        'low' => $bar['low'],
                        'close' => $bar['close'],
                        'volume' => $bar['volume'],
                        'quote_volume' => $bar['quote_volume'],
                        'trades_count' => $bar['trades_count'],
                    ];
                }

                return new HistoricalQueryResult($rows, null, HistoricalQueryResult::HOT);
            }
        };

        $runner = new BacktestRunner(
            $historical,
            new StrategyRegistry(),
            new MatchingEngine(),
            new EquityCurveBuilder('1000'),
        );

        $fromUtc = new \DateTimeImmutable($this->bars[0]['open_time'], new \DateTimeZone('UTC'));
        $toUtc = (new \DateTimeImmutable($this->bars[count($this->bars) - 1]['close_time'], new \DateTimeZone('UTC')))->modify('+1 second');

        return $runner->run(new BacktestRunRequest(
            tenantId: 1,
            strategyCode: $code,
            params: $params,
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: $fromUtc,
            toUtc: $toUtc,
            assumptions: MatchingAssumptions::fromConfig(capital: '1000'),
        ));
    }

    /**
     * @param list<int|float> $closes
     * @return list<array<string, string>>
     */
    private function makeBars(array $closes, string $startIso): array
    {
        $start = new \DateTimeImmutable($startIso, new \DateTimeZone('UTC'));
        $bars = [];

        foreach ($closes as $i => $close) {
            $openTime = $start->modify("+{$i} minute");
            $closeTime = $openTime->modify('+59 seconds');
            $c = (string) $close;
            $bars[] = [
                'open_time' => $openTime->format('Y-m-d H:i:s'),
                'close_time' => $closeTime->format('Y-m-d H:i:s'),
                'open' => $c,
                'high' => (string) ($close + 1),
                'low' => (string) ($close - 1),
                'close' => $c,
                'volume' => '1',
                'quote_volume' => '1',
                'trades_count' => '1',
            ];
        }

        return $bars;
    }
}
