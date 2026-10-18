<?php

declare(strict_types=1);

namespace Tests\Domain\Archive\Stores;

use App\Domain\Archive\Contracts\ArchiveType;
use App\Domain\Archive\Stores\ClickHouseStore;
use App\Domain\Archive\Stores\RowNormalizer;
use App\Domain\Archive\Types\KlineBarArchiveType;
use App\Domain\Archive\Types\LiquidationEventArchiveType;
use App\Domain\Archive\Types\SignalArchiveType;
use App\Domain\Archive\Types\StrategyOrderArchiveType;
use App\Models\KlineBar;
use App\Models\LiquidationEvent;
use App\Models\Signal;
use App\Models\StrategyOrder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ClickHouseStore 集成测试（spec 004 T009 / R-011 / constitution VI）。
 *
 * 可用性标志守卫：CH 不可达 markTestSkipped（与既有 56 skipped 同模式，不阻塞 CI 基线）。
 * 固定用例：schema 幂等 / 四表 round-trip 逐位精度（R-004 验收锚点）/ 轻量删除即时可见
 * （R-003 候选待核验证指针）/ 哈希和两侧同构（R-006）/ tearDown 按 batch_id 精确清理。
 *
 * @group archive-ch
 */
final class ClickHouseStoreIntegrationTest extends TestCase
{
    private const TENANT_ID = 990123456;

    private ClickHouseStore $store;

    private int $batchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = ClickHouseStore::fromConfig();
        if (! $this->store->ping()) {
            $this->markTestSkipped('ClickHouse 不可达，跳过集成断言（可用性标志模式，R-011）');
        }

