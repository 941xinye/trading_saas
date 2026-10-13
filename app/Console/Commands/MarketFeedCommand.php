<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Strategy\StrategyRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Swoole\Coroutine;
use Swoole\Runtime;
use Throwable;

/**
 * 进程 B 入口：启动 Swoole 协程容器，承载交易所 WS 订阅 / K 线聚合 / 策略调度。
 *
 * 与 Octane（进程 A）拆分原因：Octane 是 prefork HTTP 模型，长连接 WS 在 worker
 * 内会阻塞 HTTP 请求；独立 Swoole Server 进程更干净，两进程通过 Redis + Queue
 * 通信（架构方案 §1 双进程模型）。
 *
 * P1 范围：扫描 status in (running, paused_by_breaker) 的实例 → 每实例
 * StrategyRunner 协程（WS 订阅 + 决策链 + 下单闭环，Binance testnet）。
 *
 * 启动依赖：必须加载 swoole 扩展。
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
            $ids = DB::table('strategy_instances')
                ->whereIn('status', ['running', 'paused_by_breaker'])
                ->pluck('id')
                ->all();

            $this->info('Dry-run mode: would start Swoole coroutine container with:');
            $this->info('  - Exchange WS subscribers');
            $this->info('  - K-line aggregators');
            $this->info('  - Strategy runners for instances: ' . ($ids === [] ? '(none)' : implode(', ', $ids)));

            return self::SUCCESS;
        }

        $this->info('MarketFeedWorker (process B) starting...');

        // 踩坑（P1 POC）：Swoole curl hook 与 Guzzle 7.9 不兼容——Guzzle 在 handle
        // release 时调用 curl_setopt(CURLOPT_PREREQFUNCTION, null)，swoole_curl_setopt
        // 不支持该 option 抛 warning，被 Laravel 转成 ErrorException 导致 REST 全挂。
        // 处理：摘掉 SWOOLE_HOOK_CURL，REST 走阻塞 curl（单实例场景每次 <1s 可接受）；
        // WS 基于 Swoole 原生 socket 不受影响。P2 换协程 HTTP client（如自封装
        // Swoole\Coroutine\Http\Client）后恢复。
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL);

        try {
            Coroutine\run(function (): void {
                $runner = app(StrategyRunner::class);

                $ids = DB::table('strategy_instances')
                    ->whereIn('status', ['running', 'paused_by_breaker'])
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    $this->info('No strategy instances to schedule (status in running/paused_by_breaker).');
                    $this->line('Run `php artisan poc:testnet-setup` to create a POC instance.');
                } else {
                    $this->info('Scheduling strategy instances: ' . implode(', ', $ids));
                }

                $runner->start($ids);

                // 主协程保活；实例级动态启停 P2 接入（当前重启进程生效）
                while (true) {
                    Coroutine::sleep(5);
                }
            });
        } catch (Throwable $e) {
            Log::error('MarketFeedWorker crashed', ['error' => $e->getMessage()]);
            $this->error('MarketFeedWorker crashed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
