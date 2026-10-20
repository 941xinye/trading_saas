<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ---------------------------------------------------------------------
// P5.5 步骤 4：每日运维调度（全部 UTC；服务器 crontab 需挂 schedule:run，
// 见 docs/overseas-node-runbook.md「调度」一节）
// ---------------------------------------------------------------------

// 订阅状态每日刷新：到期 trialing/active → past_due，超宽限 → suspended（C3）
Schedule::command('billing:refresh-status')
    ->dailyAt('00:05')
    ->timezone('UTC')
    ->withoutOverlapping(10);

// 账实对账每 5 分钟全量可对账实例（A1/A3；只报告+暂停，绝不自动改账）
Schedule::command('reconcile:run --period=5m')
    ->everyFiveMinutes()
    ->timezone('UTC')
    ->withoutOverlapping(10);

// 订阅续费提醒每日扫描：到期前 7/3/1 天落 queued 行并投递发送 Job（spec 007 US3 / FR-007）
Schedule::command('billing:renewal-reminders')
    ->dailyAt('00:10')
    ->timezone('UTC')
    ->withoutOverlapping(10);

// 非终态过期支付单清扫：pending/processing/partial 且 expires_at < now → expired（FR-012，契约 §7）
Schedule::command('billing:expire-invoices')
    ->everyFiveMinutes()
    ->withoutOverlapping(5);

// 回测崩溃收割：running 且 updated_at 停滞超 stale_after_sec → failed（spec 008 US3 / T037）
Schedule::command('backtest:reap-stale')
    ->everyFiveMinutes()
    ->timezone('UTC')
    ->withoutOverlapping(5);
