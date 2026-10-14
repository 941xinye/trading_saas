<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Domain\Billing\Metering\QuotaExceededException;
use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Billing\QuotaGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\TestCase;
use Throwable;

/**
 * 日配额计数桶（@group redis）。
 *
 * TwoPhaseMeterTest 已覆盖 7 个两阶段 Lua 场景（freeze/幂等/settle/reverse/
 * reconcile/reversed-ref 复用），此处不重复，只补 POC 第 2 段中未独立断言的
 * 「初始用量=0」「settled_amount decimal(20,8) 满刻度」，以及 UTC 跨天重置与
 * QuotaGuard 套餐限额拒绝语义。
 */
#[Group('redis')]
class DailyQuotaCounterTest extends TestCase
{
    use ChecksRedisAvailability;

    private const TENANT_ID = 990031;

    private const EVENT = 'ai_call';

    private TwoPhaseMeter $meter;

    /** tearDown 守卫：仅 setUp ping 成功才清 Redis（坑 #24） */
    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        Carbon::setTestNow(Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC'));

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-billing-quota-990031',
            'slug' => 'poc-billing-quota-990031',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->meter = app(TwoPhaseMeter::class);
        $this->meter->flushDayCounter(self::TENANT_ID, self::EVENT);
    }

    protected function tearDown(): void
    {
        // 顺序同 TwoPhaseMeterTest：外部清理受守卫保护且先于 parent::tearDown()（坑 #24）
        if ($this->redisAvailable) {
            try {
                $meter = app(TwoPhaseMeter::class);
                $meter->flushDayCounter(self::TENANT_ID, self::EVENT);
                $meter->flushDayCounter(self::TENANT_ID, self::EVENT, Carbon::create(2026, 10, 16, 0, 0, 0, 'UTC'));
            } catch (Throwable) {
                // key TTL 2 天且用高位隔离租户 ID，清理失败不吞测试结果
            }
        }
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_fresh_day_counter_starts_at_zero(): void
    {
        static::assertSame(0, $this->meter->usedToday(self::TENANT_ID, self::EVENT));
    }

    public function test_settle_persists_full_scale_decimal_amount(): void
    {
        $event = $this->meter->freeze(self::TENANT_ID, self::EVENT, 'scale-1', 10);
        static::assertNotNull($event);

        $settled = $this->meter->settle($event);

        static::assertSame('settled', $settled->status);
        // decimal(20,8) 经模型 decimal:8 cast 必须保留 8 位小数字符串（POC 原始断言）
        static::assertSame('1.00000000', (string) $settled->settled_amount);
        static::assertSame(0, bccomp((string) $settled->settled_amount, '1', 8));
    }

    public function test_counter_rolls_over_at_utc_midnight(): void
    {
        // UTC 23:59:59 占额一次
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 23, 59, 59, 'UTC'));
        static::assertNotNull($this->meter->freeze(self::TENANT_ID, self::EVENT, 'day-15', 10));
        static::assertSame(1, $this->meter->usedToday(self::TENANT_ID, self::EVENT));

        // 跨过 UTC 00:00 → 新日桶归零；旧桶仍保留 1
        Carbon::setTestNow(Carbon::create(2026, 10, 16, 0, 0, 0, 'UTC'));
        static::assertSame(0, $this->meter->usedToday(self::TENANT_ID, self::EVENT));
        static::assertNotNull($this->meter->freeze(self::TENANT_ID, self::EVENT, 'day-16', 10));
        static::assertSame(1, $this->meter->usedToday(self::TENANT_ID, self::EVENT));
        static::assertSame(
            1,
            $this->meter->usedToday(self::TENANT_ID, self::EVENT, Carbon::create(2026, 10, 15, 12, 0, 0, 'UTC')),
        );
    }

    public function test_freeze_daily_through_quota_guard_denies_after_plan_limit_without_row(): void
    {
        $guard = app(QuotaGuard::class);

        // free 套餐 ai_calls_per_day=10
        for ($i = 1; $i <= 10; $i++) {
            $guard->freezeDaily(self::TENANT_ID, self::EVENT, "guard-{$i}");
        }
        static::assertSame(10, $this->meter->usedToday(self::TENANT_ID, self::EVENT));

        try {
            $guard->freezeDaily(self::TENANT_ID, self::EVENT, 'guard-denied');
            static::fail('第 11 次占额必须抛 QuotaExceededException');
        } catch (QuotaExceededException $e) {
            static::assertSame(self::TENANT_ID, $e->tenantId);
            static::assertSame(self::EVENT, $e->eventType);
            static::assertSame(10, $e->limit);
            static::assertSame(10, $e->used);
        }

        // 被拒不双扣、不落 frozen 行
        static::assertSame(10, $this->meter->usedToday(self::TENANT_ID, self::EVENT));
        static::assertSame(
            0,
            DB::table('meter_events')->where('tenant_id', self::TENANT_ID)->where('ref_id', 'guard-denied')->count(),
        );
    }
}
