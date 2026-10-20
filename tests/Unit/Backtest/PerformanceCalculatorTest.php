<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use App\Domain\Backtest\Dto\BacktestRunResult;
use App\Domain\Backtest\Dto\EquityPoint;
use App\Domain\Backtest\Dto\SimulatedTrade;
use App\Domain\Backtest\Services\PerformanceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Backtest\Services\PerformanceCalculator
 */
final class PerformanceCalculatorTest extends TestCase
{
    private PerformanceCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new PerformanceCalculator();
    }

    /**
     * 黄金向量 1：单调上涨 10 点
     * initial 1000，equity 线性到 1100 → return=10%，drawdown=0，win_rate=null
     */
    public function test_golden_vector_monotonic_up(): void
    {
        $points = [];
        for ($i = 0; $i <= 10; $i++) {
            $points[] = new EquityPoint(1_700_000_000_000 + $i * 86_400_000, bcadd('1000', (string) ($i * 10), 8));
        }

        $result = new BacktestRunResult(barCount: 11, trades: [], equityPoints: $points, coveragePct: '100');
        $report = $this->calc->calculate($result, '1000', 10);

        static::assertSame(0, bccomp($report->finalEquity, '1100', 8));
        static::assertSame(0, bccomp($report->totalReturnPct, '10', 8));
        static::assertSame(0, bccomp($report->maxDrawdownPct, '0', 8));
        static::assertNull($report->winRatePct);
        static::assertNull($report->profitFactor);
        static::assertSame(0, $report->tradeCount);
    }

    /**
     * 黄金向量 2：已知回撤序列
     * [1000, 1200, 900, 1100] → drawdown = (1200-900)/1200 = 25%
     */
    public function test_golden_vector_max_drawdown(): void
    {
        $points = [
            new EquityPoint(1_700_000_000_000, '1000'),
            new EquityPoint(1_700_086_400_000, '1200'),
            new EquityPoint(1_700_172_800_000, '900'),
            new EquityPoint(1_700_259_200_000, '1100'),
        ];

        $result = new BacktestRunResult(barCount: 4, trades: [], equityPoints: $points, coveragePct: '100');
        $report = $this->calc->calculate($result, '1000', 4);

        static::assertSame(0, bccomp($report->maxDrawdownPct, '25', 8));
    }

    /**
     * 黄金向量 3：两胜一负
     * trades pnl [+100, +50, -60] → win_rate=66.66666667%，profit_factor=150/60=2.5
     */
    public function test_golden_vector_win_rate_profit_factor(): void
    {
        $trades = [
            $this->trade('100'),
            $this->trade('50'),
            $this->trade('-60'),
        ];

        $points = [
            new EquityPoint(1_700_000_000_000, '1000'),
            new EquityPoint(1_700_086_400_000, '1090'),
        ];

        $result = new BacktestRunResult(barCount: 2, trades: $trades, equityPoints: $points, coveragePct: '100');
        $report = $this->calc->calculate($result, '1000', 2);

        static::assertSame(0, bccomp($report->winRatePct, '66.66666666', 8));
        static::assertSame(0, bccomp($report->profitFactor, '2.5', 8));
        static::assertSame(3, $report->tradeCount);
    }

    /**
     * 黄金向量 4：零方差 → sharpe=null
     */
    public function test_golden_vector_zero_variance_sharpe_null(): void
    {
        $points = [
            new EquityPoint(1_700_000_000_000, '1000'),
            new EquityPoint(1_700_086_400_000, '1000'),
            new EquityPoint(1_700_172_800_000, '1000'),
        ];

        $result = new BacktestRunResult(barCount: 3, trades: [], equityPoints: $points, coveragePct: '100');
        $report = $this->calc->calculate($result, '1000', 3);

        static::assertNull($report->sharpeRatio);
    }

    /**
     * 黄金向量 5：手续费侵蚀
     * 10 笔 fee 各 1 → total_fee=10
     */
    public function test_golden_vector_total_fee(): void
    {
        $trades = [];
        for ($i = 0; $i < 10; $i++) {
            $trades[] = $this->trade('0', '1');
        }

        $points = [
            new EquityPoint(1_700_000_000_000, '1000'),
        ];

        $result = new BacktestRunResult(barCount: 1, trades: $trades, equityPoints: $points, coveragePct: '100');
        $report = $this->calc->calculate($result, '1000', 1);

        static::assertSame(0, bccomp($report->totalFee, '10', 8));
        static::assertSame(10, $report->tradeCount);
    }

    public function test_zero_closed_trades_win_rate_null(): void
    {
        $trades = [$this->trade('0'), $this->trade('0')];
        $points = [new EquityPoint(1_700_000_000_000, '1000')];

        $result = new BacktestRunResult(barCount: 1, trades: $trades, equityPoints: $points, coveragePct: '100');
        $report = $this->calc->calculate($result, '1000', 1);

        static::assertNull($report->winRatePct);
        static::assertNull($report->profitFactor);
    }

    public function test_no_losses_profit_factor_null(): void
    {
        $trades = [$this->trade('100'), $this->trade('50')];
        $points = [new EquityPoint(1_700_000_000_000, '1000')];

        $result = new BacktestRunResult(barCount: 1, trades: $trades, equityPoints: $points, coveragePct: '100');
        $report = $this->calc->calculate($result, '1000', 1);

        static::assertSame(0, bccomp($report->winRatePct, '100', 8));
        static::assertNull($report->profitFactor);
    }

    public function test_empty_points_uses_initial_capital(): void
    {
        $result = new BacktestRunResult(barCount: 0, trades: [], equityPoints: [], coveragePct: '0');
        $report = $this->calc->calculate($result, '1000', 1);

        static::assertSame(0, bccomp($report->finalEquity, '1000', 8));
        static::assertSame(0, bccomp($report->totalReturnPct, '0', 8));
        static::assertNull($report->sharpeRatio);
    }

    public function test_annualized_return(): void
    {
        $points = [
            new EquityPoint(1_700_000_000_000, '1000'),
            new EquityPoint(1_700_086_400_000, '1100'),
        ];

        $result = new BacktestRunResult(barCount: 2, trades: [], equityPoints: $points, coveragePct: '100');
        // 1 天收益 10% → 年化 3650%
        $report = $this->calc->calculate($result, '1000', 1);

        static::assertSame(0, bccomp($report->totalReturnPct, '10', 8));
        static::assertSame(0, bccomp($report->annualizedReturnPct, '3650', 8));
    }

    private function trade(string $realizedPnl, string $fee = '0'): SimulatedTrade
    {
        return new SimulatedTrade(
            seq: 1,
            barOpenTime: 1_700_000_000_000,
            side: 'sell',
            qty: '1',
            price: '100',
            fee: $fee,
            realizedPnl: $realizedPnl,
        );
    }
}
