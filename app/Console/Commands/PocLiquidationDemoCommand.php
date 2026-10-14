<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Exchange\Contracts\LiquidationEvent;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Liquidation\LiquidationClusterer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Swoole\Coroutine;
use Swoole\Runtime;
use Throwable;

/**
 * P5 demo：连真实 Binance fstream !forceOrder@arr 公共强平流，采集 N 秒验证 BTC/ETH 密集区。
 *
 * 用法：php artisan poc:liquidation-demo [--duration=120] [--symbols=BTCUSDT,ETHUSDT]
 *
 * 不依赖 market:feed 长驻进程，独立 Swoole 协程容器运行；duration 到期主动 unsubscribeStream + 退出。
 * 落库 liquidation_events + Redis 聚类，输出每 10s 统计与 top 3 密集区。
 *
 * 注意：与 market:feed 同时运行时，Redis key 共享但不冲突（fstream 公共流允许多连接）。
 */
class PocLiquidationDemoCommand extends Command
{
    protected $signature = 'poc:liquidation-demo
        {--duration=120 : 采集秒数（默认 120）}
        {--symbols= : 自定义 symbol 集合（逗号分隔，默认 BTCUSDT,ETHUSDT）}';

    protected $description = 'POC: connect to Binance fstream !forceOrder@arr and capture liquidations for N seconds';

    public function handle(): int
    {
        if (! extension_loaded('swoole')) {
            $this->error('Swoole extension not loaded. Install via ServBay or pecl install swoole.');

            return self::FAILURE;
        }

        $duration = max(30, (int) $this->option('duration'));
        $symbols = $this->option('symbols') !== null
            ? array_filter(array_map('trim', explode(',', (string) $this->option('symbols'))), fn ($x) => $x !== '')
            : ['BTCUSDT', 'ETHUSDT'];

        // 与 MarketFeedCommand 一致的 hook 配置（P1 踩坑 1：摘掉 CURL/TCP/UNIX/UDG）
        Runtime::enableCoroutine(
            SWOOLE_HOOK_ALL
            & ~SWOOLE_HOOK_CURL
            & ~SWOOLE_HOOK_TCP
            & ~SWOOLE_HOOK_UNIX
            & ~SWOOLE_HOOK_UDG
        );

        $this->info("P5 liquidation demo: 连接 Binance fstream !forceOrder@arr，采集 {$duration}s");
        $this->info('订阅 symbol: ' . implode(', ', $symbols));

        // 计数器（协程内引用传递）
        $counters = [
            'total' => 0,
            'filtered' => 0,
            'by_symbol' => [],
        ];

        try {
            Coroutine\run(function () use ($duration, $symbols, &$counters) {
                $clusterer = new LiquidationClusterer();
                $manager = app(ExchangeManager::class);
                $adapter = $manager->adapter('binance', 'futures', []);

                $subId = $adapter->subscribeLiquidationStream(
                    function (LiquidationEvent $e) use ($symbols, $clusterer, &$counters): void {
                        $counters['total']++;
                        if (! in_array($e->symbol, $symbols, true)) {
                            $counters['filtered']++;

                            return;
                        }
                        $counters['by_symbol'][$e->symbol] = ($counters['by_symbol'][$e->symbol] ?? 0) + 1;

                        // 落库审计
                        try {
                            DB::table('liquidation_events')->insert([
                                'exchange_code' => $e->exchangeCode,
                                'symbol' => $e->symbol,
                                'side' => $e->side,
                                'price' => $e->price,
                                'qty' => $e->qty,
                                'value_usd' => $e->valueUsd,
                                'event_ts' => Carbon::createFromTimestampMs($e->tsMs)->setTimezone('UTC'),
                                'created_at' => Carbon::now(),
                                'updated_at' => Carbon::now(),
                            ]);
                        } catch (Throwable $ex) {
                            Log::debug('Demo event insert failed', ['symbol' => $e->symbol, 'error' => $ex->getMessage()]);
                        }

                        // 写入聚类（5m/15m/60m）
                        foreach (['5m', '15m', '60m'] as $window) {
                            try {
                                $clusterer->ingest($e, $window);
                            } catch (Throwable $ex) {
                                // 单 ingest 失败不阻断
                            }
                        }
                    }
                );

                // 主协程：每 10s 输出统计
                $elapsed = 0;
                while ($elapsed < $duration) {
                    Coroutine::sleep(10);
                    $elapsed += 10;

                    $inScope = $counters['total'] - $counters['filtered'];
                    $this->info("[{$elapsed}s] total={$counters['total']} in_scope={$inScope} filtered={$counters['filtered']}");

                    foreach ($counters['by_symbol'] as $sym => $cnt) {
                        if ($cnt === 0) {
                            continue;
                        }
                        $this->line("  {$sym}: {$cnt} 条");

                        foreach (['SELL', 'BUY'] as $side) {
                            $snap = $clusterer->snapshot('binance', $sym, $side, '5m');
                            if ($snap === []) {
                                continue;
                            }
                            $top3 = array_slice($snap, 0, 3);
                            foreach ($top3 as $i => $b) {
                                $rank = $i + 1;
                                $this->line(sprintf(
                                    "    %s 5m #%d: %s-%s qty=%s count=%d",
                                    $side,
                                    $rank,
                                    $b['from'],
                                    $b['to'],
                                    $b['total_qty'],
                                    $b['event_count'],
                                ));
                            }
                        }
                    }
                }

                $adapter->unsubscribeStream($subId);
                $this->newLine();
                $this->info('Demo 完成，最终统计：');
                $this->line("  total events: {$counters['total']}");
                $this->line("  in-scope events (subscribed symbols): " . ($counters['total'] - $counters['filtered']));
                $this->line("  by symbol: " . json_encode($counters['by_symbol']));
            });
        } catch (Throwable $e) {
            $this->error('Demo crashed: ' . $e->getMessage());
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
