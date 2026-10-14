<?php

declare(strict_types=1);

namespace Tests\Integration\Liquidation;

use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * LiquidationClusterer Redis 聚类集成测试（迁移自 poc:verify-liquidation 断言 #4-#13）。
 *
 * @group redis：本机 Redis 关闭时 requireRedis() 自动 skip（见 ChecksRedisAvailability）。
 */
#[Group('redis')]
class LiquidationClustererTest extends TestCase
{
    use LiquidationRedisFixture;

    public function test_ingest_single_event_increments_zcard_to_one(): void
    {
        // 旧断言 #4：单条写入后 ZCARD=1
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_BTC, 'BUY', '60000', '0.5'),
            '5m',
            eid: 'phpunit-eid-1',
        );

        static::assertSame(1, $this->zCard(self::LIQ_SYMBOL_BTC, 'BUY', '5m'));
    }

    public function test_ingest_batch_1000_events_increments_zcard_to_1000(): void
    {
        // 旧断言 #5：批量 1000 条同桶写入后 ZCARD=1000
        $baseTs = (int) (microtime(true) * 1000);
        for ($i = 0; $i < 1000; $i++) {
            $this->clusterer->ingest(
                $this->makeEvent(self::LIQ_SYMBOL_BTC, 'SELL', '60000', '0.001', $baseTs + $i),
                '5m',
                eid: 'phpunit-eid-batch-'.$i,
            );
        }

        static::assertSame(1000, $this->zCard(self::LIQ_SYMBOL_BTC, 'SELL', '5m'));
    }

    public function test_snapshot_returns_rows_with_six_required_fields(): void
    {
        // 旧断言 #6：snapshot 结构含 bucket_index/center/from/to/total_qty/event_count
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_BTC, 'SELL', '60000', '0.001'),
            '5m',
            eid: 'phpunit-eid-struct',
        );

        $snap = $this->clusterer->snapshot(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_BTC, 'SELL', '5m');

        static::assertCount(1, $snap);
        $first = $snap[0];
        foreach (['bucket_index', 'center', 'from', 'to', 'total_qty', 'event_count'] as $field) {
            static::assertArrayHasKey($field, $first);
        }
        static::assertSame('1000', $first['bucket_index']);
        static::assertSame(1, $first['event_count']);
    }

    public function test_snapshot_is_sorted_by_total_qty_desc(): void
    {
        // 旧断言 #7：三个桶数量 1/5/3，快照必须按 total_qty desc（5 → 3 → 1）
        foreach ([['60000', '1'], ['60120', '5'], ['60240', '3']] as $i => [$price, $qty]) {
            $this->clusterer->ingest(
                $this->makeEvent(self::LIQ_SYMBOL_BTC, 'SELL', $price, $qty),
                '5m',
                eid: 'phpunit-eid-sort-'.$i,
            );
        }

        $snap = $this->clusterer->snapshot(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_BTC, 'SELL', '5m');

        static::assertCount(3, $snap);
        static::assertSame(0, bccomp($snap[0]['total_qty'], '5', 8));
        static::assertSame(0, bccomp($snap[1]['total_qty'], '3', 8));
        static::assertSame(0, bccomp($snap[2]['total_qty'], '1', 8));
    }

    public function test_bucket_qty_and_count_equal_sum_and_number_of_events(): void
    {
        // 旧断言 #8：1000 × 0.001 同桶 → HASH qty 累计 1，event_count=1000
        $baseTs = (int) (microtime(true) * 1000);
        for ($i = 0; $i < 1000; $i++) {
            $this->clusterer->ingest(
                $this->makeEvent(self::LIQ_SYMBOL_BTC, 'SELL', '60000', '0.001', $baseTs + $i),
                '5m',
                eid: 'phpunit-eid-consist-'.$i,
            );
        }

        $bucket1000 = $this->findBucket(
            $this->clusterer->snapshot(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_BTC, 'SELL', '5m'),
            '1000',
        );

        static::assertNotNull($bucket1000);
        // HINCRBYFLOAT 浮点累加允许低位误差，scale 6 比较
        static::assertSame(0, bccomp($bucket1000['total_qty'], '1', 6));
        static::assertSame(1000, $bucket1000['event_count']);
    }

    public function test_evict_expired_deletes_empty_bucket_hash_field(): void
    {
        // 旧断言 #9：过期事件被剔除后空桶 HDEL 自动清理
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_BTC, 'BUY', '60000', '0.5', 1700000000000),
            '5m',
            eid: 'phpunit-eid-evict-1',
        );

        $cleaned = $this->clusterer->evictExpired(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_BTC, 'BUY', '5m');

        $remain = Redis::connection('default')->hGetAll(
            $this->bucketHashKey(self::LIQ_SYMBOL_BTC, 'BUY', '5m'),
        );

        static::assertSame(1, $cleaned);
        static::assertSame([], $remain);
    }

    public function test_evict_expired_reduces_zcard_to_zero(): void
    {
        // 旧断言 #10：5 条过期事件 evict 后 ZCARD 5 → 0
        foreach (range(0, 4) as $i) {
            $this->clusterer->ingest(
                $this->makeEvent(self::LIQ_SYMBOL_BTC, 'BUY', '60000', '0.001', 1700000000000 + $i),
                '15m',
                eid: 'phpunit-eid-evict-reduce-'.$i,
            );
        }

        $before = $this->zCard(self::LIQ_SYMBOL_BTC, 'BUY', '15m');
        $cleaned = $this->clusterer->evictExpired(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_BTC, 'BUY', '15m');
        $after = $this->zCard(self::LIQ_SYMBOL_BTC, 'BUY', '15m');

        static::assertSame(5, $before);
        static::assertSame(5, $cleaned);
        static::assertSame(0, $after);
    }

    public function test_three_windows_keep_independent_zsets(): void
    {
        // 旧断言 #11：同 symbol 同 side，5m/15m/60m 三档窗口不串扰
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_ETH, 'SELL', '3000', '1.0'),
            '5m',
            eid: 'phpunit-wiso-5m',
        );
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_ETH, 'SELL', '3000', '2.0'),
            '15m',
            eid: 'phpunit-wiso-15m',
        );
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_ETH, 'SELL', '3000', '4.0'),
            '60m',
            eid: 'phpunit-wiso-60m',
        );

        $c5 = $this->clusterer->snapshot(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_ETH, 'SELL', '5m');
        $c15 = $this->clusterer->snapshot(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_ETH, 'SELL', '15m');
        $c60 = $this->clusterer->snapshot(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_ETH, 'SELL', '60m');

        static::assertCount(1, $c5);
        static::assertCount(1, $c15);
        static::assertCount(1, $c60);
        static::assertSame(0, bccomp($c5[0]['total_qty'], '1', 8));
        static::assertSame(0, bccomp($c15[0]['total_qty'], '2', 8));
        static::assertSame(0, bccomp($c60[0]['total_qty'], '4', 8));
    }

    public function test_buy_and_sell_sides_are_isolated(): void
    {
        // 旧断言 #12：同 symbol 同 window，BUY/SELL 不混淆
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_BTC, 'BUY', '60000', '1.0'),
            '5m',
            eid: 'phpunit-siso-buy',
        );
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_BTC, 'SELL', '60000', '2.0'),
            '5m',
            eid: 'phpunit-siso-sell',
        );

        $buy = $this->clusterer->snapshot(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_BTC, 'BUY', '5m');
        $sell = $this->clusterer->snapshot(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_BTC, 'SELL', '5m');

        static::assertCount(1, $buy);
        static::assertCount(1, $sell);
        static::assertSame(0, bccomp($buy[0]['total_qty'], '1', 8));
        static::assertSame(0, bccomp($sell[0]['total_qty'], '2', 8));
    }

    public function test_different_symbols_are_isolated(): void
    {
        // 旧断言 #13：跨 symbol（BTC/ETH 合成替身）不混淆
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_BTC, 'SELL', '60000', '1.0'),
            '5m',
            eid: 'phpunit-sym-btc',
        );
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_ETH, 'SELL', '3000', '10.0'),
            '5m',
            eid: 'phpunit-sym-eth',
        );

        $btc = $this->clusterer->snapshot(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_BTC, 'SELL', '5m');
        $eth = $this->clusterer->snapshot(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_ETH, 'SELL', '5m');

        static::assertCount(1, $btc);
        static::assertCount(1, $eth);
        static::assertSame(0, bccomp($btc[0]['total_qty'], '1.0', 8));
        static::assertSame(0, bccomp($eth[0]['total_qty'], '10.0', 8));
    }

    /**
     * @param  array<int, array{bucket_index:string, total_qty:string, event_count:int}>  $snap
     * @return array{bucket_index:string, total_qty:string, event_count:int}|null
     */
    private function findBucket(array $snap, string $bucketIndex): ?array
    {
        foreach ($snap as $bucket) {
            if ($bucket['bucket_index'] === $bucketIndex) {
                return $bucket;
            }
        }

        return null;
    }
}
