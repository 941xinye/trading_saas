<?php

declare(strict_types=1);

namespace Tests\Feature\Backtest;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;
use App\Domain\Backtest\Dto\BacktestRunRequest;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Exceptions\BacktestDataCoverageException;
use App\Domain\Backtest\Exceptions\BacktestStrategyNotImplemented;
use App\Domain\Backtest\Services\BacktestRunner;
use App\Domain\Backtest\Services\EquityCurveBuilder;
use App\Domain\Backtest\Services\MatchingEngine;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Strategy\StrategyRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @covers \App\Domain\Backtest\Services\BacktestRunner
 */
final class BacktestRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_runner_executes_complete_backtest(): void
    {
        $historical = new class implements HistoricalQuery {
            public function query(HistoricalQueryRequest $request): HistoricalQueryResult
            {
                $rows = [];
                $start = 1_700_000_000_000;
                for ($i = 0; $i < 100; $i++) {
                    $price = 100 + ($i % 10) - 5; // 95~104 波动
                    $rows[] = [
                        'id' => (string) ($i + 1),
                        'exchange_code' => 'binance',
                        'symbol' => 'BTCUSDT',
                        'interval' => '1m',
                        'open_time' => (new \DateTimeImmutable('@'.intdiv($start + $i * 60_000, 1000)))->format('Y-m-d H:i:s'),
                        'close_time' => (new \DateTimeImmutable('@'.intdiv($start + ($i + 1) * 60_000 - 1, 1000)))->format('Y-m-d H:i:s'),
                        'open' => (string) $price,
                        'high' => (string) ($price + 1),
                        'low' => (string) ($price - 1),
                        'close' => (string) $price,
                        'volume' => '1',
                        'quote_volume' => '1',
                        'trades_count' => '1',
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

        $request = new BacktestRunRequest(
            tenantId: 1,
            strategyCode: 'spot_grid',
            params: [
                'lower_price' => '90',
                'upper_price' => '110',
                'grid_count' => 5,
                'investment_amount' => '1000',
            ],
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: new \DateTimeImmutable('2023-11-14 22:13:20', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2023-11-14 22:53:20', new \DateTimeZone('UTC')),
            assumptions: MatchingAssumptions::fromConfig(capital: '1000'),
        );

        $result = $runner->run($request);

        static::assertSame(100, $result->barCount);
        static::assertNotEmpty($result->equityPoints);
        static::assertSame('250.00', $result->coveragePct);
    }

    public function test_runner_throws_on_insufficient_coverage(): void
    {
        $historical = new class implements HistoricalQuery {
            public function query(HistoricalQueryRequest $request): HistoricalQueryResult
            {
                return new HistoricalQueryResult([], null, HistoricalQueryResult::HOT);
            }
        };

        $runner = new BacktestRunner(
            $historical,
            new StrategyRegistry(),
            new MatchingEngine(),
            new EquityCurveBuilder('1000'),
        );

        $request = new BacktestRunRequest(
            tenantId: 1,
            strategyCode: 'spot_grid',
            params: ['lower_price' => '90', 'upper_price' => '110', 'grid_count' => 5, 'investment_amount' => '1000'],
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: new \DateTimeImmutable('2023-11-14 22:13:20', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2023-11-15 22:13:20', new \DateTimeZone('UTC')),
            assumptions: MatchingAssumptions::fromConfig(capital: '1000'),
        );

        $this->expectException(BacktestDataCoverageException::class);
        $runner->run($request);
    }

    public function test_runner_throws_on_unsupported_strategy(): void
    {
        $historical = new class implements HistoricalQuery {
            public function query(HistoricalQueryRequest $request): HistoricalQueryResult
            {
                return new HistoricalQueryResult([], null, HistoricalQueryResult::HOT);
            }
        };

        $runner = new BacktestRunner(
            $historical,
            new StrategyRegistry(),
            new MatchingEngine(),
            new EquityCurveBuilder('1000'),
        );

        $request = new BacktestRunRequest(
            tenantId: 1,
            strategyCode: 'unknown_strategy',
            params: [],
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: new \DateTimeImmutable('2023-11-14 22:13:20', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2023-11-14 22:14:20', new \DateTimeZone('UTC')),
            assumptions: MatchingAssumptions::fromConfig(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported strategy code');
        $runner->run($request);
    }

    public function test_runner_progress_callback(): void
    {
        $historical = new class implements HistoricalQuery {
            public function query(HistoricalQueryRequest $request): HistoricalQueryResult
            {
                $rows = [];
                $start = 1_700_000_000_000;
                for ($i = 0; $i < 1500; $i++) {
                    $rows[] = [
                        'id' => (string) ($i + 1),
                        'exchange_code' => 'binance',
                        'symbol' => 'BTCUSDT',
                        'interval' => '1m',
                        'open_time' => (new \DateTimeImmutable('@'.intdiv($start + $i * 60_000, 1000)))->format('Y-m-d H:i:s'),
                        'close_time' => (new \DateTimeImmutable('@'.intdiv($start + ($i + 1) * 60_000 - 1, 1000)))->format('Y-m-d H:i:s'),
                        'open' => '100', 'high' => '100', 'low' => '100', 'close' => '100',
                        'volume' => '1', 'quote_volume' => '1', 'trades_count' => '1',
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

        $calls = 0;
        $runner->onProgress(static function () use (&$calls): void {
            $calls++;
        });

        $request = new BacktestRunRequest(
            tenantId: 1,
            strategyCode: 'spot_dca',
            params: ['amount_per_order' => '100', 'interval_minutes' => 1, 'max_orders' => 100],
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: new \DateTimeImmutable('2023-11-14 22:13:20', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2023-11-14 22:28:20', new \DateTimeZone('UTC')),
            assumptions: MatchingAssumptions::fromConfig(capital: '1000'),
        );

        $result = $runner->run($request);

        // 1500 bar / 500 = 3 次回调
        static::assertSame(3, $calls);
        static::assertSame(1500, $result->barCount);
    }
}
