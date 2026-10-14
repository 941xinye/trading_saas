<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 账实对账执行命令（P5.5 步骤 1）。
 *
 *   php artisan reconcile:run                 # 扫描所有 running/paused 实例
 *   php artisan reconcile:run --instance=1    # 只对指定实例
 *   php artisan reconcile:run --period=1h     # 指定周期桶
 *
 * 独立普通 PHP 进程运行（可挂调度/supervisor），绝不放进 market:feed 协程
 * （REST 为阻塞 curl，坑 #1）。
 */
class ReconcileRunCommand extends Command
{
    protected $signature = 'reconcile:run {--instance= : 仅对指定 strategy_instance id} {--period=5m : UTC 周期桶规格，如 5m/1h}';

    protected $description = 'Reconcile strategy ledger against real exchange balances';

    public function handle(ReconciliationService $service): int
    {
        $period = (string) $this->option('period');

        $instanceId = $this->option('instance');
        if ($instanceId !== null) {
            $ids = [(int) $instanceId];
        } else {
            $ids = $service->reconcileableInstanceIds();
        }

        if ($ids === []) {
            $this->info('没有需要对账的实例。');

            return self::SUCCESS;
        }

        $summary = ['ok' => 0, 'drift' => 0, 'critical' => 0, 'unknown' => 0];
        $failures = 0;

        foreach ($ids as $id) {
            try {
                $result = $service->runForInstance($id, $period);
                $summary[$result['status']] = ($summary[$result['status']] ?? 0) + 1;
                $this->line(sprintf('  实例 #%-6d → %s（report #%d）', $id, $result['status'], $result['id']));
            } catch (Throwable $e) {
                $failures++;
                $this->error(sprintf('  实例 #%d 对账异常：%s', $id, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '对账完成：ok=%d drift=%d critical=%d unknown=%d 异常=%d（周期 %s UTC）',
            $summary['ok'],
            $summary['drift'],
            $summary['critical'],
            $summary['unknown'],
            $failures,
            $period,
        ));

        if ($summary['critical'] > 0) {
            $this->warn('存在 critical 差异：相关实例已暂停，等待人工核实，系统不会自动改账。');
        }

        // 单实例模式下异常以非零码退出，便于调度/cron 监控发现
        return $instanceId !== null && $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
