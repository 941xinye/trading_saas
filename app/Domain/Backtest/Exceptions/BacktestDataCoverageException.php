<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Exceptions;

/**
 * 回测数据覆盖率不足。
 *
 * 实际 bar 数 / 区间理论 bar 数 × 100 < config('backtest.min_coverage_pct') 时抛出（FR-013）。
 * 不静默截断——调用方应据此标 failed 或标注覆盖率。
 */
class BacktestDataCoverageException extends \RuntimeException
{
    public function __construct(
        public readonly string $actualPct,
        public readonly string $thresholdPct,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            '回测数据覆盖率不足：实际 %s%% < 阈值 %s%%（FR-013）',
            $actualPct,
            $thresholdPct,
        ));
    }
}
