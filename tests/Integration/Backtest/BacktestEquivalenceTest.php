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
 * US2 等价性测试：回测与实跑语义一致性（FR-003 / SC-002）。
 *
 * ①同区间同参数回测，断言开平仓方向/bar 时点序列与 fixture 成交序列一致；
 * ②回测净值（含滑点手续费）vs 实跑毛收益（无费）方向可解释：回测净值 ≤ 实跑毛收益；
 * ③fixture 为合成数据（research R-009 降级路径），策略决策纯函数化保证等价性。
 *
 * @covers \App\Domain\Backtest\Services\BacktestRunner
 */
final class BacktestEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePath = 'tests/fixtures/backtest/testnet_spot_grid_sample.json';

    public function test_decision_sequence_matches_fixture(): void
    {
        $fixture = $this->loadFixture();
        $result = $this->runBacktest($fixture);

        static::assertNotEmpty($result->trades, '回测应产生成交');

        // 调试输出实际成交序列（失败时可见）
        $actualSummary = array_map(static fn ($t): string => sprintf(
            '#%d bar=%s side=%s qty=%s price=%s',
            $t->seq,
            date('Y-m-d H:i:s', (int) ($t->barOpenTime / 1000)),
            $t->side,
            $t->qty,
            $t->price,
        ), $result->trades);

        // 断言：方向序列与 bar 时点序列与 fixture 一致
        $fixtureTrades = $fixture['expected_trades'];
        static::assertCount(
            count($fixtureTrades),
            $result->trades,
            "成交笔数应与 fixture 一致\n实际:\n" . implode("\n", $actualSummary),
        );

        foreach ($fixtureTrades as $i => $expected) {
            $actual = $result->trades[$i];
            static::assertSame($expected['side'], $actual->side, "第 {$i} 笔方向不一致");
            static::assertSame(
                (new \DateTimeImmutable($expected['bar_open_time'], new \DateTimeZone('UTC')))->getTimestamp() * 1000,
                $actual->barOpenTime,
                "第 {$i} 笔 bar 时点不一致",
            );
        }
    }

    public function test_backtest_equity_leq_live_gross(): void
    {
        $fixture = $this->loadFixture();
        $result = $this->runBacktest($fixture);

        // 实跑毛收益 = 无费无滑点净值（用 close 原价计算）
        $liveGross = $this->liveGrossEquity($fixture);
        $backtestFinal = (float) ($result->equityPoints[count($result->equityPoints) - 1]->equity ?? '0');

        static::assertGreaterThan(0, $backtestFinal);
        static::assertLessThanOrEqual(
            $liveGross + 0.01, // 浮点容差
            $backtestFinal,
            '回测净值（含费）应 ≤ 实跑毛收益',
        );
    }

    public function test_equity_curve_monotonic_with_fees(): void
    {
        $fixture = $this->loadFixture();
        $result = $this->runBacktest($fixture);

        static::assertSame(count($fixture['bars']), count($result->equityPoints), '净值点应与 bar 一一对应');

        // 含费回测净值最终应低于无费毛收益（除非零成交）
        if ($result->trades !== []) {
            $liveGross = $this->liveGrossEquity($fixture);
            $backtestFinal = (float) $result->equityPoints[count($result->equityPoints) - 1]->equity;
            static::assertLessThanOrEqual($liveGross, $backtestFinal);
        }
    }

    /**
     * 实跑毛收益：按 fixture bars 的 close 原价、无费无滑点重算净值。
     */
    private function liveGrossEquity(array $fixture): float
    {
        $trades = $fixture['expected_trades'];
        $initial = 1000.0;
        $position = 0.0;
        $cash = $initial;

        foreach ($trades as $trade) {
            $qty = (float) $trade['qty'];
            $price = (float) $trade['price'];
            if ($trade['side'] === 'buy') {
                $cost = $qty * $price;
                if ($cost > $cash) {
                    $qty = $cash / $price;
                    $cost = $cash;
                }
                $cash -= $cost;
                $position += $qty;
            } else {
                $qty = min($qty, $position);
                $cash += $qty * $price;
                $position -= $qty;
            }
        }

        // 最终净值 = 现金 + 持仓按最后 close 估值
        $lastClose = (float) $fixture['bars'][count($fixture['bars']) - 1]['close'];

        return $cash + $position * $lastClose;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(): array
    {
        $path = base_path($this->fixturePath);
        static::assertFileExists($path);

        $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        static::assertIsArray($fixture);

        return $fixture;
    }

    private function runBacktest(array $fixture): \App\Domain\Backtest\Dto\BacktestRunResult
    {
        $historical = new class($fixture['bars']) implements HistoricalQuery {
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

        $assumptions = MatchingAssumptions::fromConfig(capital: '1000', feeRate: '0.001', slippageBps: 5);

        $runner = new BacktestRunner(
            $historical,
            new StrategyRegistry(),
            new MatchingEngine(),
            new EquityCurveBuilder('1000'),
        );

        $fromUtc = new \DateTimeImmutable($fixture['bars'][0]['open_time'], new \DateTimeZone('UTC'));
        $toUtc = (new \DateTimeImmutable($fixture['bars'][count($fixture['bars']) - 1]['close_time'], new \DateTimeZone('UTC')))->modify('+1 second');

        return $runner->run(new BacktestRunRequest(
            tenantId: 1,
            strategyCode: $fixture['strategy'],
            params: $fixture['params'],
            symbol: $fixture['symbol'],
            interval: $fixture['interval'],
            fromUtc: $fromUtc,
            toUtc: $toUtc,
            assumptions: $assumptions,
        ));
    }
}
