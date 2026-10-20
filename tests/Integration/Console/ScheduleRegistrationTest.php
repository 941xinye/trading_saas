<?php

declare(strict_types=1);

namespace Tests\Integration\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Laravel 调度注册验收（P5.5 步骤 4 验收 #2 + spec 007 T051）：
 * schedule:list 必须包含 billing:refresh-status（每日 UTC 00:05）、
 * reconcile:run --period=5m（每 5 分钟）、billing:renewal-reminders
 * （每日 UTC 00:10，withoutOverlapping(10)）、billing:expire-invoices
 * （每 5 分钟，withoutOverlapping(5)），防止 routes/console.php 注册被误删。
 */
class ScheduleRegistrationTest extends TestCase
{
    public function test_schedule_list_contains_billing_refresh_and_reconcile(): void
    {
        $this->artisan('schedule:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('billing:refresh-status')
            ->expectsOutputToContain('reconcile:run');
    }

    public function test_renewal_reminders_and_expire_invoices_registered_with_expected_cadence(): void
    {
        // 先触发 console 调度加载
        $this->artisan('schedule:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('billing:renewal-reminders')
            ->expectsOutputToContain('billing:expire-invoices');

        $events = collect(app(Schedule::class)->events());

        $reminders = $events->first(
            fn ($event): bool => str_contains((string) $event->command, 'billing:renewal-reminders'),
        );
        static::assertNotNull($reminders, 'billing:renewal-reminders 未注册');
        static::assertSame('10 0 * * *', $reminders->expression, '应为每日 00:10');
        static::assertSame('UTC', (string) $reminders->timezone);
        static::assertTrue($reminders->withoutOverlapping);
        static::assertSame(10, $reminders->expiresAt);

        $expire = $events->first(
            fn ($event): bool => str_contains((string) $event->command, 'billing:expire-invoices'),
        );
        static::assertNotNull($expire, 'billing:expire-invoices 未注册');
        static::assertSame('*/5 * * * *', $expire->expression, '应为每 5 分钟');
        static::assertTrue($expire->withoutOverlapping);
        static::assertSame(5, $expire->expiresAt);
    }
}
