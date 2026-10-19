<?php

declare(strict_types=1);

namespace Tests\Domain\Archive\Stores;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\ArchiveStore;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;
use App\Domain\Archive\Exceptions\ArchiveStorageUnavailableException;
use App\Domain\Archive\Services\HistoricalQueryService;
use App\Domain\Archive\Stores\ClickHouseStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CH 故障降级语义（spec 004 US4 / T023，R-009）：
 * 连接拒绝/超时/HTTP 500 三形态下 ClickHouseStore 统一抛 ArchiveStorageUnavailableException 且携带原因；
 * HistoricalQueryService 冷路径原样上抛（不吞不改写，同实例冒泡）；
 * 纯热路径 store 零调用（机制性零依赖，CH 断开不影响热查询）。
 */
final class DegradationTest extends TestCase
{
    private const W = '2026-10-01 00:00:00';

    public function test_connection_refused_throws_typed_exception_with_reason(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection refused');
        });
        $store = ClickHouseStore::fromConfig();

        static::assertFalse($store->ping(), 'ping 连接失败返回 false（不抛）');
        $this->assertAllMethodsThrow($store, '连接失败');
    }

    public function test_timeout_throws_typed_exception_with_reason(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Operation timed out after 5000ms');
        });
        $store = ClickHouseStore::fromConfig();

        static::assertFalse($store->ping());
        $this->assertAllMethodsThrow($store, 'timed out');
    }

    public function test_http_500_throws_typed_exception_with_status(): void
    {
        Http::fake(['*' => Http::response('Code: 81. DB::Exception: Database x does not exist', 500)]);
        $store = ClickHouseStore::fromConfig();

        static::assertFalse($store->ping(), 'ping 对 500 返回 false（successful() 为假）');
        $this->assertAllMethodsThrow($store, 'HTTP 500');
    }

    public function test_historical_query_cold_path_bubbles_store_exception_unchanged(): void
    {
        $this->seedWatermark();
        $source = new ArchiveStorageUnavailableException('CH 演练断开');
        $service = new HistoricalQueryService($this->throwingStore($source));

        try {
            $service->query($this->req('2026-09-01 00:00:00', self::W)); // to <= W 纯冷
            static::fail('冷路径应抛 ArchiveStorageUnavailableException');
        } catch (ArchiveStorageUnavailableException $e) {
            static::assertSame($source, $e, '原样上抛：不吞不改写，同实例冒泡（R-009）');
        }
    }

    public function test_pure_hot_query_unaffected_by_ch_outage(): void
    {
        $this->seedWatermark();
        $source = new ArchiveStorageUnavailableException('CH 演练断开');
        $service = new HistoricalQueryService($this->throwingStore($source));

        DB::table('kline_bars')->insert([
            'id' => 10, 'exchange_code' => 'binance', 'symbol' => 'BTCUSDT', 'interval' => '1m',
            'open_time' => '2026-10-05 00:00:00', 'close_time' => '2026-10-05 00:00:00',
            'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100.5',
            'volume' => '1', 'quote_volume' => '100.5', 'trades_count' => 3,
            'created_at' => '2026-10-05 00:00:00', 'updated_at' => '2026-10-05 00:00:00',
        ]);

        // from >= W 纯热短路：store 全方法抛异常也不影响（零调用即证明，US4 场景 2）
        $result = $service->query($this->req(self::W, '2026-11-01 00:00:00'));

        static::assertSame(HistoricalQueryResult::HOT, $result->servedFrom);
        static::assertSame(['10'], array_column($result->rows, 'id'));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** 六方法逐一断言抛 ArchiveStorageUnavailableException 且 message 携带原因 */
    private function assertAllMethodsThrow(ClickHouseStore $store, string $needle): void
    {
        $this->assertThrowsUnavailable(static fn () => $store->createSchema(), $needle);
        $this->assertThrowsUnavailable(static fn () => $store->insertRows('ch_kline_bars', [['id' => '1']]), $needle);
        $this->assertThrowsUnavailable(static fn () => $store->countByBatch('ch_kline_bars', 1), $needle);
        $this->assertThrowsUnavailable(static fn () => $store->deleteByBatch('ch_kline_bars', 1), $needle);
        $this->assertThrowsUnavailable(static fn () => $store->fetchRows('ch_kline_bars', [], 'open_time', null, null, ['id' => 'ASC'], 1), $needle);
        $this->assertThrowsUnavailable(static fn () => $store->hashSum('ch_kline_bars', 1, ['id']), $needle);
    }

    private function assertThrowsUnavailable(callable $fn, string $needle): void
    {
        try {
            $fn();
            static::fail('应抛 ArchiveStorageUnavailableException');
        } catch (ArchiveStorageUnavailableException $e) {
            static::assertStringContainsString($needle, $e->getMessage());
        }
    }

    /** 全方法抛同一异常的 store（验证服务层冒泡/纯热短路用） */
    private function throwingStore(ArchiveStorageUnavailableException $e): ArchiveStore
    {
        return new class($e) implements ArchiveStore
        {
            public function __construct(private readonly ArchiveStorageUnavailableException $e)
            {
            }

            public function ping(): bool
            {
                return false;
            }

            public function createSchema(): array
            {
                throw $this->e;
            }

            public function insertRows(string $chTable, array $rows): void
            {
                throw $this->e;
            }

            public function countByBatch(string $chTable, int $batchId): int
            {
                throw $this->e;
            }

            public function deleteByBatch(string $chTable, int $batchId): void
            {
                throw $this->e;
            }

            public function fetchRows(string $chTable, array $filters, string $timeColumn, ?string $timeFromUtc, ?string $timeToUtc, array $orderBy, int $limit, int $offset = 0, ?array $keysetAfter = null): array
            {
                throw $this->e;
            }

            public function hashSum(string $chTable, int $batchId, array $columns): string
            {
                throw $this->e;
            }
        };
    }

    private function seedWatermark(): void
    {
        DB::table('archive_watermarks')->insert([
            'data_type' => 'kline_bar',
            'copied_watermark' => self::W,
            'applied_watermark' => self::W,
            'created_at' => '2026-10-02 00:00:00',
            'updated_at' => '2026-10-02 00:00:00',
        ]);
    }

    private function req(string $from, string $to): HistoricalQueryRequest
    {
        return new HistoricalQueryRequest(
            dataType: ArchiveDataType::KlineBar,
            fromUtc: new \DateTimeImmutable($from, new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable($to, new \DateTimeZone('UTC')),
        );
    }
}
