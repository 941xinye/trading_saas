<?php

declare(strict_types=1);

namespace Tests\Domain\Archive;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\ArchiveStore;
use App\Domain\Archive\Dto\ArchiveRunOptions;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;
use App\Domain\Archive\Services\ArchivePipeline;
use App\Domain\Archive\Services\HistoricalQueryService;
use App\Domain\Archive\Types\SignalArchiveType;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Domain\Archive\Support\FakeArchiveStore;
use Tests\TestCase;

/**
 * 租户隔离贯穿历史层（spec 004 US3 / T021/T022，contracts/historical-query-contract.md §4，SC-004）。
 *
 * 双租户历史 signals 经真实管线（apply）搬入 fake CH 后，分别以 A/B 租户上下文查询：
 * 纯冷/跨界/纯热三段路由各自零泄露；无上下文调 tenant 类型抛 RuntimeException；
 * 结构性断言 HistoricalQueryRequest 无 tenantId 通道（R-008 越权通道物理不存在）。
 */
final class TenantIsolationTest extends TestCase
{
    private FakeArchiveStore $store;

    private HistoricalQueryService $service;

    private const NOW = '2026-10-19 00:00:00';

    /** applied_watermark（管线 apply 后）= 2026-10-11 00:00:00 */
    private const W = '2026-10-11 00:00:00';

    private const TENANT_A = 990100;

    private const TENANT_B = 990200;

