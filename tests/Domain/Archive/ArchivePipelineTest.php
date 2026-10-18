<?php

declare(strict_types=1);

namespace Tests\Domain\Archive;

use App\Domain\Archive\Contracts\ArchiveStore;
use App\Domain\Archive\Dto\ArchiveRunOptions;
use App\Domain\Archive\Services\ArchivePipeline;
use App\Domain\Archive\Types\KlineBarArchiveType;
use App\Domain\Archive\Types\SignalArchiveType;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Domain\Archive\Support\FakeArchiveStore;
use Tests\TestCase;

/**
 * ArchivePipeline 状态机/安全红线/热链路零感知单测（spec 004 T010-T012）。
 *
 * fake ArchiveStore + 真实 Eloquent 热表（SQLite :memory:）+ 固定时钟（nowUtc 注入）。
 * 状态机 = data-model.md：pending → copied → passed/failed → applied（applied_at 标记）。
 * 安全红线 = contracts/archive-pipeline-contract.md §3。
 */
final class ArchivePipelineTest extends TestCase
{
    private FakeArchiveStore $store;

    /** 固定时钟：now=2026-10-19 00:00:00 UTC，retention 7 天 → 安全上界 2026-10-12 00:00:00 */
    private const NOW = '2026-10-19 00:00:00';

    private \DateTimeImmutable $now;

    private const CH_TABLE = 'ch_kline_bars';

    protected function setUp(): void
    {
        parent::setUp();
        config(['archive.types.kline_bar.retention_days' => 7]);
        $this->now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
        $this->store = new FakeArchiveStore;
        $this->app->instance(ArchiveStore::class, $this->store);
    }

    // ------------------------------------------------------------------
    // T010 状态机与账目
    // ------------------------------------------------------------------

    public function test_dry_run_slices_batches_by_window_and_keeps_books(): void
    {
        $this->seedKline('2026-10-10 10:00:00', '2026-10-10 10:01:00');
        $this->seedKline('2026-10-10 23:00:00', '2026-10-10 23:01:00');
        $this->seedKline('2026-10-11 00:30:00', '2026-10-11 00:31:00');
        $this->seedKline('2026-10-11 12:00:00', '2026-10-11 12:01:00');
        $this->seedKline('2026-10-11 23:59:00', '2026-10-11 23:59:59');
        $this->seedKline('2026-10-15 00:00:00', '2026-10-15 00:01:00'); // 保留期内，不动

        $report = $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions());

        // 两个批次：[10-10, 10-11) 2 行 / [10-11, 10-12) 3 行；空切片不建批
        static::assertCount(2, $report->batches);
        static::assertSame('2026-10-10 00:00:00', $report->batches[0]->timeFrom);
        static::assertSame('2026-10-11 00:00:00', $report->batches[0]->timeTo);
        static::assertSame(2, $report->batches[0]->sourceRows);
        static::assertSame(3, $report->batches[1]->sourceRows);
        static::assertTrue($report->batches[0]->sourceRows === $report->batches[0]->archivedRows);

        // 账目：verify passed、未 applied（dry-run）
        $rows = DB::table('archive_batches')->orderBy('id')->get();
        static::assertCount(2, $rows);
        static::assertSame('passed', $rows[0]->verify_status);
        static::assertSame('passed', $rows[1]->verify_status);
        static::assertNull($rows[0]->applied_at);

        // 状态机迁移 pending→copied→passed 终态 + verify 明细
        static::assertStringContainsString('rows=OK', $report->batches[0]->verifyDetail);
        static::assertStringContainsString('hash_sum=OK', $report->batches[0]->verifyDetail);
        static::assertStringContainsString('sample=2/2=OK', $report->batches[0]->verifyDetail);

        // copied_watermark 推进到最后批次上界；applied_watermark 不动
        $wm = DB::table('archive_watermarks')->where('data_type', 'kline_bar')->first();
        static::assertSame('2026-10-12 00:00:00', $wm->copied_watermark);
        static::assertSame('1970-01-01 00:00:00', $wm->applied_watermark);

