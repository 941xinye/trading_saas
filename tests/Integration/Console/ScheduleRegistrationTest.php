<?php

declare(strict_types=1);

namespace Tests\Integration\Console;

use Tests\TestCase;

/**
 * Laravel 调度注册验收（P5.5 步骤 4 验收 #2）：
 * schedule:list 必须包含 billing:refresh-status（每日 UTC 00:05）与
 * reconcile:run --period=5m（每 5 分钟）两条目，防止 routes/console.php 注册被误删。
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
}
