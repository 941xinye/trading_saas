<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 进程 B 入口：启动 Swoole 协程容器，承载交易所 WS 订阅 / K 线聚合 / 策略调度。
 *
 * 与 Octane（进程 A）拆分原因：Octane 是 prefork HTTP 模型，长连接 WS 在 worker
 * 内会阻塞 HTTP 请求；独立 Swoole Server 进程更干净，两进程通过 Redis + Queue
 * 通信（架构方案 §1 双进程模型）。
 *
 * 启动依赖：必须加载 swoole 扩展。本机通过 ServBay 安装：
 *   - ServBay MCP 提供 Swoole runtime 管理
 *   - 或 pecl install swoole
 *
 * POC 阶段：handle 仅校验环境与提示，实际协程启动待 P1 POC 实现。
 */
class MarketFeedCommand extends Command
{
    /**
     * 命令签名。
     * --dry-run: 仅打印将启动的协程组件清单，不真正连交易所。
     */
    protected $signature = 'market:feed {--dry-run : Run without placing real orders}';

    /**
     * 命令描述。
     */
    protected $description = 'Start MarketFeedWorker (process B): Swoole coroutine container for exchange WS + kline aggregation + strategy scheduler';

    public function handle(): int
    {
        if (! extension_loaded('swoole')) {
            $this->error('Swoole extension not loaded. Install via ServBay or pecl install swoole.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry-run mode: would start Swoole coroutine container with:');
            $this->info('  - Exchange WS subscribers');
            $this->info('  - K-line aggregators');
            $this->info('  - Strategy runners');
            $this->info('Skeleton: actual coroutine startup not implemented. Wait for P1 POC.');

            return self::SUCCESS;
        }

        $this->info('Skeleton: real market feed startup not implemented. Wait for P1 POC.');

        return self::SUCCESS;
    }
}
