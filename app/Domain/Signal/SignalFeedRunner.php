<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\ExchangeManager;
use App\Jobs\AiAnalyzeJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 信号行情 Runner（运行在 market:feed 进程 B 内）。
 *
 * 启动时加载全部 active 订阅，按 symbol 分组订阅交易所公开 1m WS：
 *   1m bar 闭合 → BarAggregator 按订阅周期（5m/15m/…）聚合
 *   → 高周期 bar 闭合：upsert kline_bars（平台级）+ 逐订阅 dispatch AiAnalyzeJob
 *
 * 铁律（架构 §9.3 / P1 踩坑 1）：
 *  - 本 Runner 在 Swoole 协程容器内，只做毫秒级 Redis/DB 操作与 Job 投递；
 *    30s 的 AI HTTP 调用一律在 ai 队列的普通 PHP worker 内执行，绝不在本进程调用。
 *  - 公开行情无需凭据，adapter 传 []；POC 固定 binance spot（testnet K 线镜像真实市场）。
 *  - 订阅清单启动时加载，变更需重启进程（动态热更等后续阶段）。
 */
class SignalFeedRunner
{
    private const EXCHANGE = 'binance';

    private const MARKET = 'spot';

    /** @var array<string,BarAggregator> key = symbol|interval */
    private array $aggregators = [];

    public function __construct(
        private readonly ExchangeManager $exchangeManager,
    ) {
    }

    /**
     * 加载订阅并建立 WS 订阅（非阻塞：WS 循环在 Adapter 内部独立协程）。
     */
    public function start(): void
    {
        // symbol => interval => [subscriptionRow,...]
        $grouped = $this->loadActiveSubscriptions();

        if ($grouped === []) {
            Log::info('SignalFeedRunner: 无 active 信号订阅，跳过启动');

            return;
        }

        $adapter = $this->exchangeManager->adapter(self::EXCHANGE, self::MARKET, []);

        foreach ($grouped as $symbol => $intervalMap) {
            $this->subscribeSymbol($adapter, $symbol, $intervalMap);
        }

        $subscriptionCount = 0;
        foreach ($grouped as $intervalMap) {
            foreach ($intervalMap as $subscriptions) {
                $subscriptionCount += count($subscriptions);
            }
        }

        Log::info('SignalFeedRunner 已启动', [
            'symbols' => array_keys($grouped),
            'subscription_count' => $subscriptionCount,
        ]);
    }

    /**
     * @return array<string,array<string,object[]>>
     */
    private function loadActiveSubscriptions(): array
    {
        $rows = DB::table('signal_subscriptions')->where('active', 1)->get();
        $grouped = [];

        foreach ($rows as $row) {
            $symbols = $this->decodeList($row->symbols);
            $intervals = $this->decodeList($row->intervals);

            foreach ($symbols as $symbol) {
                foreach ($intervals as $interval) {
                    $grouped[$symbol][$interval][] = $row;
                }
            }
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @param array<string,object[]> $intervalMap interval => 订阅行
     */
    private function subscribeSymbol(ExchangeAdapter $adapter, string $symbol, array $intervalMap): void
    {
        $intervals = array_keys($intervalMap);

        // subscribeKlineStream 内部自建 WS 协程并立即返回（含断线指数退避重连），
        // 无需本侧保活；进程存活由 MarketFeedCommand 主协程循环保证。
        $adapter->subscribeKlineStream(
            $symbol,
            '1m',
            function (ExchangeKline $bar1m) use ($symbol, $intervals, $intervalMap): void {
                foreach ($intervals as $interval) {
                    try {
                        $aggregator = $this->aggregator($symbol, $interval);
                        $closed = $aggregator->aggregate($bar1m, $interval);

                        if ($closed === null) {
                            continue;
                        }

                        // 残次桶守卫：进程中途启动/WS 断线会导致桶内 1m bar 不齐，
                        // 不完整聚合 bar 不写 kline_bars、不触发信号（宁可漏一轮）
                        $expectedTicks = BarAggregator::EXPECTED_TICKS[$interval] ?? null;
                        $actualTicks = $aggregator->lastClosedTicks($closed->exchangeCode, $symbol, $interval);
                        if ($expectedTicks !== null && $actualTicks < $expectedTicks) {
                            Log::warning('聚合桶不完整，跳过写库与信号投递', [
                                'symbol' => $symbol,
                                'interval' => $interval,
                                'open_time' => $closed->openTime,
                                'ticks' => $actualTicks,
                                'expected' => $expectedTicks,
                            ]);

                            continue;
                        }

                        $this->upsertKlineBar($closed);

                        foreach ($intervalMap[$interval] as $subscription) {
                            AiAnalyzeJob::dispatch(
                                (int) $subscription->tenant_id,
                                (int) $subscription->id,
                                $symbol,
                                $interval,
                                $this->barToArray($closed),
                            );
                        }

                        Log::info('聚合 bar 闭合，已投递 AI 分析', [
                            'symbol' => $symbol,
                            'interval' => $interval,
                            'open_time' => $closed->openTime,
                            'subscriptions' => count($intervalMap[$interval]),
                        ]);
                    } catch (Throwable $e) {
                        // 单周期/单订阅失败不影响其他周期
                        Log::error('信号 bar 聚合/投递失败', [
                            'symbol' => $symbol,
                            'interval' => $interval,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            },
        );
    }

    private function aggregator(string $symbol, string $interval): BarAggregator
    {
        $key = $symbol . '|' . $interval;

        return $this->aggregators[$key] ??= new BarAggregator();
    }

    private function upsertKlineBar(ExchangeKline $bar): void
    {
        $now = Carbon::now();
        $values = [
            'exchange_code' => $bar->exchangeCode,
            'symbol' => $bar->symbol,
            'interval' => $bar->interval,
            'open_time' => Carbon::createFromTimestampMs($bar->openTime)->setTimezone('UTC'),
            'close_time' => Carbon::createFromTimestampMs($bar->closeTime)->setTimezone('UTC'),
            'open' => $bar->open,
            'high' => $bar->high,
            'low' => $bar->low,
            'close' => $bar->close,
            'volume' => $bar->volume,
            'quote_volume' => $bar->quoteVolume,
            'trades_count' => $bar->tradesCount,
            'updated_at' => $now,
        ];

        DB::table('kline_bars')->updateOrInsert(
            [
                'exchange_code' => $bar->exchangeCode,
                'symbol' => $bar->symbol,
                'interval' => $bar->interval,
                'open_time' => $values['open_time'],
            ],
            $values + ['created_at' => $now],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function barToArray(ExchangeKline $bar): array
    {
        return [
            'exchange_code' => $bar->exchangeCode,
            'symbol' => $bar->symbol,
            'interval' => $bar->interval,
            'open_time' => $bar->openTime,
            'close_time' => $bar->closeTime,
            'open' => $bar->open,
            'high' => $bar->high,
            'low' => $bar->low,
            'close' => $bar->close,
            'volume' => $bar->volume,
            'quote_volume' => $bar->quoteVolume,
            'trades_count' => $bar->tradesCount,
        ];
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
        }

        return [];
    }
}
