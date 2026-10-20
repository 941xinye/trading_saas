<?php

declare(strict_types=1);

namespace Tests\Integration\Payment;

use App\Domain\Billing\QuotaGuard;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Payment\PaymentActivationService;
use App\Domain\Payment\PaymentInvoiceService;
use App\Domain\Payment\RenewalReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * US3 完整时间线（SC-004 / FR-007 / FR-008）：
 *
 *   t0        订阅 active，ends_at = t0+3d → 提醒命中 3 天档（7 天档不命中）
 *   t0+3d-1m  未到期：refreshStatus 不迁移（转换时点不误判）
 *   t0+4d     到期未续费：refreshStatus → past_due（权益拦截）
 *   t0+4d~t0+7d 宽限期内（grace=3d，config plans.past_due_grace_days）：仍 past_due
 *   t0+8d     超宽限：refreshStatus → tenant suspended
 *   补缴      支付确认 → 恢复 active、周期顺延 1 个日历月、权益复原；
 *             重放激活返回 false（无双重计费/重复接线）；旧周期提醒不重复
 */
class RenewalRecoveryTimelineTest extends TestCase
{
    private const TENANT_ID = 990302;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-renew-tl-990302',
            'slug' => 'poc-renew-tl-990302',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_reminder_past_due_grace_suspended_recover_timeline(): void
    {
        $graceDays = (int) config('plans.past_due_grace_days', 3);
        $reminders = app(RenewalReminderService::class);
        $subscriptions = app(SubscriptionService::class);
        $guard = app(QuotaGuard::class);

        // ---- t0：付费 pro 订阅 active，ends_at = t0 + 3 天 ----
        $t0 = CarbonImmutable::create(2026, 10, 1, 0, 10, 0, 'UTC');
        Carbon::setTestNow($t0);
        $sub = $subscriptions->provision(self::TENANT_ID, 'pro', 0);
        static::assertSame('active', $sub->status);
        // 付费周期：provision 默认 ends_at = now + 1 个日历月；回拨为 3 天后构造临期
        DB::table('subscriptions')->where('id', $sub->getKey())
            ->update(['ends_at' => $t0->addDays(3)->format('Y-m-d H:i:s')]);

        // ---- 提醒：命中 3 天档；7 天档不命中 ----
        $created = $reminders->dispatch(null, $t0);
        static::assertSame(3, $created, '3 天档 × 白名单渠道（log/mail/telegram）');
        static::assertSame([3], DB::table('payment_renewal_reminders')
            ->pluck('days_before')->map(fn ($d) => (int) $d)->unique()->values()->all());
        // 同周期重复扫描零新增
        static::assertSame(0, $reminders->dispatch(null, $t0));

        // ---- 到期前 1 分钟：refreshStatus 不误迁移 ----
        Carbon::setTestNow($t0->addDays(3)->subMinute());
        static::assertSame([], $subscriptions->refreshStatus());
        static::assertSame('active', $subscriptions->latestFor(self::TENANT_ID)->status);

        // ---- t0+4d：到期未续费 → past_due（权益拦截）----
        Carbon::setTestNow($t0->addDays(4));
        $transitions = $subscriptions->refreshStatus();
        static::assertTrue($this->hit($transitions, 'past_due'));
        static::assertSame('past_due', $subscriptions->latestFor(self::TENANT_ID)->status);
        static::assertFalse($guard->isEntitled(self::TENANT_ID));

        // ---- 宽限期内（ends_at + grace 之前）：仍 past_due，tenant 不 suspended ----
        Carbon::setTestNow($t0->addDays(3 + $graceDays));
        $subscriptions->refreshStatus();
        static::assertSame(
            'active',
            (string) DB::table('tenants')->where('id', self::TENANT_ID)->value('status'),
            '宽限截止时点前 tenant 不得 suspended',
        );

        // ---- 超宽限（ends_at + grace + 1d）：tenant suspended ----
        Carbon::setTestNow($t0->addDays(3 + $graceDays + 1));
        $transitions = $subscriptions->refreshStatus();
        static::assertTrue($this->hit($transitions, 'suspended'));
        static::assertSame(
            'suspended',
            (string) DB::table('tenants')->where('id', self::TENANT_ID)->value('status'),
        );
        static::assertFalse($guard->isEntitled(self::TENANT_ID));

        // ---- 补缴：支付确认 → 恢复 active + 周期顺延 1 个日历月 ----
        $payTime = CarbonImmutable::create(2026, 10, 9, 2, 30, 0, 'UTC');
        Carbon::setTestNow($payTime);
        $invoice = app(PaymentInvoiceService::class)->create(self::TENANT_ID, 'pro');
        $activation = app(PaymentActivationService::class);

        static::assertTrue($activation->activate($invoice, '49.00000000', 'fake.settled', '{}'));

        $restored = $subscriptions->latestFor(self::TENANT_ID);
        static::assertSame('active', $restored->status);
        static::assertSame(
            'active',
            (string) DB::table('tenants')->where('id', self::TENANT_ID)->value('status'),
        );
        // 周期顺延：新订阅 ends_at = 支付时点 + 1 个日历月
        static::assertSame(
            $payTime->addMonth()->format('Y-m-d H:i:s'),
            Carbon::parse($restored->ends_at)->format('Y-m-d H:i:s'),
        );
        static::assertTrue($guard->isEntitled(self::TENANT_ID), '恢复后权益复原');

        // ---- 无双重计费：重放激活返回 false，订阅行/审计行不翻倍 ----
        static::assertFalse($activation->activate($invoice->refresh(), '49.00000000', 'fake.settled', '{}'));
        static::assertSame(2, DB::table('subscriptions')->where('tenant_id', self::TENANT_ID)->count());
        static::assertSame(
            1,
            DB::table('payment_webhook_events')->whereNotNull('processed_key')->count(),
        );

        // ---- 旧周期提醒不重复；新周期档位可再发 ----
        static::assertSame(3, DB::table('payment_renewal_reminders')->count());
        $created = $reminders->dispatch(null, CarbonImmutable::create(2026, 11, 2, 0, 10, 0, 'UTC'));
        static::assertSame(3, $created, '新周期 7 天档（11-09 到期 - 7 天 = 11-02）');
        static::assertSame(6, DB::table('payment_renewal_reminders')->count());
    }

    /** @param array<int,array{tenant_id:int,from:string,to:string}> $transitions */
    private function hit(array $transitions, string $to): bool
    {
        foreach ($transitions as $transition) {
            if ($transition['tenant_id'] === self::TENANT_ID && $transition['to'] === $to) {
                return true;
            }
        }

        return false;
    }
}
