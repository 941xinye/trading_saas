<?php

declare(strict_types=1);

namespace Tests\Integration\Backtest;

use App\Domain\Backtest\Contracts\BacktestRunStatus;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Exceptions\BacktestConcurrencyException;
use App\Domain\Backtest\Models\BacktestRun;
use App\Domain\Backtest\Services\BacktestRunService;
use App\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\Support\RedisCleanup;
use Tests\TestCase;

/**
 * US3 并发控制集成测试（T032）。
 *
 * 单飞锁冲突拒绝、租户并发上限（free=1 第二任务拒绝）、锁 TTL 释放后可再发起。
 *
 * @group redis
 */
final class BacktestConcurrencyTest extends TestCase
{
    use RedisCleanup;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'name' => 'concurrency-tenant',
            'slug' => 'concurrency-tenant',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
        ]);
        TenantContext::initialize($this->tenant->id);
        $this->cleanupLocks();
    }

    protected function tearDown(): void
    {
        $this->cleanupLocks();
        TenantContext::clear();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 单飞锁：同一 run_id 重复创建 → 拒绝
    // ------------------------------------------------------------------

    public function test_single_lock_rejects_duplicate_run(): void
    {
        $service = app(BacktestRunService::class);

        $run1 = $service->create(
            tenantId: $this->tenant->id,
            strategyCode: 'spot_grid',
            params: ['lower_price' => '90', 'upper_price' => '110', 'grid_count' => 5, 'investment_amount' => '1000'],
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            assumptions: MatchingAssumptions::fromConfig(),
        );

        // 同一 run_id 再次 acquireLock 应失败（通过反射调用私有方法模拟重复创建）
        $this->expectException(BacktestConcurrencyException::class);
        $this->expectExceptionMessage('单飞锁冲突');

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('acquireLock');
        $method->setAccessible(true);
        $method->invoke($service, $run1->id, $this->tenant->id);
    }

    // ------------------------------------------------------------------
    // 租户并发上限：free=1，已有一个 running → 第二任务拒绝
    // ------------------------------------------------------------------

    public function test_tenant_concurrency_limit_rejects_second_run(): void
    {
        // free 套餐并发上限 = 1
        config()->set('backtest.concurrency.free', 1);

        $service = app(BacktestRunService::class);

        // 先创建一个 running 状态的 run（占满并发额度）
        BacktestRun::create([
            'tenant_id' => $this->tenant->id,
            'strategy_code' => 'spot_grid',
            'params_snapshot' => [],
            'assumptions' => MatchingAssumptions::fromConfig()->toArray(),
            'symbol' => 'BTCUSDT',
            'interval' => '1m',
            'from_utc' => new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            'to_utc' => new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            'status' => BacktestRunStatus::Running,
            'progress_pct' => 50,
        ]);

        $this->expectException(BacktestConcurrencyException::class);
        $this->expectExceptionMessage('并发上限');

        $service->create(
            tenantId: $this->tenant->id,
            strategyCode: 'spot_grid',
            params: ['lower_price' => '90', 'upper_price' => '110', 'grid_count' => 5, 'investment_amount' => '1000'],
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            assumptions: MatchingAssumptions::fromConfig(),
        );
    }

    // ------------------------------------------------------------------
    // 锁 TTL 释放后可再发起
    // ------------------------------------------------------------------

    public function test_lock_released_after_ttl_allows_new_run(): void
    {
        $service = app(BacktestRunService::class);

        $run1 = $service->create(
            tenantId: $this->tenant->id,
            strategyCode: 'spot_grid',
            params: ['lower_price' => '90', 'upper_price' => '110', 'grid_count' => 5, 'investment_amount' => '1000'],
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            assumptions: MatchingAssumptions::fromConfig(),
        );

        static::assertSame(1, BacktestRun::count(), '第一次 create 后应有 1 条记录');

        // 手工删除锁（模拟 TTL 过期 / releaseLock）
        $lockKey = sprintf('tenant:%d:backtest:lock:%d', $this->tenant->id, $run1->id);
        $deleted = Redis::connection('default')->del($lockKey);
        static::assertSame(1, $deleted, '锁应被成功删除');
        static::assertSame(0, Redis::connection('default')->exists($lockKey), '锁应不存在');

        // 再次创建应成功
        $run2 = $service->create(
            tenantId: $this->tenant->id,
            strategyCode: 'spot_grid',
            params: ['lower_price' => '90', 'upper_price' => '110', 'grid_count' => 5, 'investment_amount' => '1000'],
            symbol: 'BTCUSDT',
            interval: '1m',
            fromUtc: new \DateTimeImmutable('2023-11-14 22:00:00', new \DateTimeZone('UTC')),
            toUtc: new \DateTimeImmutable('2023-11-14 22:10:00', new \DateTimeZone('UTC')),
            assumptions: MatchingAssumptions::fromConfig(),
        );

        static::assertNotSame($run1->id, $run2->id, "run1={$run1->id} run2={$run2->id} 应不同");
        static::assertSame(BacktestRunStatus::Pending, $run2->status);
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function cleanupLocks(): void
    {
        // keys 内部会自动加前缀，pattern 无需手动加前缀；trait 自动 strip 前缀后再 del（坑 #26）
        $this->flushRedisPattern('tenant:*:backtest:lock:*');
    }
}
