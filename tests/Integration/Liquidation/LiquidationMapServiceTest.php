<?php

declare(strict_types=1);

namespace Tests\Integration\Liquidation;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * LiquidationMapService 密集区查询集成测试（迁移自 poc:verify-liquidation 断言 #14、#15）。
 *
 * @group redis：本机 Redis 关闭时 requireRedis() 自动 skip。
 */
#[Group('redis')]
class LiquidationMapServiceTest extends TestCase
{
    use LiquidationRedisFixture;

    public function test_query_truncates_rows_to_top_n(): void
    {
        // 旧断言 #14：15 个不同桶（每 100 USDT 一个价格，bw=60 必落不同桶），topN=10 截断
        for ($i = 0; $i < 15; $i++) {
            $price = bcadd('60000', bcmul((string) $i, '100', 8), 8);
            $this->clusterer->ingest(
                $this->makeEvent(self::LIQ_SYMBOL_BTC, 'SELL', $price, '0.001'),
                '5m',
                eid: 'phpunit-topn-'.$i,
            );
        }

        $rows = $this->mapService->query(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_BTC, '5m', topN: 10);

        static::assertCount(10, $rows);
    }

    public function test_distance_pct_is_positive_above_price_and_negative_below(): void
    {
        // 旧断言 #15：现价 60000，桶心 60600（上方）→ +1%，桶心 59400（下方）→ -1%
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_BTC, 'SELL', '60600', '1.0'),
            '5m',
            eid: 'phpunit-dist-up',
        );
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_BTC, 'SELL', '59400', '1.0'),
            '5m',
            eid: 'phpunit-dist-down',
        );

        $rows = $this->mapService->query(
            self::LIQ_EXCHANGE,
            self::LIQ_SYMBOL_BTC,
            '5m',
            topN: 10,
            currentPrice: '60000',
        );

        $up = $this->findRowByCenter($rows, '60600');
        $down = $this->findRowByCenter($rows, '59400');

        static::assertNotNull($up);
        static::assertNotNull($down);
        static::assertSame(1, bccomp((string) $up['distance_pct'], '0', 4));
        static::assertSame(-1, bccomp((string) $down['distance_pct'], '0', 4));
        static::assertSame(0, bccomp((string) $up['distance_pct'], '1.0000', 4));
        static::assertSame(0, bccomp((string) $down['distance_pct'], '-1.0000', 4));
    }

    public function test_distance_pct_is_null_without_current_price(): void
    {
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_BTC, 'SELL', '60600', '1.0'),
            '5m',
            eid: 'phpunit-dist-null',
        );

        $rows = $this->mapService->query(
            self::LIQ_EXCHANGE,
            self::LIQ_SYMBOL_BTC,
            '5m',
            topN: 10,
            currentPrice: null,
        );

        static::assertCount(1, $rows);
        static::assertNull($rows[0]['distance_pct']);
    }

    public function test_query_merges_both_sides_and_sorts_by_total_qty_desc(): void
    {
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_BTC, 'BUY', '60000', '1.0'),
            '5m',
            eid: 'phpunit-merge-buy',
        );
        $this->clusterer->ingest(
            $this->makeEvent(self::LIQ_SYMBOL_BTC, 'SELL', '60000', '3.0'),
            '5m',
            eid: 'phpunit-merge-sell',
        );

        $rows = $this->mapService->query(self::LIQ_EXCHANGE, self::LIQ_SYMBOL_BTC, '5m', topN: 10);

        static::assertCount(2, $rows);
        static::assertSame(0, bccomp($rows[0]['total_qty'], '3', 8));
        static::assertSame('SELL', $rows[0]['side']);
        static::assertSame(0, bccomp($rows[1]['total_qty'], '1', 8));
        static::assertSame('BUY', $rows[1]['side']);
    }

    /**
     * @param  array<int, array{bucket_center:string, distance_pct:?string, side:string, total_qty:string}>  $rows
     * @return array{bucket_center:string, distance_pct:?string, side:string, total_qty:string}|null
     */
    private function findRowByCenter(array $rows, string $center): ?array
    {
        foreach ($rows as $row) {
            if (bccomp($row['bucket_center'], $center, 8) === 0) {
                return $row;
            }
        }

        return null;
    }
}