        // dry-run：主库零删除、CH 有数据
        static::assertSame(6, DB::table('kline_bars')->count());
        static::assertSame(5, $this->store->countByBatch(self::CH_TABLE, (int) $rows[0]->id)
            + $this->store->countByBatch(self::CH_TABLE, (int) $rows[1]->id));
    }

    public function test_uniq_range_reuses_incomplete_batch_and_clears_stale_ch_rows(): void
    {
        // 预置半途批次（上次运行中断遗留）与 CH 半途脏行
        $batchId = (int) DB::table('archive_batches')->insertGetId([
            'data_type' => 'kline_bar',
            'time_from' => '2026-10-10 00:00:00',
            'time_to' => '2026-10-11 00:00:00',
            'source_rows' => 999,
            'archived_rows' => 1,
            'verify_status' => 'pending',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->store->tables[self::CH_TABLE][] = [
            'id' => '1', 'batch_id' => (string) $batchId, 'open_time' => '2026-10-10 10:00:00', 'symbol' => 'GARBAGE',
        ];

        $this->seedKline('2026-10-10 10:00:00', '2026-10-10 10:01:00');
        $this->seedKline('2026-10-10 11:00:00', '2026-10-10 11:01:00');

        $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions());

        // 同切片复用未完成批次行（不重复建批）
        static::assertSame(1, DB::table('archive_batches')->count());
        static::assertSame(2, (int) DB::table('archive_batches')->value('source_rows'));

        // copy 幂等守卫：先 deleteByBatch 清半途行再插 → 无重复
        static::assertSame([self::CH_TABLE, $batchId], $this->store->deleteByBatchCalls[0]);
        static::assertSame(2, $this->store->countByBatch(self::CH_TABLE, $batchId));
        static::assertSame('passed', DB::table('archive_batches')->value('verify_status'));
    }

    public function test_failed_verify_marks_failed_and_rerun_resets_then_repairs(): void
    {
        $this->seedKline('2026-10-10 10:00:00', '2026-10-10 10:01:00');
        $this->seedKline('2026-10-10 11:00:00', '2026-10-10 11:01:00');

        // 第一次：哈希和失配 → failed + failed_reason 记账
        $this->store->forceHashSum = '42';
        $report = $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions());
        static::assertSame('failed', $report->batches[0]->verifyStatus);
        static::assertSame('failed', $report->batches[0]->action);
        static::assertNotNull($report->batches[0]->message);
        $row = DB::table('archive_batches')->sole();
        static::assertSame('failed', $row->verify_status);
        static::assertNotNull($row->failed_reason);
        // 失败批次不推进 copied_watermark（续跑必须重处理）
        static::assertSame('1970-01-01 00:00:00', DB::table('archive_watermarks')->value('copied_watermark'));

        // 第二次：failed 复位 pending → 重 copy（再次清半途行）→ 修复转 passed
        $this->store->forceHashSum = null;
        $this->store->deleteByBatchCalls = [];
        $report = $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions());

        static::assertSame('passed', $report->batches[0]->verifyStatus);
        static::assertSame(1, DB::table('archive_batches')->count(), '复用同一切片批次行');
        static::assertCount(1, $this->store->deleteByBatchCalls, '重跑先清半途 CH 行');
        // 数据只落在 [10-10, 10-11) 一个窗口，后继窗口空切片不推进（防回填丢失）
        static::assertSame('2026-10-11 00:00:00', DB::table('archive_watermarks')->value('copied_watermark'));
    }

    public function test_max_batches_limits_processed_batches_per_run(): void
    {
        $this->seedKline('2026-10-10 10:00:00', '2026-10-10 10:01:00');
        $this->seedKline('2026-10-11 10:00:00', '2026-10-11 10:01:00');

        $report = $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions(maxBatches: 1));

        static::assertCount(1, $report->batches);
        static::assertSame('2026-10-10 00:00:00', $report->batches[0]->timeFrom);
        static::assertSame(1, DB::table('archive_batches')->count());
        static::assertSame('2026-10-11 00:00:00', DB::table('archive_watermarks')->value('copied_watermark'));
    }

    public function test_apply_transitions_passed_batch_to_applied_and_deletes_hot_rows(): void
    {
        $this->seedKline('2026-10-10 10:00:00', '2026-10-10 10:01:00');
        $this->seedKline('2026-10-11 10:00:00', '2026-10-11 10:01:00');
        $this->seedKline('2026-10-15 00:00:00', '2026-10-15 00:01:00'); // 保留期内

        // 第一跑 dry-run：copy + verify
        $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions());
        // 第二跑 --apply：passed 批次进入删除
        $report = $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions(apply: true));

        static::assertSame('deleted', $report->batches[0]->action);
        static::assertSame('deleted', $report->batches[1]->action);
        static::assertSame(1, DB::table('kline_bars')->count(), '只删归档切片行，保留期内行不动');
        static::assertSame('2026-10-15 00:00:00', DB::table('kline_bars')->value('open_time'));

        $rows = DB::table('archive_batches')->orderBy('id')->get();
        static::assertNotNull($rows[0]->applied_at);
        static::assertNotNull($rows[1]->applied_at);
        static::assertSame('2026-10-12 00:00:00', DB::table('archive_watermarks')->value('applied_watermark'));
        // 终态：applied 批次重跑直接跳过
        $report = $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions(apply: true));
        static::assertCount(0, $report->batches);
    }

    // ------------------------------------------------------------------
    // T011 安全红线（contracts/archive-pipeline-contract.md §3）
    // ------------------------------------------------------------------

    public function test_red_line_1_apply_refuses_batch_that_never_passed_verify(): void
    {
        $this->seedKline('2026-10-10 10:00:00', '2026-10-10 10:01:00');
        $this->store->forceHashSum = '42';

        // --apply 模式下 verify 失败：批次永不进入删除候选
        $report = $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions(apply: true));

        static::assertSame('failed', $report->batches[0]->action);
        static::assertSame(1, DB::table('kline_bars')->count(), '主库零删除');
        static::assertNull(DB::table('archive_batches')->value('applied_at'));
        static::assertSame('1970-01-01 00:00:00', DB::table('archive_watermarks')->value('applied_watermark'));
    }

    public function test_red_line_2_apply_recount_mismatch_aborts_without_partial_delete(): void
    {
        $this->seedKline('2026-10-10 10:00:00', '2026-10-10 10:01:00');
        $this->seedKline('2026-10-10 11:00:00', '2026-10-10 11:01:00');
        $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions());

        // verify 后、apply 前主库被并发写入一行（落在切片内）
        $this->seedKline('2026-10-10 12:00:00', '2026-10-10 12:01:00');

        try {
            $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions(apply: true));
            static::fail('apply 再核对失配必须 abort');
        } catch (\RuntimeException $e) {
            static::assertStringContainsString('再核对', $e->getMessage());
        }

        // 无半删：3 行全在；水位未推进；批次降级 failed 拒绝删除
        static::assertSame(3, DB::table('kline_bars')->count());
        static::assertSame('1970-01-01 00:00:00', DB::table('archive_watermarks')->value('applied_watermark'));
        static::assertSame('failed', DB::table('archive_batches')->value('verify_status'));
    }

    public function test_red_line_3_slice_upper_bound_capped_at_retention_and_kline_close_guard(): void
    {
        // 未闭合 bar：切片时间窗内但 close_time >= now-5min → 必须排除
        $this->seedKline('2026-10-11 00:30:00', '2026-10-18 23:56:00');
        $this->seedKline('2026-10-11 00:31:00', '2026-10-11 00:32:00');

        $report = $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions(toUtc: '2026-12-31 00:00:00'));

        // --to 越界截断到安全上界 + 警告
        static::assertSame('2026-10-12 00:00:00', $report->toUtc);
        static::assertNotSame([], $report->warnings);
        // 闭合保险生效：未闭合 bar 不进任何批次
        static::assertCount(1, $report->batches);
        static::assertSame(1, $report->batches[0]->sourceRows);
        static::assertSame(2, DB::table('kline_bars')->count());
    }

    // ------------------------------------------------------------------
    // T012 热链路零感知（FR-003）
    // ------------------------------------------------------------------

    public function test_hot_writes_during_archive_survive_apply_and_stay_out_of_batches(): void
    {
        $this->seedKline('2026-10-10 10:00:00', '2026-10-10 10:01:00');
        $this->seedKline('2026-10-10 11:00:00', '2026-10-10 11:01:00');

        // copy 期间（首个 CH 写入前钩子）模拟热链路并发写入保留期内新行
        $this->store->onInsert = function (): void {
            $this->store->onInsert = null;
            $this->seedKline('2026-10-18 23:00:00', '2026-10-18 23:01:00');
        };

        $report = $this->pipeline()->run(new KlineBarArchiveType, $this->runOptions(apply: true));

        // 新行不落入任何批次切片（时间片谓词隔离）
        static::assertCount(1, $report->batches);
        static::assertSame(2, $report->batches[0]->sourceRows);
        static::assertSame(2, $report->batches[0]->archivedRows);
        // 归档后：老行移出、热链路新行完好
        static::assertSame(1, DB::table('kline_bars')->count());
        static::assertSame('2026-10-18 23:00:00', DB::table('kline_bars')->value('open_time'));
        static::assertSame(2, $this->store->countByBatch(self::CH_TABLE, $report->batches[0]->batchId));
    }

    public function test_tenant_type_copies_all_tenants_bypassing_scope_without_harming_recent_rows(): void
    {
        config(['archive.types.signal.retention_days' => 7]);
        $this->seedTenantWithSubscription(990100, 'tenant-a');
        $this->seedTenantWithSubscription(990200, 'tenant-b');
        // 两租户各 1 行超期 signal + 各 1 行保留期内
        $this->seedSignal(990100, 990101, now()->subDays(10)->format('Y-m-d H:i:s'));
        $this->seedSignal(990200, 990201, now()->subDays(10)->format('Y-m-d H:i:s'));
        $this->seedSignal(990100, 990101, now()->subDays(1)->format('Y-m-d H:i:s'));
        $this->seedSignal(990200, 990201, now()->subDays(1)->format('Y-m-d H:i:s'));

        // 租户 A 上下文存在（模拟业务运行期）：管线必须显式绕过 TenantScope 搬出双租户行
        TenantContext::initialize(990100);
        try {
            $report = $this->pipeline()->run(new SignalArchiveType, $this->runOptions(apply: true));
        } finally {
            TenantContext::clear();
        }

        static::assertCount(1, $report->batches);
        static::assertSame(2, $report->batches[0]->sourceRows, '双租户超期行都被搬出');
        $chRows = $this->store->tables['ch_signals'];
        static::assertSame(['990100', '990200'], array_values(array_unique(array_column($chRows, 'tenant_id'))));
        // 保留期内双租户行不误伤
        static::assertSame(2, DB::table('signals')->count());
    }

    // ------------------------------------------------------------------
    // T011 #6 + T014 命令层（参数下限 / 报告格式 / 退出码）
    // ------------------------------------------------------------------

    public function test_command_rejects_params_below_floor_with_usage_code(): void
    {
        // 红线 4：校验先于管线执行，不触碰 CH、零副作用
        $this->artisan('archive:run', ['type' => 'kline_bar', '--chunk-size' => '50'])->assertExitCode(2);
        $this->artisan('archive:run', ['type' => 'kline_bar', '--throttle-ms' => '10'])->assertExitCode(2);
        $this->artisan('archive:run', ['type' => 'bogus'])->assertExitCode(2);
        $this->artisan('archive:run', ['type' => 'kline_bar', '--from' => 'not-a-date'])->assertExitCode(2);
        static::assertSame(0, DB::table('archive_batches')->count());
    }

    public function test_command_dry_run_reports_deletable_range_and_exit_zero(): void
    {
        config(['archive.types.kline_bar.retention_days' => 7]);
        $this->seedKline(now()->subDays(10)->format('Y-m-d H:i:s'), now()->subDays(10)->modify('+1 minute')->format('Y-m-d H:i:s'));
        $this->seedKline(now()->subDays(1)->format('Y-m-d H:i:s'), now()->subDays(1)->modify('+1 minute')->format('Y-m-d H:i:s'));

        $this->artisan('archive:run', ['type' => 'kline_bar'])
            ->expectsOutputToContain('[batch')
            ->expectsOutputToContain('verify: rows=OK hash_sum=OK sample=')
            ->expectsOutputToContain('可删范围')
            ->assertExitCode(0);

        static::assertSame(2, DB::table('kline_bars')->count(), 'dry-run 主库零删除');
    }

    public function test_command_exit_1_when_verify_fails(): void
    {
        config(['archive.types.kline_bar.retention_days' => 7]);
        $this->seedKline(now()->subDays(10)->format('Y-m-d H:i:s'), now()->subDays(10)->modify('+1 minute')->format('Y-m-d H:i:s'));
        $this->store->forceHashSum = '42';

        $this->artisan('archive:run', ['type' => 'kline_bar'])->assertExitCode(1);
        static::assertSame('failed', DB::table('archive_batches')->value('verify_status'));
        static::assertNotNull(DB::table('archive_batches')->value('failed_reason'));
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function pipeline(): ArchivePipeline
    {
        return new ArchivePipeline($this->store);
    }

    private function runOptions(bool $apply = false, ?int $maxBatches = null, ?string $toUtc = null): ArchiveRunOptions
    {
        return new ArchiveRunOptions(
            apply: $apply,
            maxBatches: $maxBatches,
            toUtc: $toUtc,
            nowUtc: $this->now,
        );
    }

    private function seedKline(string $openTime, string $closeTime): void
    {
        DB::table('kline_bars')->insert([
            'exchange_code' => 'binance', 'symbol' => 'BTCUSDT', 'interval' => '1m',
            'open_time' => $openTime, 'close_time' => $closeTime,
            'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100.5',
            'volume' => '1', 'quote_volume' => '100.5', 'trades_count' => 3,
            'created_at' => $openTime, 'updated_at' => $openTime,
        ]);
    }

    private function seedTenantWithSubscription(int $tenantId, string $slug): void
    {
        $now = now();
        DB::table('tenants')->insert([
            'id' => $tenantId, 'name' => $slug, 'slug' => $slug,
            'exchange_dek' => 'dummy', 'status' => 'active', 'plan_code' => 'free',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('signal_subscriptions')->insert([
            'id' => $tenantId + 1, 'tenant_id' => $tenantId, 'name' => 'sub',
            'symbols' => '["BTCUSDT"]', 'intervals' => '["5m"]', 'channels' => '["telegram"]',
            'active' => 1, 'rule_fallback_enabled' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function seedSignal(int $tenantId, int $subscriptionId, string $triggeredAt): void
    {
        DB::table('signals')->insert([
            'tenant_id' => $tenantId, 'subscription_id' => $subscriptionId,
            'symbol' => 'BTCUSDT', 'interval' => '5m', 'direction' => 'long',
            'entry_price' => '100', 'exit_price' => '110', 'stop_loss' => null,
            'confidence' => '0.85', 'source' => 'ai', 'rationale' => 'ok',
            'raw_payload' => '{"x":1}', 'triggered_at' => $triggeredAt,
            'created_at' => $triggeredAt, 'updated_at' => $triggeredAt,
        ]);
    }
}
