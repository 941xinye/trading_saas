<?php

declare(strict_types=1);

namespace App\Domain\Liquidation;

use App\Domain\Exchange\Contracts\LiquidationEvent;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 强平密集区聚类器（基于 Redis ZSET + HASH）。
 *
 * 数据结构（key 前缀来自 config/liquidation.php 的 redis_prefix，默认 'liq'）：
 *
 *   liq:z:{ex}:{sym}:{side}:{window}    ZSET    score=ts_ms  member=eid
 *   liq:b:{ex}:{sym}:{side}:{window}    HASH    field=bucketIndex  value=cumulative_qty
 *   liq:bc:{ex}:{sym}:{side}:{window}   HASH    field=bucketIndex  value=event_count
 *   liq:eid:{eid}                       STRING  ttl=2×window  value="{ex}:{sym}:{side}:{window}:{bucket}:{qty}"
 *
 * 设计要点：
 *  - eid（32 字符 hex）作为 ZSET member，避免 member JSON 反复解析；反向索引用于 eviction 时回退 HASH
 *  - 惰性 eviction：snapshot 查询前先 evictExpired 清过期事件
 *  - 周期 eviction 由 LiquidationFeedRunner 协程内 60s 触发一次，保证高频场景下 HASH 不无限膨胀
 *  - 全部写入用 pipeline 批量，单事件失败 try/catch 不阻断主循环
 *  - TTL = window_seconds × ttl_multiplier（默认 2×，给 eviction 留缓冲）
 *
 * 注意：bucket 减到 qty=0 或 count=0 时自动 HDEL，避免 HASH 残留空桶。
 */
class LiquidationClusterer
{
    public function __construct(
        private readonly LiquidationBucketPolicy $policy = new LiquidationBucketPolicy(),
    ) {
    }

    /**
     * 写入一个强平事件到指定窗口的 Redis 结构。
     *
     * @param string $window 5m|15m|60m
     * @param ?string $eid   测试注入固定 eid（生产环境留空，自动生成 32 字符 hex）
     * @return string 实际使用的 eid（用于 POC 断言）
     */
    public function ingest(LiquidationEvent $e, string $window, ?string $eid = null): string
    {
        $eid ??= bin2hex(random_bytes(16));
        $bucketIndex = $this->policy->bucketIndex($e->price, $this->policy->bucketWidth($e->symbol));

        $cfg = $this->windowCfg($window);
        $ttl = $cfg['seconds'] * $cfg['ttl_multiplier'];
        $zsetKey = $this->zsetKey($e->exchangeCode, $e->symbol, $e->side, $window);
        $bucketKey = $this->bucketHashKey($e->exchangeCode, $e->symbol, $e->side, $window);
        $countKey = $this->countHashKey($e->exchangeCode, $e->symbol, $e->side, $window);
        $eidKey = $this->eidKey($eid);

        $eidValue = implode('|', [
            $e->exchangeCode, $e->symbol, $e->side, $window, $bucketIndex, $e->qty,
        ]);

        $redis = Redis::connection('default');
        $redis->pipeline(function ($pipe) use (
            $zsetKey, $eid, $e, $bucketKey, $bucketIndex, $countKey, $eidKey, $eidValue, $ttl,
        ) {
            // phpredis zAdd 签名：zAdd($key, $score, $member)（score 在前，member 在后）
            $pipe->zadd($zsetKey, $e->tsMs, $eid);
            $pipe->hIncrByFloat($bucketKey, $bucketIndex, (float) $e->qty);
            $pipe->hIncrBy($countKey, $bucketIndex, 1);
            $pipe->setex($eidKey, $ttl, $eidValue);
            $pipe->expire($zsetKey, $ttl);
            $pipe->expire($bucketKey, $ttl);
            $pipe->expire($countKey, $ttl);
        });

        return $eid;
    }

    /**
     * 清理过期事件（惰性 eviction）。
     *
     * 流程：取 ZSET score < (now - window_ms) 的 eid → 反解 HASH 减回去 → ZREM + DEL eid。
     * 注意：zRemRangeByScore 直接删除元素但拿不到 eid，必须先 zRangeByScore 取列表再 zRem。
     *
     * @return int 清理的事件数
     */
    public function evictExpired(string $exchange, string $symbol, string $side, string $window): int
    {
        $cfg = $this->windowCfg($window);
        $cutoffMs = (int) (microtime(true) * 1000) - $cfg['seconds'] * 1000;
        $zsetKey = $this->zsetKey($exchange, $symbol, $side, $window);

        $redis = Redis::connection('default');
        $expired = $redis->zRangeByScore($zsetKey, '-inf', (string) $cutoffMs);
        if (! is_array($expired) || $expired === []) {
            return 0;
        }

        $bucketKey = $this->bucketHashKey($exchange, $symbol, $side, $window);
        $countKey = $this->countHashKey($exchange, $symbol, $side, $window);
        $cleaned = 0;

        foreach ($expired as $eid) {
            $eidKey = $this->eidKey($eid);
            $raw = $redis->get($eidKey);
            if (! is_string($raw) || $raw === '') {
                // eid 反向索引已过期/丢失：直接 ZREM，HASH 不修复（误差容忍，后续可重建）
                $redis->zRem($zsetKey, $eid);
                continue;
            }

            $parts = explode('|', $raw);
            // [exchange, symbol, side, window, bucketIndex, qty]
            if (count($parts) < 6) {
                $redis->zRem($zsetKey, $eid);
                $redis->del($eidKey);
                continue;
            }
            $bucketIndex = $parts[4];
            $qty = $parts[5];

            // 减回去（HINCRBYFLOAT 负数 + HINCRBY 负数）
            $redis->hIncrByFloat($bucketKey, $bucketIndex, -((float) $qty));
            $redis->hIncrBy($countKey, $bucketIndex, -1);

            // 桶 qty 或 count 归 0 自动清理（避免 HASH 残留空桶）
            $remainQty = (float) $redis->hGet($bucketKey, $bucketIndex);
            $remainCount = (int) $redis->hGet($countKey, $bucketIndex);
            if ($remainQty <= 0.0 || $remainCount <= 0) {
                $redis->hDel($bucketKey, $bucketIndex);
                $redis->hDel($countKey, $bucketIndex);
            }

            $redis->zRem($zsetKey, $eid);
            $redis->del($eidKey);
            $cleaned++;
        }

        return $cleaned;
    }

