<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Domain\Billing\Metering\TwoPhaseMeter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\TestCase;
use Throwable;

/**
 * TwoPhaseMeter 两阶段计量样板测试（P5.5 步骤 0，@group redis）。
 *
 * 覆盖 P3 核心语义：原子占额、超限拒绝、ref 幂等、settle 保留计数、
 * reverse 还额幂等、reconcile 从 DB 重建、reversed 后 ref 可重新占额。
 *
 * 隔离约定：
 *  - 使用高位测试租户 ID 990001，Redis key 与真实租户（1/2/8）永不冲突；
 *  - setUp/tearDown 都 flush 当日计数桶（坑 #14：段间共用桶必须先清）。
 */
#[Group('redis')]
class TwoPhaseMeterTest extends TestCase
{
    use ChecksRedisAvailability;

    private const TENANT_ID = 990001;

    private const EVENT = 'ai_call';

    private TwoPhaseMeter $meter;

    /** Redis 是否真实可用；tearDown 绝不能因 Redis 不可用再抛异常（否则污染事务回滚，级联拖垮后续测试） */
    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        $this->meter = app(TwoPhaseMeter::class);

        Carbon::setTestNow(Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC'));
        $this->meter->flushDayCounter(self::TENANT_ID, self::EVENT);

        // meter_events.tenant_id 有外键约束（坑 #15），插入真实租户行
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-unit-tenant',
            'slug' => 'poc-unit-990001',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    protected function tearDown(): void
    {
        // 顺序：先做可能失败的外部清理（受守卫保护），最后 parent::tearDown() 回滚事务
        if ($this->redisAvailable) {
            try {
                app(TwoPhaseMeter::class)->flushDayCounter(self::TENANT_ID, self::EVENT);
            } catch (Throwable) {
                // 清理失败不允许吞掉测试结果本身（key TTL 2 天，且用的是高位隔离租户 ID）
            }
        }
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_freeze_within_limit_increments_counter(): void
    {
        $event = $this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-a', 10);

        static::assertNotNull($event);
        static::assertSame('frozen', $event->status);
        static::assertSame(1, $this->meter->usedToday(self::TENANT_ID, self::EVENT));

        $this->assertDatabaseHas('meter_events', [
            'tenant_id' => self::TENANT_ID,
            'ref_id' => 'ref-a',
            'status' => 'frozen',
        ]);
    }

    public function test_freeze_over_limit_is_rejected_without_extra_count(): void
    {
        static::assertNotNull($this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-a', 1));
        static::assertNull($this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-b', 1));

        static::assertSame(1, $this->meter->usedToday(self::TENANT_ID, self::EVENT));
        static::assertSame(1, DB::table('meter_events')->where('tenant_id', self::TENANT_ID)->count());
    }

    public function test_same_ref_is_idempotent(): void
    {
        $first = $this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-dup', 10);
        $second = $this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-dup', 10);

        static::assertNotNull($first);
        static::assertSame($first->id, $second->id);
        static::assertSame(1, $this->meter->usedToday(self::TENANT_ID, self::EVENT));
    }

    public function test_settle_keeps_counter_and_status(): void
    {
        $event = $this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-settle', 10);

        $settled = $this->meter->settle($event);

        static::assertSame('settled', $settled->status);
        static::assertSame(1, $this->meter->usedToday(self::TENANT_ID, self::EVENT));

        // settle 幂等：重复调用不报错、计数不变
        static::assertSame('settled', $this->meter->settle($settled)->status);
        static::assertSame(1, $this->meter->usedToday(self::TENANT_ID, self::EVENT));
    }

    public function test_reverse_returns_quota_and_is_idempotent(): void
    {
        $event = $this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-rev', 10);

        $reversed = $this->meter->reverse($event);

        static::assertSame('reversed', $reversed->status);
        static::assertSame(0, $this->meter->usedToday(self::TENANT_ID, self::EVENT));

        // 二次冲正幂等，计数不会变成负数
        static::assertSame('reversed', $this->meter->reverse($reversed)->status);
        static::assertSame(0, $this->meter->usedToday(self::TENANT_ID, self::EVENT));
    }

    public function test_reconcile_rebuilds_counter_from_db_rows(): void
    {
        $settled = $this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-1', 10);
        $frozen = $this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-2', 10);
        $reversed = $this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-3', 10);

        $this->meter->settle($settled);
        $this->meter->reverse($reversed);
        static::assertSame(2, $this->meter->usedToday(self::TENANT_ID, self::EVENT));

        // 模拟 Redis 丢 key
        $this->meter->flushDayCounter(self::TENANT_ID, self::EVENT);
        static::assertSame(0, $this->meter->usedToday(self::TENANT_ID, self::EVENT));

        // 仅 frozen+settled 计入（2 行），reversed 不计
        static::assertSame(2, $this->meter->reconcile(self::TENANT_ID, self::EVENT));
        static::assertSame(2, $this->meter->usedToday(self::TENANT_ID, self::EVENT));
    }

    public function test_reversed_ref_can_be_frozen_again(): void
    {
        $first = $this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-reuse', 10);
        $this->meter->reverse($first);
        static::assertSame(0, $this->meter->usedToday(self::TENANT_ID, self::EVENT));

        $again = $this->meter->freeze(self::TENANT_ID, self::EVENT, 'ref-reuse', 10);

        static::assertNotNull($again);
        static::assertNotSame($first->id, $again->id);
        static::assertSame('frozen', $again->status);
        static::assertSame(1, $this->meter->usedToday(self::TENANT_ID, self::EVENT));
    }
}
