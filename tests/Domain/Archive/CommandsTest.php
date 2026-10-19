<?php

declare(strict_types=1);

namespace Tests\Domain\Archive;

use App\Domain\Archive\Contracts\ArchiveStore;
use Illuminate\Support\Facades\DB;
use Tests\Domain\Archive\Support\FakeArchiveStore;
use Tests\TestCase;

/**
 * archive:verify / archive:status 命令（spec 004 US5 / T025，FR-009）。
 *
 * fake ArchiveStore + 真实管线先记账（dry-run passed 批次），再驱动复检/状态命令。
 * US5 场景 2：人为制造不一致（篡改 store 哈希）→ 复检定位到具体批次降级 failed → 不可进入 apply 候选。
 */
final class CommandsTest extends TestCase
{
    private FakeArchiveStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        config(['archive.types.kline_bar.retention_days' => 7]);
        $this->store = new FakeArchiveStore;
        $this->app->instance(ArchiveStore::class, $this->store);
    }

    // ------------------------------------------------------------------
    // archive:verify
    // ------------------------------------------------------------------

    public function test_verify_rechecks_passed_batch_and_keeps_status(): void
    {
        $this->seedBatchViaPipeline();

        // 注：expectsOutputToContain 每断言需各自匹配不同输出行（同一行只能满足一个断言）
        $this->artisan('archive:verify', ['type' => 'kline_bar'])
            ->expectsOutputToContain('verify: rows=OK hash_sum=OK sample=')
            ->expectsOutputToContain('汇总[kline_bar]：1 批复检 / 1 passed / 0 failed')
            ->assertExitCode(0);

        static::assertSame('passed', DB::table('archive_batches')->value('verify_status'), 'passed 批次复检保持 passed');
    }

    public function test_verify_demotes_tampered_batch_and_blocks_apply(): void
    {
        $this->seedBatchViaPipeline();
        $batchId = (int) DB::table('archive_batches')->value('id');

        // 人为制造不一致：篡改 store 哈希和 → 复检定位到具体批次
        $this->store->forceHashSum = '999';
        $this->artisan('archive:verify', ['type' => 'kline_bar'])
            ->expectsOutputToContain(sprintf('[batch %d] kline_bar', $batchId))
            ->expectsOutputToContain('verify: rows=OK hash_sum=MISMATCH  → FAILED')
            ->expectsOutputToContain('demoted to failed')
            ->assertExitCode(1);

        $batch = DB::table('archive_batches')->where('id', $batchId)->first();
        static::assertSame('failed', $batch->verify_status);
        static::assertStringContainsString('复检降级', (string) $batch->failed_reason);

        // failed 批次退出复检候选
        $this->artisan('archive:verify', ['type' => 'kline_bar'])
            ->expectsOutputToContain('无待复检批次')
            ->assertExitCode(0);

        // failed 批次不可进入 apply：--apply 复跑零处理（批次在水位之后不再重访），主库零删除
        $this->artisan('archive:run', ['type' => 'kline_bar', '--apply' => true])->assertExitCode(0);
        static::assertSame(2, DB::table('kline_bars')->count(), '主库零删除（FR-005）');
        static::assertNull(DB::table('archive_batches')->where('id', $batchId)->value('applied_at'));

        // 强制 --from 回捞该区间：uniq 复位重拷后 verify 仍失败（哈希仍被篡改）→ 拒删
        $timeFrom = (string) DB::table('archive_batches')->where('id', $batchId)->value('time_from');
        $this->artisan('archive:run', ['type' => 'kline_bar', '--apply' => true, '--from' => $timeFrom])->assertExitCode(1);
        static::assertSame(2, DB::table('kline_bars')->count(), 'verify gate 兜底：重拷 verify 不过仍拒删');
        static::assertSame('failed', DB::table('archive_batches')->where('id', $batchId)->value('verify_status'));
    }

    public function test_verify_batch_option_and_usage_errors(): void
    {
        $this->seedBatchViaPipeline();
        $batchId = (int) DB::table('archive_batches')->value('id');

        // --batch 指定批次
        $this->artisan('archive:verify', ['type' => 'kline_bar', '--batch' => (string) $batchId])
            ->expectsOutputToContain('PASSED')
            ->assertExitCode(0);
        // --batch 不存在
        $this->artisan('archive:verify', ['type' => 'kline_bar', '--batch' => '999'])->assertExitCode(2);
        // --batch 缺 type
        $this->artisan('archive:verify', ['--batch' => (string) $batchId])->assertExitCode(2);
        // 非法类型
        $this->artisan('archive:verify', ['type' => 'bogus'])->assertExitCode(2);
        // 未启用类型
        config(['archive.types.signal.enabled' => false]);
        $this->artisan('archive:verify', ['type' => 'signal'])->assertExitCode(2);
    }

    // ------------------------------------------------------------------
    // archive:status
    // ------------------------------------------------------------------

    public function test_status_outputs_watermarks_estimate_and_recent_batches(): void
    {
        $this->seedBatchViaPipeline();
        // 归档后新写入一行（水位之后、保留期上界之前）→ 待归档量估算 = 1
        $this->seedKline(now()->subDays(8)->format('Y-m-d H:i:s'), now()->subDays(8)->modify('+1 minute')->format('Y-m-d H:i:s'));

        $this->artisan('archive:status', ['type' => 'kline_bar'])
            ->expectsOutputToContain('[kline_bar] enabled retention_days=7')
            ->expectsOutputToContain('applied_watermark=1970-01-01 00:00:00')
            ->expectsOutputToContain('待归档量估算：1 行')
            ->expectsOutputToContain('verify=passed applied_at=-')
            ->assertExitCode(0);
    }

    public function test_status_marks_disabled_type_and_rejects_bogus_type(): void
    {
        config(['archive.types.signal.enabled' => false]);

        $this->artisan('archive:status', ['type' => 'signal'])
            ->expectsOutputToContain('[signal] disabled')
            ->assertExitCode(0);

        $this->artisan('archive:status', ['type' => 'bogus'])->assertExitCode(2);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** 真实管线 dry-run 记账：2 行超期 kline → 1 批次 passed */
    private function seedBatchViaPipeline(): void
    {
        $this->seedKline(now()->subDays(10)->format('Y-m-d H:i:s'), now()->subDays(10)->modify('+1 minute')->format('Y-m-d H:i:s'));
        $this->seedKline(now()->subDays(10)->modify('+1 hour')->format('Y-m-d H:i:s'), now()->subDays(10)->modify('+61 minutes')->format('Y-m-d H:i:s'));
        $this->artisan('archive:run', ['type' => 'kline_bar'])->assertExitCode(0);
        static::assertSame('passed', DB::table('archive_batches')->value('verify_status'));
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
}