    /**
     * 取密集区快照（先 evictExpired 后读 HASH）。
     *
     * @return array<int,array{bucket_index:string, center:string, from:string, to:string, total_qty:string, event_count:int}>
     */
    public function snapshot(string $exchange, string $symbol, string $side, string $window): array
    {
        $this->evictExpired($exchange, $symbol, $side, $window);

        $bucketKey = $this->bucketHashKey($exchange, $symbol, $side, $window);
        $countKey = $this->countHashKey($exchange, $symbol, $side, $window);

        $redis = Redis::connection('default');
        $buckets = $redis->hGetAll($bucketKey);
        $counts = $redis->hGetAll($countKey);
        if (! is_array($buckets) || $buckets === []) {
            return [];
        }

        $bw = $this->policy->bucketWidth($symbol);
        $rows = [];
        foreach ($buckets as $bucketIndex => $qty) {
            $count = (int) ($counts[$bucketIndex] ?? 0);
            [$from, $to] = $this->policy->bucketRange((string) $bucketIndex, $bw);
            $rows[] = [
                'bucket_index' => (string) $bucketIndex,
                'center' => $this->policy->bucketCenter((string) $bucketIndex, $bw),
                'from' => $from,
                'to' => $to,
                'total_qty' => (string) $qty,
                'event_count' => $count,
            ];
        }

        // 按 total_qty desc 排序
        usort($rows, fn ($a, $b) => bccomp($b['total_qty'], $a['total_qty'], 8));

        return $rows;
    }

    /**
     * 取 N 个窗口的快照聚合（跨窗口去重不取，按窗口分桶返回）。
     *
     * @param string[] $windows
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function snapshotsFor(string $exchange, string $symbol, string $side, array $windows): array
    {
        $out = [];
        foreach ($windows as $w) {
            $out[$w] = $this->snapshot($exchange, $symbol, $side, $w);
        }

        return $out;
    }

    /**
     * 清空指定窗口的所有 Redis 数据（POC 测试用）。
     */
    public function clearWindow(string $exchange, string $symbol, string $side, string $window): void
    {
        $redis = Redis::connection('default');
        $zsetKey = $this->zsetKey($exchange, $symbol, $side, $window);
        $eids = $redis->zRange($zsetKey, 0, -1);
        if (is_array($eids)) {
            foreach ($eids as $eid) {
                $redis->del($this->eidKey($eid));
            }
        }
        $redis->del([
            $zsetKey,
            $this->bucketHashKey($exchange, $symbol, $side, $window),
            $this->countHashKey($exchange, $symbol, $side, $window),
        ]);
    }

    // ------------------------------------------------------------------
    // Key 与配置助手
    // ------------------------------------------------------------------

    private function prefix(): string
    {
        return (string) config('liquidation.redis_prefix', 'liq');
    }

    private function zsetKey(string $ex, string $sym, string $side, string $window): string
    {
        $p = $this->prefix();

        return "{$p}:z:{$ex}:{$sym}:{$side}:{$window}";
    }

    private function bucketHashKey(string $ex, string $sym, string $side, string $window): string
    {
        $p = $this->prefix();

        return "{$p}:b:{$ex}:{$sym}:{$side}:{$window}";
    }

    private function countHashKey(string $ex, string $sym, string $side, string $window): string
    {
        $p = $this->prefix();

        return "{$p}:bc:{$ex}:{$sym}:{$side}:{$window}";
    }

    private function eidKey(string $eid): string
    {
        $p = $this->prefix();

        return "{$p}:eid:{$eid}";
    }

    /**
     * @return array{seconds:int, ttl_multiplier:int}
     */
    private function windowCfg(string $window): array
    {
        $cfg = config("liquidation.windows.{$window}");
        if (! is_array($cfg)) {
            return ['seconds' => 300, 'ttl_multiplier' => 2];
        }

        return [
            'seconds' => (int) ($cfg['seconds'] ?? 300),
            'ttl_multiplier' => (int) ($cfg['ttl_multiplier'] ?? 2),
        ];
    }
}
