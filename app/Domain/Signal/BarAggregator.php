<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;
use Illuminate\Support\Facades\Redis;

/**
 * K 线聚合器。
 *
 * 按 1m/5m/15m/1h/4h/1d 周期，把交易所 WS 推来的 1m 已闭 bar 聚合为高周期 bar。
 *
 * 关闭逻辑（严格按交易所 openTime 分桶，架构 §9.6 不用本地时钟）：
 *   bucket = floor(openTime / periodMs) * periodMs
 *   - 当前无未闭 bar 或输入 bar 落入新桶 → 旧桶闭合返回，同时以输入 bar 开新桶
 *   - 落入同一桶 → 并入 OHLCV（high 取大/low 取小/close 覆盖/量累加），返回 null
 *
 * 崩溃恢复：未闭 bar 以 JSON 存 Redis String
 *   key = market:agg:{exchange}:{symbol}:{interval}
 * 进程重启后第一次 aggregate 自动 hydrate（P1 坑 9：不借用业务字段做恢复守卫，
 * Redis 本身即持久状态，读到什么就是什么，无需额外标志）。
 *
 * interval=1m 直通返回（不聚合、不写状态）。
 */
class BarAggregator
{
    private const STATE_PREFIX = 'market:agg';

    /** 周期 → 毫秒 */
    private const PERIOD_MS = [
        '1m' => 60_000,
        '5m' => 300_000,
        '15m' => 900_000,
        '1h' => 3_600_000,
        '4h' => 14_400_000,
        '1d' => 86_400_000,
    ];

    /** 周期闭合所需的 1m bar 数（残次桶判定：启动/断线期间漏桶则 ticks 不足） */
    public const EXPECTED_TICKS = [
        '5m' => 5,
        '15m' => 15,
        '1h' => 60,
        '4h' => 240,
        '1d' => 1440,
    ];

    /** key = exchange:symbol:interval；最近一次闭合桶实际聚合到的 1m bar 数 */
    private array $lastClosedTicks = [];

    /**
     * 聚合 1m bar 到指定周期。
     *
     * @param ExchangeKline $bar      输入的最新 1m 已闭 bar
     * @param string        $interval 目标周期 1m|5m|15m|1h|4h|1d
     *
     * @return ExchangeKline|null 跨桶时返回上一根已闭合的目标周期 bar，否则 null
     *                            （注意：进程中途启动/断线导致的残次桶同样返回，
     *                            调用方需用 lastClosedTicks() 判定是否完整，
     *                            残次桶不得写 kline_bars/触发信号）
     */
    public function aggregate(ExchangeKline $bar, string $interval): ?ExchangeKline
    {
        if ($interval === '1m') {
            return $bar;
        }

        $periodMs = self::PERIOD_MS[$interval]
            ?? throw new \InvalidArgumentException("Unsupported aggregate interval: {$interval}");

        $bucketStart = intdiv($bar->openTime, $periodMs) * $periodMs;
        $bucketClose = $bucketStart + $periodMs - 1;

        $stateKey = $this->key($bar->exchangeCode, $bar->symbol, $interval);
        $state = $this->load($bar->exchangeCode, $bar->symbol, $interval);
        $closed = null;

        if ($state !== null && (int) $state['open_time'] !== $bucketStart) {
            // 跨桶：先闭合旧 bar，并记录本桶实际收到的 1m bar 数
            $this->lastClosedTicks[$stateKey] = (int) ($state['ticks'] ?? 0);
            $closed = $this->toKline($bar->exchangeCode, $bar->symbol, $interval, $state);
            $state = null;
        }

        if ($state === null) {
            $state = [
                'open_time' => $bucketStart,
                'close_time' => $bucketClose,
                'open' => $bar->open,
                'high' => $bar->high,
                'low' => $bar->low,
                'close' => $bar->close,
                'volume' => $bar->volume,
                'quote_volume' => $bar->quoteVolume,
                'trades_count' => $bar->tradesCount,
                'ticks' => 1,
            ];
        } else {
            $state['high'] = (string) max((float) $state['high'], (float) $bar->high);
            $state['low'] = (string) min((float) $state['low'], (float) $bar->low);
            $state['close'] = $bar->close;
            $state['volume'] = bcadd((string) $state['volume'], $bar->volume, 8);
            $state['quote_volume'] = bcadd((string) $state['quote_volume'], $bar->quoteVolume, 8);
            $state['trades_count'] = (int) $state['trades_count'] + $bar->tradesCount;
            $state['ticks'] = (int) ($state['ticks'] ?? 0) + 1;
        }

        $this->save($bar->exchangeCode, $bar->symbol, $interval, $state);

        return $closed;
    }

    /**
     * 最近一次闭合桶实际聚合到的 1m bar 数（aggregate() 返回闭合 bar 后立即查询）。
     * 小于 EXPECTED_TICKS[interval] 说明桶内有缺失（启动晚/WS 断线），为残次桶。
     */
    public function lastClosedTicks(string $exchange, string $symbol, string $interval): int
    {
        return $this->lastClosedTicks[$this->key($exchange, $symbol, $interval)] ?? 0;
    }

    // ------------------------------------------------------------------
    // Redis 状态
    // ------------------------------------------------------------------

    private function key(string $exchange, string $symbol, string $interval): string
    {
        return sprintf('%s:%s:%s:%s', self::STATE_PREFIX, $exchange, $symbol, $interval);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function load(string $exchange, string $symbol, string $interval): ?array
    {
        $raw = Redis::connection('default')->get($this->key($exchange, $symbol, $interval));
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function save(string $exchange, string $symbol, string $interval, array $state): void
    {
        // TTL 给 2 天，异常残留 key 可自愈；正常每分钟刷新
        Redis::connection('default')->set(
            $this->key($exchange, $symbol, $interval),
            json_encode($state, JSON_UNESCAPED_UNICODE),
            'EX',
            172_800,
        );
    }

    /**
     * @param array<string,mixed> $state
     */
    private function toKline(string $exchange, string $symbol, string $interval, array $state): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: $exchange,
            symbol: $symbol,
            interval: $interval,
            openTime: (int) $state['open_time'],
            closeTime: (int) $state['close_time'],
            open: (string) $state['open'],
            high: (string) $state['high'],
            low: (string) $state['low'],
            close: (string) $state['close'],
            volume: (string) $state['volume'],
            quoteVolume: (string) $state['quote_volume'],
            tradesCount: (int) $state['trades_count'],
        );
    }
}
