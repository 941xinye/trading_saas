<?php

declare(strict_types=1);

namespace App\Domain\Liquidation;

use App\Domain\Exchange\Contracts\LiquidationEvent;
use App\Domain\Exchange\ExchangeManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Swoole\Coroutine;
use Throwable;

/**
 * 强平行情 Runner（运行在 market:feed 进程 B 内）。
 *
 * 启动时加载订阅 symbol 集（strategy_instances.symbols ∪ signal_subscriptions.symbols，
 * 空集则默认 BTC/ETH），订阅 Binance fstream !forceOrder@arr 公共强平流：
 *   每条事件 → 1) 落库 liquidation_events 审计  2) Clusterer::ingest 写入 5m/15m/60m 三档窗口
 *   并行协程：每 60s 把 Redis HASH 桶快照写入 liquidation_clusters 表（重启后回放）
 *
 * 铁律（架构 §9.3 / P1 踩坑 1）：
 *  - 本 Runner 在 Swoole 协程容器内，只做毫秒级 Redis/DB 操作；
 *    强平流是公开流，无需凭据，adapter 传 []；fstream WS 协程在 Adapter 内部独立运行。
 *  - 单事件回调失败 try/catch 不阻断主循环（高频场景必须保证）。
 *  - 订阅清单启动时加载，变更需重启进程。
 */
class LiquidationFeedRunner
{
    private const EXCHANGE = 'binance';

    private const MARKET = 'futures';

    /** @var string[] */
    private const WINDOWS = ['5m', '15m', '60m'];

    /** @var string[] */
    private const SIDES = ['BUY', 'SELL'];

    public function __construct(
        private readonly ExchangeManager $exchangeManager,
        private readonly LiquidationClusterer $clusterer,
    ) {
    }

    /**
     * 加载订阅并建立 WS 订阅 + 周期快照协程（非阻塞）。
     */
    public function start(): void
    {
        $symbols = $this->loadSubscribedSymbols();

        if ($symbols === []) {
            // 即使无订阅也默认 BTC/ETH（大户爆仓数据有展示价值）
            $symbols = ['BTCUSDT', 'ETHUSDT'];
        }

        $adapter = $this->exchangeManager->adapter(self::EXCHANGE, self::MARKET, []);
        $adapter->subscribeLiquidationStream(function (LiquidationEvent $e) use ($symbols): void {
            $this->onLiquidation($e, $symbols);
        });

        // 启动周期快照协程
        Coroutine::create(function () use ($symbols): void {
            $interval = (int) config('liquidation.snapshot_interval_sec', 60);
            // 启动延迟：先让事件流入再快照，避免空快照
            Coroutine::sleep($interval);
            while (true) {
                if (Coroutine::isCanceled()) {
                    return;
                }
                try {
                    $this->snapshotAll($symbols);
                } catch (Throwable $e) {
                    Log::warning('Liquidation snapshot cycle failed', ['error' => $e->getMessage()]);
                }
                Coroutine::sleep($interval);
                if (Coroutine::isCanceled()) {
                    return;
                }
            }
        });

        Log::info('LiquidationFeedRunner 已启动', [
            'symbols' => $symbols,
            'windows' => self::WINDOWS,
            'snapshot_interval_sec' => (int) config('liquidation.snapshot_interval_sec', 60),
        ]);
    }

    // ------------------------------------------------------------------
    // 单事件回调
    // ------------------------------------------------------------------

    private function onLiquidation(LiquidationEvent $e, array $symbols): void
    {
        // 1. 过滤未订阅 symbol（fstream !forceOrder@arr 是全市场流）
        if (! in_array($e->symbol, $symbols, true)) {
            return;
        }

        // 2. 落库审计
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
            Log::warning('Liquidation event insert failed', [
                'symbol' => $e->symbol,
                'error' => $ex->getMessage(),
            ]);
        }

        // 3. 写入聚类（5m/15m/60m 三档窗口）
        foreach (self::WINDOWS as $window) {
            try {
                $this->clusterer->ingest($e, $window);
            } catch (Throwable $ex) {
                Log::warning('Cluster ingest failed', [
                    'window' => $window,
                    'symbol' => $e->symbol,
                    'error' => $ex->getMessage(),
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // 周期快照
    // ------------------------------------------------------------------

    private function snapshotAll(array $symbols): void
    {
        $now = Carbon::now();
        $nowUtc = Carbon::now('UTC');

        foreach ($symbols as $sym) {
            foreach (self::SIDES as $side) {
                foreach (self::WINDOWS as $window) {
                    try {
                        $buckets = $this->clusterer->snapshot(self::EXCHANGE, $sym, $side, $window);

                        foreach ($buckets as $b) {
                            DB::table('liquidation_clusters')->updateOrInsert(
                                [
                                    'exchange_code' => self::EXCHANGE,
                                    'symbol' => $sym,
                                    'side' => $side,
                                    'window' => $window,
                                    'bucket_center' => $b['center'],
                                    'snapshot_ts' => $nowUtc,
                                ],
                                [
                                    'price_from' => $b['from'],
                                    'price_to' => $b['to'],
                                    'total_qty' => $b['total_qty'],
                                    'event_count' => $b['event_count'],
                                    'top_qty' => null,
                                    'updated_at' => $now,
                                ] + ['created_at' => $now]
                            );
                        }
                    } catch (Throwable $ex) {
                        Log::warning('Snapshot per-symbol failed', [
                            'symbol' => $sym,
                            'side' => $side,
                            'window' => $window,
                            'error' => $ex->getMessage(),
                        ]);
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // 订阅 symbol 加载
    // ------------------------------------------------------------------

    /**
     * @return string[]
     */
    private function loadSubscribedSymbols(): array
    {
        $symbols = [];

        // strategy_instances.symbols（运行中实例的订阅）
        $rows = DB::table('strategy_instances')
            ->whereIn('status', ['running', 'paused_by_breaker'])
            ->whereNotNull('symbols')
            ->pluck('symbols');
        foreach ($rows as $s) {
            $symbols = array_merge($symbols, $this->decodeList($s));
        }

        // signal_subscriptions.symbols
        $rows = DB::table('signal_subscriptions')
            ->where('active', 1)
            ->whereNotNull('symbols')
            ->pluck('symbols');
        foreach ($rows as $s) {
            $symbols = array_merge($symbols, $this->decodeList($s));
        }

        $symbols = array_values(array_unique($symbols));
        sort($symbols);

        return $symbols;
    }

    /**
     * @return string[]
     */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_map('strval', $value);
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_map('strval', $decoded);
            }
            // 逗号分隔兜底
            return array_filter(array_map('trim', explode(',', $value)), fn ($x) => $x !== '');
        }

        return [];
    }
}
