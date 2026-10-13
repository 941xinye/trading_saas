<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 交易所健康检查：探测 REST/WS 延迟与可用性，落 exchange_health_checks 表。
 *
 * 沿用 AI-API 项目已验证的 HealthChecker 命令约定：
 *  - 不带 --exchange 时遍历所有启用交易所
 *  - --fix 触发自动恢复（重置 status=degraded → 探测成功后 active；down 不自动恢复）
 *  - 失败次数 / 延迟告警通过 DispatchNotificationJob 走告警渠道
 *
 * 与 ExchangeHealthChecker（Domain/Exchange）配合：本命令是命令行入口，
 * 实际探测逻辑封装在 Domain 层（沿用 AI-API 的复用约定）。
 *
 * POC 阶段：handle 仅占位，实际探测逻辑待 P1 POC 实现。
 */
class HealthCheckCommand extends Command
{
    /**
     * 命令签名。
     * --exchange: 指定交易所 code（binance|okx|bybit|bitget），缺省全量
     * --fix: 启用自动恢复尝试
     */
    protected $signature = 'exchange:health-check {--exchange=} {--fix}';

    /**
     * 命令描述。
     */
    protected $description = 'Probe exchange REST/WS latency and availability; write exchange_health_checks rows';

    public function handle(): int
    {
        $exchange = $this->option('exchange');
        $fix = (bool) $this->option('fix');

        $target = $exchange !== null && $exchange !== '' ? "exchange={$exchange}" : 'all exchanges';
        $mode = $fix ? ' (with --fix)' : '';
        $this->info("Skeleton: exchange:health-check {$target}{$mode} not implemented. Wait for P1 POC.");

        return self::SUCCESS;
    }
}
