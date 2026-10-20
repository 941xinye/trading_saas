<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Services;

use App\Domain\Backtest\Dto\BacktestRunResult;
use App\Domain\Backtest\Dto\PerformanceReport;
use App\Domain\Backtest\Dto\SimulatedTrade;

/**
 * 绩效计算器（spec 008 T024 / contracts/performance-metrics-contract.md）。
 *
 * 单遍遍历净值曲线产出 return/drawdown/Sharpe 序列；平仓盈亏从 SimulatedTrade.realizedPnl
 * 单笔提取（非零即平仓）。
 */
final class PerformanceCalculator
{
    /**
     * @param BacktestRunResult $result
     * @param string            $initialCapital
     * @param int               $days 区间自然日数（(toUtc-fromUtc)/86400 向上取整）
     */
    public function calculate(BacktestRunResult $result, string $initialCapital, int $days): PerformanceReport
    {
        $points = $result->equityPoints;
        $trades = $result->trades;

        $finalEquity = $points === [] ? $initialCapital : $points[count($points) - 1]->equity;

        // 单遍遍历：净值曲线 → 日收益序列 + 最大回撤
        [$dailyReturns, $maxDrawdownPct] = $this->scanEquityCurve($points, $initialCapital);

        $totalReturnPct = bccomp($initialCapital, '0', 8) === 1
            ? bcmul(bcdiv(bcsub($finalEquity, $initialCapital, 12), $initialCapital, 12), '100', 8)
            : '0';

        $annualizedReturnPct = $days > 0
            ? bcmul($totalReturnPct, bcdiv('365', (string) $days, 12), 8)
            : '0';

        $sharpeRatio = $this->sharpe($dailyReturns);

        // 平仓序列：realizedPnl ≠ 0 的 trade
        $closedPnls = array_values(array_filter(
            array_map(static fn (SimulatedTrade $t): string => $t->realizedPnl, $trades),
            static fn (string $pnl): bool => bccomp($pnl, '0', 8) !== 0,
        ));

        $winRatePct = $this->winRate($closedPnls);
        $profitFactor = $this->profitFactor($closedPnls);

        $totalFee = '0';
        foreach ($trades as $trade) {
            $totalFee = bcadd($totalFee, $trade->fee, 8);
        }

        return new PerformanceReport(
            initialCapital: $initialCapital,
            finalEquity: $finalEquity,
            totalReturnPct: $totalReturnPct,
            annualizedReturnPct: $annualizedReturnPct,
            sharpeRatio: $sharpeRatio,
            maxDrawdownPct: $maxDrawdownPct,
            winRatePct: $winRatePct,
            profitFactor: $profitFactor,
            tradeCount: count($trades),
            totalFee: $totalFee,
            barCount: $result->barCount,
            dataCoveragePct: $result->coveragePct,
        );
    }

    /**
     * 单遍遍历净值曲线：按 UTC 日分组取每日末点 → 日收益序列 + 最大回撤。
     *
     * @param \App\Domain\Backtest\Dto\EquityPoint[] $points
     * @return array{0: list<float>, 1: string}
     */
    private function scanEquityCurve(array $points, string $initialCapital): array
    {
        if ($points === []) {
            return [[], '0'];
        }

        // 按 UTC 日分组取每日末点 equity
        $dailyEquity = [];
        foreach ($points as $point) {
            $day = intdiv($point->barTime, 86400000);
            $dailyEquity[$day] = $point->equity;
        }
        ksort($dailyEquity);

        $dailyReturns = [];
        $prevEquity = $initialCapital;
        $peak = $initialCapital;
        $maxDrawdown = '0';

        foreach ($dailyEquity as $equity) {
            if (bccomp($prevEquity, '0', 8) === 1) {
                $ret = bcdiv(bcsub($equity, $prevEquity, 12), $prevEquity, 12);
                $dailyReturns[] = (float) $ret;
            }

            if (bccomp($equity, $peak, 12) === 1) {
                $peak = $equity;
            }

            $drawdown = bccomp($peak, '0', 8) === 1
                ? bcmul(bcdiv(bcsub($peak, $equity, 12), $peak, 12), '100', 8)
                : '0';
            if (bccomp($drawdown, $maxDrawdown, 8) === 1) {
                $maxDrawdown = $drawdown;
            }

            $prevEquity = $equity;
        }

        return [$dailyReturns, $maxDrawdown];
    }

    /**
     * Sharpe = mean(日收益) / stddev(日收益) × √365。
     * 零交易或零方差 → null。
     *
     * @param list<float> $dailyReturns
     */
    private function sharpe(array $dailyReturns): ?string
    {
        $n = count($dailyReturns);
        if ($n === 0) {
            return null;
        }

        $mean = array_sum($dailyReturns) / $n;
        $variance = 0.0;
        foreach ($dailyReturns as $r) {
            $variance += ($r - $mean) ** 2;
        }
        $variance /= $n;
        $stddev = sqrt($variance);

        if ($stddev == 0.0) {
            return null;
        }

        $sharpe = ($mean / $stddev) * sqrt(365.0);

        return number_format($sharpe, 8, '.', '');
    }

    /**
     * 胜率 = 盈利平仓数 / 总平仓数 × 100。零平仓 → null。
     *
     * @param list<string> $closedPnls
     */
    private function winRate(array $closedPnls): ?string
    {
        $count = count($closedPnls);
        if ($count === 0) {
            return null;
        }

        $wins = 0;
        foreach ($closedPnls as $pnl) {
            if (bccomp($pnl, '0', 8) === 1) {
                $wins++;
            }
        }

        return bcmul(bcdiv((string) $wins, (string) $count, 12), '100', 8);
    }

    /**
     * 盈亏比 = Σ盈利 / |Σ亏损|。零亏损 → null（口径注明「无穷大记 null」）。
     *
     * @param list<string> $closedPnls
     */
    private function profitFactor(array $closedPnls): ?string
    {
        $grossProfit = '0';
        $grossLoss = '0';

        foreach ($closedPnls as $pnl) {
            if (bccomp($pnl, '0', 8) === 1) {
                $grossProfit = bcadd($grossProfit, $pnl, 12);
            } elseif (bccomp($pnl, '0', 8) === -1) {
                $grossLoss = bcadd($grossLoss, $pnl, 12);
            }
        }

        if (bccomp($grossLoss, '0', 8) === 0) {
            return null;
        }

        return bcdiv($grossProfit, bcmul($grossLoss, '-1', 12), 8);
    }
}
