<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 查看实例对账状态（reconcile:status {instanceId}）。
 *
 * 打印：实例当前状态（含 paused_by_reconcile 暂停原因）+ 最近 5 条对账报告
 * + 最新一条报告的差异明细（diff_json）。
 */
class ReconcileStatusCommand extends Command
{
    protected $signature = 'reconcile:status {instanceId}';

    protected $description = 'Show reconciliation reports and pause reason for an instance';

    public function handle(): int
    {
        $instanceId = (int) $this->argument('instanceId');

        $instance = DB::table('strategy_instances')->where('id', $instanceId)->first();
        if ($instance === null) {
            $this->error("实例 #{$instanceId} 不存在");

            return self::FAILURE;
        }

        $this->info("=== 实例 #{$instanceId} 对账状态 ===");
        $this->line("  symbol:          {$instance->symbol}");
        $this->line("  status:          {$instance->status}");
        if ($instance->status === 'paused_by_reconcile') {
            $this->warn('  暂停原因:        账实对账检出 critical 差异（人工核实后重新启动）');
        }
        $this->line('  breaker_state:   '.$instance->breaker_state);

        $reports = DB::table('reconciliation_reports')
            ->where('strategy_instance_id', $instanceId)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'status', 'period_key', 'checked_at']);

        $this->newLine();
        $this->info('--- 最近 5 条对账报告 ---');
        if ($reports->isEmpty()) {
            $this->line('  （无报告，尚未执行过 reconcile:run）');

            return self::SUCCESS;
        }

        foreach ($reports as $r) {
            $this->line(sprintf('  #%d  %-9s  period=%s  %s', $r->id, $r->status, $r->period_key, $r->checked_at));
        }

        $latest = DB::table('reconciliation_reports')
            ->where('strategy_instance_id', $instanceId)
            ->orderByDesc('id')
            ->first(['status', 'diff_json']);

        if ($latest !== null && in_array($latest->status, ['drift', 'critical'], true)) {
            $this->newLine();
            $this->warn('--- 最新差异明细 ---');
            $diff = json_decode((string) $latest->diff_json, true);
            if (is_array($diff)) {
                $this->line(json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        }

        return self::SUCCESS;
    }
}
