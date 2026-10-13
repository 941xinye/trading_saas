<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 策略调度控制器：启动 / 停止 strategy_instance 的运行态。
 *
 * 进程 B 内 StrategyRunner 协程调度器的命令行入口。start 把指定 instance 从
 * stopped/starting 推进到 running；stop 触发 graceful shutdown（先停止开新单、
 * 等未成交订单撤单、刷盘 position_snapshot）。
 *
 * 不带 instanceId 时批量操作（按租户级 / 平台级，POC 阶段定义具体过滤语义）。
 *
 * POC 阶段：handle 仅占位提示，实际状态机迁移待 P1 POC 实现。
 */
class StrategySchedulerCommand extends Command
{
    /**
     * 命令签名。
     * action: start|stop（必填，限定枚举）
     * instanceId: 可选，缺省时批量操作（POC 阶段定义默认范围）。
     */
    protected $signature = 'strategy:schedule {action=start|stop} {instanceId?}';

    /**
     * 命令描述。
     */
    protected $description = 'Start or stop a strategy instance scheduler (process B StrategyRunner)';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $instanceId = $this->argument('instanceId');

        $target = $instanceId !== null ? "instance #{$instanceId}" : 'all instances';
        $this->info("Skeleton: strategy:schedule {$action} {$target} not implemented. Wait for P1 POC.");

        return self::SUCCESS;
    }
}
