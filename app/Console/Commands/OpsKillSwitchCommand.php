<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Risk\PlatformKillSwitch;
use Illuminate\Console\Command;
use Throwable;

/**
 * 平台级全局急停开关。
 *
 *   php artisan ops:kill-switch on --reason="交易所异常" --actor=ops
 *   php artisan ops:kill-switch off
 *   php artisan ops:kill-switch status
 *
 * 只停开新仓：策略平仓、reduceOnly 平仓不受影响。
 */
class OpsKillSwitchCommand extends Command
{
    protected $signature = 'ops:kill-switch
        {action=status : on|off|status}
        {--reason= : 启用原因（写入 Redis 审计载荷）}
        {--actor= : 操作人标识}';

    protected $description = 'Platform-wide kill switch: block new positions, always allow closes';

    public function handle(PlatformKillSwitch $switch): int
    {
        $action = strtolower((string) $this->argument('action'));

        try {
            return match ($action) {
                'on' => $this->activate($switch),
                'off' => $this->deactivate($switch),
                'status' => $this->status($switch),
                default => $this->invalid($action),
            };
        } catch (Throwable $e) {
            $this->error("急停开关操作失败（Redis 不可用）：{$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function activate(PlatformKillSwitch $switch): int
    {
        $reason = trim((string) $this->option('reason')) ?: 'manual';
        $actor = trim((string) $this->option('actor')) ?: null;

        $payload = $switch->activate($reason, $actor);

        $this->warn(sprintf(
            '全局急停已启用：reason=%s actor=%s at=%s（仅停开新仓，平仓放行）',
            $payload['reason'],
            $payload['actor'] ?? '-',
            $payload['activated_at'],
        ));

        return self::SUCCESS;
    }

    private function deactivate(PlatformKillSwitch $switch): int
    {
        $switch->deactivate();
        $this->info('全局急停已解除，策略/信号可恢复开仓。');

        return self::SUCCESS;
    }

    private function status(PlatformKillSwitch $switch): int
    {
        $status = $switch->status();

        if ($status === null) {
            $this->info('全局急停：off');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '全局急停：on（reason=%s actor=%s at=%s）',
            $status['reason'] !== '' ? $status['reason'] : '-',
            $status['actor'] ?? '-',
            $status['activated_at'] !== '' ? $status['activated_at'] : '-',
        ));

        return self::SUCCESS;
    }

    private function invalid(string $action): int
    {
        $this->error("未知动作：{$action}（仅支持 on|off|status）");

        return self::FAILURE;
    }
}
