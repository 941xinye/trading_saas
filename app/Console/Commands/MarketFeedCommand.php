<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Liquidation\LiquidationFeedRunner;
use App\Domain\Signal\SignalFeedRunner;
use App\Domain\Strategy\StrategyRunner;
use App\Models\StrategyInstance;
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
 * 热启停（2026-10-21）：保活协程每 5s 比对 DB 期望状态与进程内协程，
 * 后台点「启动」（status=starting）无需重启进程即可拉起；停止由实例协程
 * 自身监听 status=stopped 完成。暂停态（breaker/position_risk/reconcile）
 * 启动时保留，不被覆写为 running。
 *
 * 启动依赖：必须加载 swoole 扩展（--dry-run 除外）。
 */
class MarketFeedCommand extends Command
{
    /**
     * 期望有协程在跑的实例状态集合（与 API 状态校验共用同一常量）。
     *
     * @var string[]
     */
    private const LIVE_STATUSES = StrategyInstance::LIVE_STATUSES;

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
        if ($this->option('dry-run')) {
            $ids = $this->liveInstanceIds();

            $this->info('Dry-run mode: would start Swoole coroutine container with:');
            $this->info('  - Exchange WS subscribers');
            $this->info('  - K-line aggregators');
            $this->info('  - Strategy runners for instances: '.($ids === [] ? '(none)' : implode(', ', $ids)));

            return self::SUCCESS;
        }

        if (! extension_loaded('swoole')) {
            $this->error('Swoole extension not loaded. Install via ServBay or pecl install swoole.');

            return self::FAILURE;
        }

        $this->info('MarketFeedWorker (process B) starting...');

        // 踩坑（P1 POC）：Swoole curl hook 与 Guzzle 7.9 不兼容——Guzzle 在 handle
        // release 时调用 curl_setopt(CURLOPT_PREREQFUNCTION, null)，swoole_curl_setopt
        // 不支持该 option 抛 warning，被 Laravel 转成 ErrorException 导致 REST 全挂。
        // 处理：摘掉 SWOOLE_HOOK_CURL，REST 走阻塞 curl（单实例场景每次 <1s 可接受）；
        // WS 基于 Swoole 原生 socket 不受影响。P2 换协程 HTTP client（如自封装
        // Swoole\Coroutine\Http\Client）后恢复。
        //
        // 踩坑（P1 POC）：PDO/Redis 协程化后，保活协程（5s 轮询 status）与 WS 协程
        // （onBar 内 DB/Redis）并发复用同一条底层 socket，Swoole 抛
        // "Socket has already been bound to another coroutine" fatal 终结事件循环。
        // P1 单实例：连 TCP/UNIX/UDG hook 一起摘掉，PDO/phpredis 退化为阻塞 IO
        // （调用期间不发生协程切换 → 天然串行）；多实例 P2 上连接池后再恢复。
        Runtime::enableCoroutine(
            SWOOLE_HOOK_ALL
            & ~SWOOLE_HOOK_CURL
            & ~SWOOLE_HOOK_TCP
            & ~SWOOLE_HOOK_UNIX
            & ~SWOOLE_HOOK_UDG
        );

        try {
            Coroutine\run(function (): void {
                $runner = app(StrategyRunner::class);

                $ids = $this->liveInstanceIds();

                if ($ids === []) {
                    $this->info('No strategy instances to schedule (status in '.implode('/', self::LIVE_STATUSES).').');
                    $this->line('Run `php artisan poc:testnet-setup` to create a POC instance.');
                } else {
                    $this->info('Scheduling strategy instances: '.implode(', ', $ids));
                }

                $runner->start($ids);

                // P2：信号订阅行情（公开 1m WS → 5m 聚合 → AiAnalyzeJob）。
                // 仅订阅/聚合/投递，AI 慢调用在独立 queue worker（ai 队列）执行。
                $this->info('Starting signal feed runner (active signal subscriptions)...');
                app(SignalFeedRunner::class)->start();

                // P5：大户爆仓流订阅（公开 fstream !forceOrder@arr → 聚类 + 落库 + 周期快照）。
                $this->info('Starting liquidation feed runner (Binance fstream forceOrder@arr)...');
                app(LiquidationFeedRunner::class)->start();

                // 主协程保活 + 实例热启动（后台 start → status=starting 无需重启进程）。
                // 停止由实例协程自身监听 status=stopped 完成，这里只负责补齐新增实例。
                while (true) {
                    Coroutine::sleep(5);

                    try {
                        $running = $runner->runningInstanceIds();
                        $toStart = array_values(array_diff($this->liveInstanceIds(), $running));
                        if ($toStart !== []) {
                            $this->info('Hot-start strategy instances: '.implode(', ', $toStart));
                            $runner->start($toStart);
                        }
                    } catch (Throwable $e) {
                        // 单次同步失败不影响行情/策略协程继续运行
                        Log::warning('MarketFeed instance sync failed', ['error' => $e->getMessage()]);
                    }
                }
            });
        } catch (Throwable $e) {
            Log::error('MarketFeedWorker crashed', ['error' => $e->getMessage()]);
            $this->error('MarketFeedWorker crashed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 期望有协程在跑的实例 ID（启动扫描与热启动共用同一口径）。
     *
     * @return int[]
     */
    private function liveInstanceIds(): array
    {
        return DB::table('strategy_instances')
            ->whereIn('status', self::LIVE_STATUSES)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
