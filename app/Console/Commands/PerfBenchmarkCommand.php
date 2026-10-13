<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 性能基准测试：跑 tests/Perf 下预设场景，输出延迟 / 吞吐 / Redis 命中率等指标。
 *
 * 沿用 CRM 项目的 perf:benchmark 命令约定：以场景而非命令参数为主入口，
 * 不同 --scenario 触发不同 fixture（订单并发 / AI 调用并发 / 行情订阅压力等）。
 *
 * 输出格式：JSON（便于 CI 比对 baseline + 回归告警）。
 *
 * POC 阶段：handle 仅占位，实际场景实现待 P5 POC（性能验证阶段）。
 */
class PerfBenchmarkCommand extends Command
{
    /**
     * 命令签名。
     * --scenario: 场景名，缺省 default
     */
    protected $signature = 'perf:benchmark {--scenario=default}';

    /**
     * 命令描述。
     */
    protected $description = 'Run performance benchmark scenarios under tests/Perf (CRM convention)';

    public function handle(): int
    {
        $scenario = (string) $this->option('scenario');
        $this->info("Skeleton: perf:benchmark --scenario={$scenario} not implemented. Wait for P5 POC.");

        return self::SUCCESS;
    }
}
