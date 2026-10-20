<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Domain\Payment\RenewalReminderService;
use App\Jobs\RenewalReminderJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 续费提醒扫描（FR-007，契约 §3）：
 * 7/3/1 档位命中/不命中边界、同周期重复调用零新增（唯一索引幂等）、
 * 续费顺延后新周期可再发、套餐 channels 白名单过滤（log 系统渠道恒放行）。
 */
class RenewalReminderServiceTest extends TestCase
{
    private const TENANT_ID = 990301;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 8, 0, 10, 0, 'UTC'));
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_threshold_hit_queues_whitelisted_channels_and_dispatches_job(): void
    {
        // pro 套餐 channels = websocket/telegram/mail；reminder_channels = log/mail/telegram
        // → log（系统渠道恒放行）+ mail + telegram 三行
        $this->makeTenant('pro');
        $this->makeSubscription('pro', 'active', '2026-10-15 08:00:00');

        $created = app(RenewalReminderService::class)->dispatch(null, $this->now());

        static::assertSame(3, $created);
        static::assertSame(
            ['log', 'mail', 'telegram'],
            DB::table('payment_renewal_reminders')->orderBy('channel')->pluck('channel')->map(fn ($c) => (string) $c)->sort()->values()->all(),
        );
        $row = DB::table('payment_renewal_reminders')->where('channel', 'log')->first();
        static::assertSame(7, (int) $row->days_before);
        static::assertSame('queued', (string) $row->status);
        static::assertSame('pro', (string) $row->plan_code);
        Queue::assertPushed(RenewalReminderJob::class, 3);
    }

    public function test_threshold_hit_uses_calendar_day_not_intraday_time(): void
    {
        // ends_at 当日内时刻早于 now（00:05 < 00:10）：日历日差仍 = 7，必须命中
        $this->makeTenant('pro');
        $this->makeSubscription('pro', 'active', '2026-10-15 00:05:00');

        $created = app(RenewalReminderService::class)->dispatch(null, $this->now());

        static::assertSame(3, $created);
    }

    public function test_non_threshold_day_creates_nothing(): void
    {
        $this->makeTenant('pro');
        // 5 天后到期：不在 [7,3,1] 档位
        $this->makeSubscription('pro', 'active', '2026-10-13 08:00:00');

        $created = app(RenewalReminderService::class)->dispatch(null, $this->now());

        static::assertSame(0, $created);
        static::assertSame(0, DB::table('payment_renewal_reminders')->count());
        Queue::assertNotPushed(RenewalReminderJob::class);
    }

    public function test_same_cycle_repeat_dispatch_is_idempotent(): void
    {
        $this->makeTenant('pro');
        $this->makeSubscription('pro', 'active', '2026-10-15 08:00:00');
        $service = app(RenewalReminderService::class);

        static::assertSame(3, $service->dispatch(null, $this->now()));
        // 同周期同档重复扫描：零新增、零重复投递
        static::assertSame(0, $service->dispatch(null, $this->now()));
        static::assertSame(3, DB::table('payment_renewal_reminders')->count());
        Queue::assertPushed(RenewalReminderJob::class, 3);
    }

    public function test_new_cycle_after_renewal_can_remind_again(): void
    {
        $this->makeTenant('pro');
        $this->makeSubscription('pro', 'active', '2026-10-15 08:00:00');
        $service = app(RenewalReminderService::class);
        static::assertSame(3, $service->dispatch(null, $this->now()));

        // 续费顺延：新订阅行（新 ends_at 周期）→ 新周期 7 天档可再发
        $this->makeSubscription('pro', 'active', '2026-11-15 08:00:00');
        $created = $service->dispatch(null, CarbonImmutable::create(2026, 11, 8, 0, 10, 0, 'UTC'));

        static::assertSame(3, $created);
        static::assertSame(6, DB::table('payment_renewal_reminders')->count());
    }

    public function test_plan_channel_whitelist_filters_reminder_channels(): void
    {
        // free 套餐 channels = websocket：仅 log 系统渠道放行
        $this->makeTenant('free');
        $this->makeSubscription('free', 'trialing', '2026-10-11 08:00:00');

        $created = app(RenewalReminderService::class)->dispatch(null, $this->now());

        static::assertSame(1, $created);
        static::assertSame('log', (string) DB::table('payment_renewal_reminders')->value('channel'));
    }

    public function test_expired_or_terminal_subscriptions_are_skipped(): void
    {
        $this->makeTenant('pro');
        // 已过期（ends_at 过去）
        $this->makeSubscription('pro', 'active', '2026-10-07 08:00:00');

        static::assertSame(0, app(RenewalReminderService::class)->dispatch(null, $this->now()));
    }

    // ------------------------------------------------------------------

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::create(2026, 10, 8, 0, 10, 0, 'UTC');
    }

    private function makeTenant(string $planCode): void
    {
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-renew-990301',
            'slug' => 'poc-renew-990301',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => $planCode,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function makeSubscription(string $planCode, string $status, string $endsAt): void
    {
        DB::table('subscriptions')->insert([
            'tenant_id' => self::TENANT_ID,
            'plan_code' => $planCode,
            'status' => $status,
            'started_at' => Carbon::now()->subMonth(),
            'ends_at' => $endsAt,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
}