    protected function setUp(): void
    {
        parent::setUp();
        config(['archive.types.signal.retention_days' => 7]);
        $this->store = new FakeArchiveStore;
        $this->app->instance(ArchiveStore::class, $this->store);
        $this->service = new HistoricalQueryService($this->store);
        TenantContext::clear();

        // 双租户历史信号（超保留期，待归档）+ 保留期内近期信号
        $this->seedTenantWithSubscription(self::TENANT_A, 'ta');
        $this->seedTenantWithSubscription(self::TENANT_B, 'tb');
        $this->seedSignal(self::TENANT_A, '2026-10-10 01:00:00'); // id 1
        $this->seedSignal(self::TENANT_A, '2026-10-10 02:00:00'); // id 2
        $this->seedSignal(self::TENANT_B, '2026-10-10 03:00:00'); // id 3
        $this->seedSignal(self::TENANT_B, '2026-10-10 04:00:00'); // id 4
        $this->seedSignal(self::TENANT_A, '2026-10-15 01:00:00'); // id 5（保留期内）
        $this->seedSignal(self::TENANT_B, '2026-10-15 02:00:00'); // id 6（保留期内）

        // 真实管线归档（T013 显式 withoutGlobalScope 系统级搬迁）+ apply 删除热侧
        $pipeline = new ArchivePipeline($this->store);
        $report = $pipeline->run(new SignalArchiveType, new ArchiveRunOptions(
            apply: true,
            nowUtc: new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')),
        ));
        static::assertSame(1, $report->passedCount(), '归档前置：一批次 passed');
        static::assertSame(4, $this->store->countByBatch('ch_signals', (int) DB::table('archive_batches')->value('id')));
        static::assertSame(2, DB::table('signals')->count(), 'apply 后热侧只剩保留期内 2 行');
        static::assertSame(self::W, DB::table('archive_watermarks')->where('data_type', 'signal')->value('applied_watermark'));
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_tenant_a_pure_cold_sees_only_own_rows(): void
    {
        TenantContext::initialize(self::TENANT_A);

        $result = $this->service->query($this->req('2026-10-09 00:00:00', self::W));

        static::assertSame(HistoricalQueryResult::COLD, $result->servedFrom);
        static::assertSame(['1', '2'], array_column($result->rows, 'id'));
        static::assertSame([(string) self::TENANT_A, (string) self::TENANT_A], array_column($result->rows, 'tenant_id'));
        // 冷侧谓词带 tenant_id（R-008 两层等价）
        $call = end($this->store->fetchRowsCalls);
        static::assertSame((string) self::TENANT_A, $call['filters']['tenant_id'] ?? null);
    }

    public function test_tenant_a_pure_hot_sees_only_own_rows(): void
    {
        TenantContext::initialize(self::TENANT_A);

        $sqls = [];
        DB::listen(function ($query) use (&$sqls): void {
            if (str_contains($query->sql, 'signals')) {
                $sqls[] = $query->sql;
            }
        });

        $result = $this->service->query($this->req(self::W, '2026-10-20 00:00:00'));

        static::assertSame(HistoricalQueryResult::HOT, $result->servedFrom);
        static::assertSame(['5'], array_column($result->rows, 'id'));
        // 纯热短路段同样带租户谓词（隔离与路由正交，T022 断言缺口）
        static::assertNotEmpty($sqls);
        static::assertStringContainsString('tenant_id', $sqls[0]);
    }

    public function test_tenant_a_merged_crossing_watermark_zero_leak(): void
    {
        TenantContext::initialize(self::TENANT_A);

        $result = $this->service->query($this->req('2026-10-09 00:00:00', '2026-10-20 00:00:00'));

        static::assertSame(HistoricalQueryResult::MERGED, $result->servedFrom);
        static::assertSame(['1', '2', '5'], array_column($result->rows, 'id'), 'A 只见己方行（SC-004 零泄露）');
    }

    public function test_tenant_b_merged_crossing_watermark_zero_leak(): void
    {
        TenantContext::initialize(self::TENANT_B);

        $result = $this->service->query($this->req('2026-10-09 00:00:00', '2026-10-20 00:00:00'));

        static::assertSame(HistoricalQueryResult::MERGED, $result->servedFrom);
        static::assertSame(['3', '4', '6'], array_column($result->rows, 'id'), 'B 只见己方行（SC-004 零泄露）');
    }

    public function test_tenant_type_without_context_throws(): void
    {
        TenantContext::clear();

        $this->expectException(\RuntimeException::class);
        $this->service->query($this->req('2026-10-09 00:00:00', '2026-10-20 00:00:00'));
    }

    public function test_request_dto_has_no_tenant_channel(): void
    {
        $reflection = new \ReflectionClass(HistoricalQueryRequest::class);
        foreach ($reflection->getProperties() as $property) {
            static::assertStringNotContainsStringIgnoringCase('tenant', $property->getName(), 'Request 不存在 tenantId 公开属性（R-008 越权通道物理不存在）');
        }
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== HistoricalQueryRequest::class) {
                continue;
            }
            static::assertStringNotContainsStringIgnoringCase('tenant', $method->getName());
        }
    }

    public function test_public_type_queryable_without_tenant_context(): void
    {
        TenantContext::clear();

        $result = $this->service->query(new HistoricalQueryRequest(
            dataType: ArchiveDataType::KlineBar,
            fromUtc: new \DateTimeImmutable('2026-10-09 00:00:00', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2026-10-20 00:00:00'),
        ));

        static::assertSame(HistoricalQueryResult::HOT, $result->servedFrom);
        static::assertSame([], $result->rows);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function req(string $from, string $to): HistoricalQueryRequest
    {
        return new HistoricalQueryRequest(
            dataType: ArchiveDataType::Signal,
            fromUtc: new \DateTimeImmutable($from, new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable($to, new \DateTimeZone('UTC')),
        );
    }

    private function seedTenantWithSubscription(int $tenantId, string $slug): void
    {
        DB::table('tenants')->insert([
            'id' => $tenantId, 'name' => $slug, 'slug' => $slug,
            'exchange_dek' => 'dummy', 'status' => 'active', 'plan_code' => 'free',
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        DB::table('signal_subscriptions')->insert([
            'id' => $tenantId + 1, 'tenant_id' => $tenantId, 'name' => 'sub',
            'symbols' => '["BTCUSDT"]', 'intervals' => '["5m"]', 'channels' => '["telegram"]',
            'active' => 1, 'rule_fallback_enabled' => 1,
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
    }

    private function seedSignal(int $tenantId, string $triggeredAt): void
    {
        DB::table('signals')->insert([
            'tenant_id' => $tenantId, 'subscription_id' => $tenantId + 1,
            'symbol' => 'BTCUSDT', 'interval' => '5m', 'direction' => 'long',
            'entry_price' => '100', 'exit_price' => '110', 'stop_loss' => null,
            'confidence' => '0.85', 'source' => 'ai', 'rationale' => 'ok',
            'raw_payload' => '{"x":1}', 'triggered_at' => $triggeredAt,
            'created_at' => $triggeredAt, 'updated_at' => $triggeredAt,
        ]);
    }
}
