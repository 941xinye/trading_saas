<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Exceptions;

/**
 * 策略码不在回测白名单。
 *
 * FR-001：仅支持 spot_grid/spot_dca/futures_grid/custom_dsl；未实现策略一律拒绝。
 */
class BacktestStrategyNotImplemented extends \RuntimeException
{
    public function __construct(
        public readonly string $strategyCode,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            '策略 %s 不在回测白名单（支持：%s）',
            $strategyCode,
            implode(', ', config('backtest.strategies', [])),
        ));
    }
}
