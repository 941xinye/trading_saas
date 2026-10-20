<?php

declare(strict_types=1);

namespace Tests\Integration\Backtest;

use App\Domain\Backtest\Contracts\BacktestRunStatus;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Models\BacktestRun;
use App\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * US3 崩溃收割集成测试（T033）。
 *
 * running 且 updated_at 过期 → reap-stale 标 failed（error_message 含 'reaped'）；
 * 终态行不触碰；无匹配时 0 affected。
 */
final class ReapStaleBacktestsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'name' => 'reap-tenant',
            'slug' => 'reap-tenant',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
        ]);
        TenantContext::initialize($this->tenant->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // running 且过期 → reap
    // ------------------------------------------------------------------

    public function test_reaps_stale_running_run(): void
    {
        $staleAfter = (int) config('backtest.stale_after_sec', 240);

        // 创建一个 running 且 updated_at 已超过 stale_after_sec 的任务
        $run = BacktestRun::create([
            'tenant_id' => $this->tenant->id,
            'strategy_code' => 'spot_grid',
            'params_snapshot' => [],
            'assumptions' => MatchingAssumptions::fromConfig()->toArray(),
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'from_utc' => new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            'to_utc' => new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            'status' => BacktestRunStatus::Running,
            'progress_pct' => 30,
        ]);

        // 手工把 updated_at 拨到过期阈值之前
        $run->updated_at = now()->subSeconds($staleAfter + 60);
        $run->save();

        $exitCode = Artisan::call('backtest:reap-stale');
        static::assertSame(0, $exitCode);

        $run->refresh();
        static::assertSame(BacktestRunStatus::Failed, $run->status);
        static::assertStringContainsString('reaped', $run->error_message);
        static::assertNotNull($run->finished_at);
    }

    // ------------------------------------------------------------------
    // 终态行不触碰
    // ------------------------------------------------------------------

    public function test_does_not_touch_terminal_status_runs(): void
    {
        $staleAfter = (int) config('backtest.stale_after_sec', 240);

        // 一个 completed 且 updated_at 很旧的任务
        $run = BacktestRun::create([
            'tenant_id' => $this->tenant->id,
            'strategy_code' => 'spot_grid',
            'params_snapshot' => [],
            'assumptions' => MatchingAssumptions::fromConfig()->toArray(),
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'from_utc' => new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            'to_utc' => new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            'status' => BacktestRunStatus::Completed,
            'progress_pct' => 100,
        ]);

        $run->updated_at = now()->subSeconds($staleAfter + 3600);
        $run->save();

        Artisan::call('backtest:reap-stale');

        $run->refresh();
        // 状态应保持 completed，不被 reap
        static::assertSame(BacktestRunStatus::Completed, $run->status);
        static::assertNull($run->error_message);
    }

    // ------------------------------------------------------------------
    // running 但未过期 → 不 reap
    // ------------------------------------------------------------------

    public function test_does_not_reap_fresh_running_run(): void
    {
        $run = BacktestRun::create([
            'tenant_id' => $this->tenant->id,
            'strategy_code' => 'spot_grid',
            'params_snapshot' => [],
            'assumptions' => MatchingAssumptions::fromConfig()->toArray(),
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'from_utc' => new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            'to_utc' => new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            'status' => BacktestRunStatus::Running,
            'progress_pct' => 10,
        ]);

        // updated_at 是刚刚，不应被收割
        Artisan::call('backtest:reap-stale');

        $run->refresh();
        static::assertSame(BacktestRunStatus::Running, $run->status);
        static::assertNull($run->error_message);
    }

    // ------------------------------------------------------------------
    // 无匹配 → 0 affected
    // ------------------------------------------------------------------

    public function test_no_matching_runs_outputs_zero(): void
    {
        $output = Artisan::call('backtest:reap-stale');
        static::assertSame(0, $output);

        $consoleOutput = Artisan::output();
        static::assertStringContainsString('reaped 0', $consoleOutput);
    }
}
