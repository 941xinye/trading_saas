<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backtest\Contracts\BacktestRunStatus;
use App\Domain\Backtest\Models\BacktestRun;
use Illuminate\Console\Command;

/**
 * backtest:reap-stale 崩溃任务收割（spec 008 T037）。
 *
 * 扫描 running 且 updated_at 超过 stale_after_sec 的任务 → 标 failed（error_message 含 'reaped'）。
 * 终态行不触碰；无匹配输出 0 affected。
 */
final class ReapStaleBacktestsCommand extends Command
{
    protected $signature = 'backtest:reap-stale';

    protected $description = '收割停滞的 running 回测任务（崩溃恢复）';

    public function handle(): int
    {
        $staleAfter = (int) config('backtest.stale_after_sec', 240);
        $threshold = now()->subSeconds($staleAfter);

        $affected = BacktestRun::where('status', BacktestRunStatus::Running)
            ->where('updated_at', '<', $threshold)
            ->update([
                'status' => BacktestRunStatus::Failed,
                'error_message' => 'reaped: running 超时未更新（stale_after_sec='.$staleAfter.'）',
                'finished_at' => now(),
            ]);

        $this->info("reaped {$affected} stale backtest run(s)");

        return self::SUCCESS;
    }
}
