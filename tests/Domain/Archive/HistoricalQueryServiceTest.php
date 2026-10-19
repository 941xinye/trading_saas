<?php

declare(strict_types=1);

namespace Tests\Domain\Archive;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\ArchiveStore;
use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;
use App\Domain\Archive\Exceptions\ArchiveQueryLimitException;
use App\Domain\Archive\Services\HistoricalQueryService;
use Illuminate\Support\Facades\DB;
use Tests\Domain\Archive\Support\FakeArchiveStore;
use Tests\TestCase;

/**
 * HistoricalQueryService 冷热路由/归并去重/游标分页/上限与参数校验（spec 004 T017/T018，
 * contracts/historical-query-contract.md §2/§3，R-007）。
 *
 * fake ArchiveStore + 真实 Eloquent 热表（SQLite :memory:）。
 * 路由基准：archive_watermarks.applied_watermark = 2026-10-01 00:00:00。
 */
final class HistoricalQueryServiceTest extends TestCase
{
    private FakeArchiveStore $store;

    private HistoricalQueryService $service;

    private const W = '2026-10-01 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new FakeArchiveStore;
        $this->app->instance(ArchiveStore::class, $this->store);
        $this->service = new HistoricalQueryService($this->store);
        DB::table('archive_watermarks')->insert([
            'data_type' => 'kline_bar',
            'copied_watermark' => self::W,
            'applied_watermark' => self::W,
            'created_at' => '2026-10-02 00:00:00',
            'updated_at' => '2026-10-02 00:00:00',
        ]);
    }

    // ------------------------------------------------------------------
    // T017 路由与归并
    // ------------------------------------------------------------------

    public function test_pure_cold_routes_to_store_only(): void
    {
        $this->seedColdKline(1, '2026-09-10 00:00:00');
        $this->seedColdKline(2, '2026-09-20 00:00:00');
        $this->seedHotKline(3, '2026-10-05 00:00:00'); // 热侧有数但不在范围

        $hotQueries = $this->countHotQueries(function (): void {
            // to == W → 纯冷
            $result = $this->service->query($this->req('2026-09-01 00:00:00', self::W));
            static::assertSame(HistoricalQueryResult::COLD, $result->servedFrom);
            static::assertSame(['1', '2'], array_column($result->rows, 'id'));
            static::assertNull($result->nextPageCursor);
        });

        static::assertSame(0, $hotQueries, '纯冷查询不应触碰热侧');
        static::assertCount(1, $this->store->fetchRowsCalls, '纯冷查询应调用 store 一次');
    }

    public function test_pure_hot_short_circuits_store(): void
    {
        $this->seedColdKline(1, '2026-09-10 00:00:00'); // 冷侧有数但不在范围
        $this->seedHotKline(10, '2026-10-05 00:00:00');
        $this->seedHotKline(11, '2026-10-06 00:00:00');

        // from == W → 纯热
        $result = $this->service->query($this->req(self::W, '2026-11-01 00:00:00'));

        static::assertSame(HistoricalQueryResult::HOT, $result->servedFrom);
        static::assertSame([], $this->store->fetchRowsCalls, '纯热短路：store 零调用（R-007）');
        static::assertSame(['10', '11'], array_column($result->rows, 'id'));

        // 行形态：投影 = canonicalColumns（无审计列），值全字符串（R-004）
        static::assertSame('100.00000000', $result->rows[0]['open']);
        static::assertSame('2026-10-05 00:00:00', $result->rows[0]['open_time']);
        static::assertArrayNotHasKey('batch_id', $result->rows[0]);
        static::assertArrayNotHasKey('archived_at', $result->rows[0]);
    }

    public function test_merged_crossing_watermark_merges_sorted_and_dedupes_inflight(): void
    {
        // 冷侧：已 applied 行（时间 < W）+ 一行 in-flight（时间 >= W，已 copy 未删，与主库同现）
        $this->seedColdKline(1, '2026-09-10 00:00:00');
        $this->seedColdKline(2, '2026-09-15 00:00:00');
        $this->seedColdKline(100, '2026-10-05 00:00:00', 9); // in-flight 批次 9
        // 热侧：同一 in-flight 行（id=100）+ 纯热行；100/101 同时间戳验证 (时间, id) 次序
        //（uniq(exchange,symbol,interval,open_time) 约束下同时间戳需不同 symbol）
        $this->seedHotKline(100, '2026-10-05 00:00:00');
        $this->seedHotKline(101, '2026-10-05 00:00:00', 'ETHUSDT');
        $this->seedHotKline(102, '2026-10-20 00:00:00');

        $result = $this->service->query($this->req('2026-09-01 00:00:00', '2026-11-01 00:00:00'));

        static::assertSame(HistoricalQueryResult::MERGED, $result->servedFrom);
        static::assertNotEmpty($this->store->fetchRowsCalls, '跨界应查冷侧');
        static::assertSame(
            ['1', '2', '100', '101', '102'],
            array_column($result->rows, 'id'),
            '(时间列, id) 归并升序 + in-flight 同 id 去重保留一条',
        );
    }

    // ------------------------------------------------------------------
    // T018 分页 / 上限 / 游标 / 参数校验
    // ------------------------------------------------------------------

    public function test_cursor_pagination_exhausts_all_rows_without_dup_or_loss(): void
    {
        // 12 冷（09-05 起逐日）+ 13 热（10-05 起逐日），pageSize=10
        for ($i = 0; $i < 12; $i++) {
            $this->seedColdKline($i + 1, sprintf('2026-09-%02d 00:00:00', 5 + $i));
        }
        for ($i = 0; $i < 13; $i++) {
            $this->seedHotKline(20 + $i, sprintf('2026-10-%02d 00:00:00', 5 + $i));
        }

        $seen = [];
        $pageCounts = [];
        $cursor = null;
        do {
            $result = $this->service->query($this->req('2026-09-01 00:00:00', '2026-11-01 00:00:00', 10, $cursor));
            $pageCounts[] = count($result->rows);
            foreach ($result->rows as $row) {
                $seen[] = $row['id'];
            }
            $cursor = $result->nextPageCursor;
        } while ($cursor !== null);

        $expected = array_map('strval', array_merge(range(1, 12), range(20, 32)));
        static::assertSame([10, 10, 5], $pageCounts, '页大小 10/10/5，nextPageCursor=null 终止');
        static::assertSame($expected, $seen, '翻页全量拉取不重不漏、跨页续序');
        static::assertSame($expected, array_unique($seen));
    }

    public function test_page_size_over_limit_throws(): void
    {
        $this->expectException(ArchiveQueryLimitException::class);
        $this->service->query($this->req('2026-09-01 00:00:00', '2026-11-01 00:00:00', 50001));
    }

    public function test_malformed_cursor_throws(): void
    {
        foreach (['!!!not-base64!!!', base64_encode('{"x":1}'), base64_encode('["2026-10-01 00:00:00"]')] as $bad) {
            try {
                $this->service->query($this->req('2026-09-01 00:00:00', '2026-11-01 00:00:00', null, $bad));
                static::fail('非法游标应抛 ArchiveQueryLimitException：' . $bad);
            } catch (ArchiveQueryLimitException $e) {
                static::assertInstanceOf(ArchiveQueryLimitException::class, $e);
            }
        }
    }

    public function test_filter_applicability_validation(): void
    {
        $from = new \DateTimeImmutable('2026-09-01 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-11-01 00:00:00', new \DateTimeZone('UTC'));

        // from >= to
        $this->assertInvalid(static fn () => new HistoricalQueryRequest(ArchiveDataType::KlineBar, $to, $from));
        // interval 仅 KlineBar
        $this->assertInvalid(static fn () => new HistoricalQueryRequest(ArchiveDataType::Signal, $from, $to, interval: '5m'));
        $this->assertInvalid(static fn () => new HistoricalQueryRequest(ArchiveDataType::LiquidationEvent, $from, $to, interval: '5m'));
        // exchangeCode 仅 KlineBar / LiquidationEvent
        $this->assertInvalid(static fn () => new HistoricalQueryRequest(ArchiveDataType::Signal, $from, $to, exchangeCode: 'binance'));
        $this->assertInvalid(static fn () => new HistoricalQueryRequest(ArchiveDataType::StrategyOrder, $from, $to, exchangeCode: 'binance'));
        // 合法组合不抛
        static::assertInstanceOf(HistoricalQueryRequest::class, new HistoricalQueryRequest(ArchiveDataType::LiquidationEvent, $from, $to, symbol: 'BTCUSDT', exchangeCode: 'binance'));
        static::assertInstanceOf(HistoricalQueryRequest::class, new HistoricalQueryRequest(ArchiveDataType::KlineBar, $from, $to, symbol: 'BTCUSDT', exchangeCode: 'binance', interval: '1m'));
    }

    public function test_container_binding_resolves_service(): void
    {
        static::assertInstanceOf(HistoricalQueryService::class, $this->app->make(HistoricalQuery::class));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function req(string $from, string $to, ?int $pageSize = null, ?string $cursor = null): HistoricalQueryRequest
    {
        return new HistoricalQueryRequest(
            dataType: ArchiveDataType::KlineBar,
            fromUtc: new \DateTimeImmutable($from, new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable($to, new \DateTimeZone('UTC')),
            pageSize: $pageSize,
            cursor: $cursor,
        );
    }

    private function seedHotKline(int $id, string $openTime, string $symbol = 'BTCUSDT'): void
    {
        DB::table('kline_bars')->insert([
            'id' => $id,
            'exchange_code' => 'binance', 'symbol' => $symbol, 'interval' => '1m',
            'open_time' => $openTime, 'close_time' => $openTime,
            'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100.5',
            'volume' => '1', 'quote_volume' => '100.5', 'trades_count' => 3,
            'created_at' => $openTime, 'updated_at' => $openTime,
        ]);
    }

    private function seedColdKline(int $id, string $openTime, int $batchId = 1): void
    {
        $this->store->tables['ch_kline_bars'][] = [
            'id' => (string) $id,
            'exchange_code' => 'binance', 'symbol' => 'BTCUSDT', 'interval' => '1m',
            'open_time' => $openTime, 'close_time' => $openTime,
            'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100.5',
            'volume' => '1', 'quote_volume' => '100.5', 'trades_count' => '3',
            'created_at' => $openTime, 'updated_at' => $openTime,
            'batch_id' => (string) $batchId, 'archived_at' => '2026-10-02 00:00:00',
        ];
    }

    /** 统计热表查询次数（纯冷路由断言「热侧零查询」用） */
    private function countHotQueries(callable $fn): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (str_contains($query->sql, 'kline_bars')) {
                $count++;
            }
        });
        $fn();

        return $count;
    }

    private function assertInvalid(callable $fn): void
    {
        try {
            $fn();
            static::fail('应抛 InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // 预期
        }
    }
}
