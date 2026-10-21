<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Contracts;

/**
 * 参数寻优任务状态机。
 *
 * 只进不退：pending → running → completed / failed（终态）。
 */
enum OptimizationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }
}
