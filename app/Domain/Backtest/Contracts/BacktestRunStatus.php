<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Contracts;

/**
 * 回测任务状态机（data-model.md 表 1）。
 *
 * 只进不退：pending → running → completed / failed（终态）。
 * worker 崩溃时 running 任务经 backtest:reap-stale 收割为 failed。
 */
enum BacktestRunStatus: string
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
