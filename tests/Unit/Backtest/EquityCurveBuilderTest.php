<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use App\Domain\Backtest\Services\EquityCurveBuilder;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\Contracts\StrategyState;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Backtest\Services\EquityCurveBuilder
 */
final class EquityCurveBuilderTest extends TestCase
{
    public function test_no_position_equity_equals_initial_capital(): void
    {
        $builder = new EquityCurveBuilder('1000');
        $strategy = $this->mockStrategy(null, '0');

        $bar = $this->bar('100');
        $point = $builder->mark($strategy, $bar);

        static::assertSame(0, bccomp($point->equity, '1000', 8));
        static::assertSame($bar->openTime, $point->barTime);
    }

    public function test_long_position_unrealized_pnl(): void
    {
        $builder = new EquityCurveBuilder('1000');
        // position: qty=2, entry=90, direction=long
        $strategy = $this->mockStrategy(['position' => [
            'qty' => '2',
            'direction' => 'long',
            'entry_price' => '90',
        ]], '0');

        // close=100 → unrealized = 2 * (100-90) = 20
        $builder->mark($strategy, $this->bar('100'));
        $points = $builder->points();

        static::assertSame(0, bccomp($points[0]->equity, '1020', 8));
    }

    public function test_short_position_unrealized_pnl(): void
    {
        $builder = new EquityCurveBuilder('1000');
        // position: qty=2, entry=100, direction=short
        $strategy = $this->mockStrategy(['position' => [
            'qty' => '2',
            'direction' => 'short',
            'entry_price' => '100',
        ]], '0');

        // close=90 → unrealized = 2 * (100-90) = 20
        $builder->mark($strategy, $this->bar('90'));

        static::assertSame(0, bccomp($builder->points()[0]->equity, '1020', 8));
    }

    public function test_realized_pnl_plus_position(): void
    {
        $builder = new EquityCurveBuilder('1000');
        $strategy = $this->mockStrategy(['position' => [
            'qty' => '1',
            'direction' => 'long',
            'entry_price' => '50',
        ]], '30'); // realizedPnl=30

        // close=100 → unrealized = 1*(100-50)=50; total = 1000+30+50=1080
        $builder->mark($strategy, $this->bar('100'));

        static::assertSame(0, bccomp($builder->points()[0]->equity, '1080', 8));
    }

    public function test_spot_grid_grids_weighted_average(): void
    {
        $builder = new EquityCurveBuilder('1000');
        $strategy = $this->mockStrategy([
            'grids' => [
                '0' => ['qty' => '1', 'cost' => '90'],
                '1' => ['qty' => '2', 'cost' => '95'],
            ],
        ], '0');

        // totalQty=3, totalCost=1*90+2*95=280, avgCost=93.33333333
        // unrealized = 3*(100-93.33333333) = 20
        $builder->mark($strategy, $this->bar('100'));

        static::assertSame(0, bccomp($builder->points()[0]->equity, '1020', 8));
    }

    public function test_futures_position_side_alias(): void
    {
        $builder = new EquityCurveBuilder('1000');
        $strategy = $this->mockStrategy(['position' => [
            'size' => '1',
            'side' => 'long',
            'entry_price' => '90',
        ]], '0');

        $builder->mark($strategy, $this->bar('100'));

        static::assertSame(0, bccomp($builder->points()[0]->equity, '1010', 8));
    }

    public function test_multiple_points_accumulate(): void
    {
        $builder = new EquityCurveBuilder('1000');
        $strategy = $this->mockStrategy(null, '0');

        $builder->mark($strategy, $this->bar('100'));
        $builder->mark($strategy, $this->bar('110'));

        static::assertCount(2, $builder->points());
    }

    public function test_reset_clears_points(): void
    {
        $builder = new EquityCurveBuilder('1000');
        $builder->mark($this->mockStrategy(null, '0'), $this->bar('100'));
        $builder->reset();

        static::assertCount(0, $builder->points());
    }

    private function mockStrategy(?array $snapshot, string $realizedPnl): StrategyInterface
    {
        $state = new StrategyState(
            schemaVersion: 1,
            params: [],
            positionSnapshot: $snapshot,
            realizedPnl: $realizedPnl,
            consecutiveLosses: 0,
            lastBarTime: null,
        );

        $strategy = $this->createMock(StrategyInterface::class);
        $strategy->method('getState')->willReturn($state);

        return $strategy;
    }

    private function bar(string $close): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '1m',
            openTime: 1_700_000_000_000,
            closeTime: 1_700_000_059_999,
            open: $close,
            high: $close,
            low: $close,
            close: $close,
            volume: '1',
            quoteVolume: '1',
            tradesCount: 1,
        );
    }
}
