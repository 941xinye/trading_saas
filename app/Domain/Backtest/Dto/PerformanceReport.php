<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Dto;

/**
 * 绩效报告（不可变值对象）。
 *
 * 资金相关全 bcmath 字符串；Sharpe 为 float 展示指标（不结算，constitution II 豁免）。
 * 零值语义：sharpe 零方差 null、win_rate 零平仓 null、profit_factor 零亏损 null。
 */
final readonly class PerformanceReport
{
    public function __construct(
        public string $initialCapital,
        public string $finalEquity,
        public string $totalReturnPct,
        public string $annualizedReturnPct,
        public ?string $sharpeRatio,
        public string $maxDrawdownPct,
        public ?string $winRatePct,
        public ?string $profitFactor,
        public int $tradeCount,
        public string $totalFee,
        public int $barCount,
        public string $dataCoveragePct,
    ) {
    }
}