        // 高位隔离批号：避免与真实归档批次/历史残留冲突
        $this->batchId = 990_100_000 + random_int(1, 899_999);
    }

    protected function tearDown(): void
    {
        // constitution VI：外部 IO 清理用可用性标志 + try/catch 包住，且先于 parent::tearDown
        if (isset($this->store) && $this->store->ping()) {
            foreach (['ch_kline_bars', 'ch_signals', 'ch_strategy_orders', 'ch_liquidation_events'] as $table) {
                try {
                    $this->store->deleteByBatch($table, $this->batchId);
                } catch (\Throwable) {
                    // 清理失败不掩盖测试结果（批号高位隔离，残留无对账影响）
                }
            }
        }

        parent::tearDown();
    }

    public function test_create_schema_is_idempotent(): void
    {
        $first = $this->store->createSchema();
        $this->assertGreaterThanOrEqual(5, count($first)); // 库 + 四表

        // 幂等：二次执行不报错
        $this->store->createSchema();
        $this->assertTrue($this->store->ping());
    }

    public function test_kline_roundtrip_and_lightweight_delete_visibility(): void
    {
        $now = '2026-05-01 00:06:00';
        $id = (int) DB::table('kline_bars')->insertGetId([
            'exchange_code' => 'binance', 'symbol' => 'BTCUSDT', 'interval' => '5m',
            'open_time' => '2026-05-01 00:00:00', 'close_time' => '2026-05-01 00:05:00',
            'open' => '100.10000000', 'high' => '101.20000000', 'low' => '99.90000000',
            'close' => '100.90000000', 'volume' => '12.50000000', 'quote_volume' => '1261.25000000',
            'trades_count' => 42, 'created_at' => $now, 'updated_at' => $now,
        ]);
        /** @var KlineBar $model */
        $model = KlineBar::query()->findOrFail($id);
        $type = new KlineBarArchiveType();
        $chRow = $type->toChRow($model, $this->batchId, '2026-05-02 00:00:00');

        $fetched = $this->assertRoundTrip($type, $chRow);

        // Decimal 引号回读为字符串（constitution II）
        $this->assertSame('100.10000000', $fetched['open']);
        $this->assertSame('42', $fetched['trades_count']);

        // 轻量删除后 count==0 立即可见（R-003 候选待核验证指针）
        $this->store->deleteByBatch($type->chTable(), $this->batchId);
        $this->assertSame(0, $this->store->countByBatch($type->chTable(), $this->batchId));
    }

    public function test_signal_nullable_and_raw_payload_roundtrip(): void
    {
        $now = '2026-05-01 00:06:00';
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID, 'name' => 'archive-it', 'slug' => 'archive-it',
            'exchange_dek' => 'dummy', 'status' => 'active', 'plan_code' => 'free',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $subId = (int) DB::table('signal_subscriptions')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'name' => 'it-sub',
            'symbols' => '["BTCUSDT"]', 'intervals' => '["5m"]', 'channels' => '["log"]',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) DB::table('signals')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'subscription_id' => $subId,
            'symbol' => 'BTCUSDT', 'interval' => '5m', 'direction' => 'long',
            'entry_price' => '100.00000000', 'exit_price' => '110.00000000',
            'stop_loss' => null, 'confidence' => '0.85', 'source' => 'ai',
            'rationale' => null, 'raw_payload' => '{"k":1,"note":"中文"}',
            'triggered_at' => '2026-05-01 00:00:00', 'created_at' => $now, 'updated_at' => $now,
        ]);
        /** @var Signal $model */
        $model = Signal::query()->findOrFail($id);
        $type = new SignalArchiveType();
        $chRow = $type->toChRow($model, $this->batchId, '2026-05-02 00:00:00');

        // 写入侧规范化口径：可空 Decimal null→JSON null；String 类 null→''；raw_payload 原 JSON 串
        $this->assertNull($chRow['stop_loss']);
        $this->assertSame('', $chRow['rationale']);
        $this->assertSame('{"k":1,"note":"中文"}', $chRow['raw_payload']);

        $fetched = $this->assertRoundTrip($type, $chRow);

        // 读回：Nullable 列回 null（保真，无哨兵歧义）
        $this->assertNull($fetched['stop_loss']);
        $this->assertSame('0.85', $fetched['confidence']);
    }

    public function test_strategy_order_nullable_columns_roundtrip(): void
    {
        $now = '2026-05-01 00:06:00';
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID, 'name' => 'archive-it', 'slug' => 'archive-it',
            'exchange_dek' => 'dummy', 'status' => 'active', 'plan_code' => 'free',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) DB::table('strategy_orders')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'strategy_instance_id' => 1,
            'exchange_order_id' => null, 'client_order_id' => 'it-cid-0001',
            'symbol' => 'BTCUSDT', 'side' => 'buy', 'type' => 'market',
            'quantity' => '0.00100000', 'price' => null, 'stop_price' => null,
            'leverage' => null, 'reduce_only' => false, 'status' => 'filled',
            'filled_qty' => '0.00100000', 'avg_fill_price' => null, 'fee' => '0',
            'error_message' => null, 'placed_at' => null, 'filled_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        /** @var StrategyOrder $model */
        $model = StrategyOrder::query()->findOrFail($id);
        $type = new StrategyOrderArchiveType();
        $chRow = $type->toChRow($model, $this->batchId, '2026-05-02 00:00:00');

        $this->assertNull($chRow['exchange_order_id']);
        $this->assertNull($chRow['placed_at']);
        $this->assertSame('0', $chRow['reduce_only']);
        $this->assertSame('', $chRow['error_message']);

        $fetched = $this->assertRoundTrip($type, $chRow);
        $this->assertNull($fetched['exchange_order_id']);
        $this->assertNull($fetched['leverage']);
        $this->assertNull($fetched['avg_fill_price']);
    }

    public function test_liquidation_roundtrip_with_value_usd_scale2(): void
    {
        $now = '2026-05-01 00:06:00';
        $id = (int) DB::table('liquidation_events')->insertGetId([
            'exchange_code' => 'binance', 'symbol' => 'BTCUSDT', 'side' => 'SELL',
            'price' => '60000.00000000', 'qty' => '2.50000000', 'value_usd' => '150000.00',
            'event_ts' => '2026-05-01 00:00:00', 'exchange_event_id' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        /** @var LiquidationEvent $model */
        $model = LiquidationEvent::query()->findOrFail($id);
        $type = new LiquidationEventArchiveType();
        $chRow = $type->toChRow($model, $this->batchId, '2026-05-02 00:00:00');

        $this->assertSame('', $chRow['exchange_event_id']); // String 非 Nullable，null→''
        $this->assertSame('150000.00', $chRow['value_usd']); // Decimal(20,2) 逐位

        $this->assertRoundTrip($type, $chRow);
    }

    /**
     * 写入 → 行数 / 哈希和两侧同构 / 读回 canonicalRow 逐位相等（R-004/R-006 验收锚点）。
     *
     * @param array<string, string|null> $chRow
     * @return array<string, string|null>
     */
    private function assertRoundTrip(ArchiveType $type, array $chRow): array
    {
        $this->store->insertRows($type->chTable(), [$chRow]);
        $this->assertSame(1, $this->store->countByBatch($type->chTable(), $this->batchId));

        $expectedSum = RowNormalizer::mod2p64(
            RowNormalizer::addHash('0', RowNormalizer::rowHash(RowNormalizer::canonicalRow($type, $chRow))),
        );
        $this->assertSame($expectedSum, $this->store->hashSum($type->chTable(), $this->batchId, $type->canonicalColumns()));

        $fetched = $this->store->fetchRows(
            $type->chTable(),
            ['batch_id' => $this->batchId],
            $type->timeColumn(),
            null,
            null,
            ['id' => 'ASC'],
            10,
        );
        $this->assertCount(1, $fetched);
        $this->assertSame(
            RowNormalizer::canonicalRow($type, $chRow),
            RowNormalizer::canonicalRow($type, $fetched[0]),
        );

        return $fetched[0];
    }
}
