<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P3 POC 双租户信号链路实跑：
 *  - 确保第二租户 poc-billing（free）及其 BTCUSDT/5m/log active 订阅就位
 *  - 与 tenant#1 的既有订阅共用同一条 market:feed WS（按 symbol+interval 分组订阅）
 *  - 下一根 5m 收盘后，两租户各自产生 meter_events(ai_call)，Redis 计数桶互不串
 *
 * 运维步骤（WSL）：
 *   php artisan poc:billing-demo
 *   # 重启 market:feed 与 queue worker（新构造依赖 QuotaGuard）
 *   # 等待下一个 5m 整点后：
 *   php artisan poc:billing-demo --status
 *
 * 清理：php artisan poc:billing-demo --cleanup
 */
class PocBillingDemoCommand extends Command
{
    protected $signature = 'poc:billing-demo
        {--cleanup : 停用第二租户订阅并删除 poc-billing 租户}
        {--status : 查看双租户当日计量与最近信号}';

    protected $description = 'POC: provision second tenant for live two-tenant signal run';

    private const SLUG = 'poc-billing';

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }
        if ($this->option('status')) {
            return $this->status();
        }

        return $this->provision();
    }

    private function provision(): int
    {
        $now = Carbon::now();

        $tenant = DB::table('tenants')->where('slug', self::SLUG)->first();
        if ($tenant === null) {
            $tenantId = (int) DB::table('tenants')->insertGetId([
                'name' => 'POC Billing Tenant',
                'slug' => self::SLUG,
                'exchange_dek' => '',
                'status' => 'active',
                'plan_code' => 'free',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->info("租户 poc-billing 已创建（id={$tenantId}, plan=free）");
        } else {
            $tenantId = (int) $tenant->id;
            DB::table('tenants')->where('id', $tenantId)->update(['status' => 'active', 'updated_at' => $now]);
            $this->info("租户 poc-billing 已存在（id={$tenantId}），确保 active");
        }

        $name = 'poc-signal-BTCUSDT';
        $existing = DB::table('signal_subscriptions')->where('tenant_id', $tenantId)->where('name', $name)->first();
        $payload = [
            'symbols' => json_encode(['BTCUSDT']),
            'intervals' => json_encode(['5m']),
            'ai_prompter' => 'deepseek',
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(['log']),
            'active' => 1,
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $subId = (int) DB::table('signal_subscriptions')->insertGetId($payload + [
                'tenant_id' => $tenantId,
                'name' => $name,
                'ai_model' => null,
                'created_at' => $now,
            ]);
        } else {
            $subId = (int) $existing->id;
            DB::table('signal_subscriptions')->where('id', $subId)->update($payload);
        }
        $this->info("信号订阅已就位（id={$subId}, BTCUSDT/5m/log, active）");

        $this->newLine();
        $this->info('当前双租户实跑拓扑：');
        $rows = DB::table('signal_subscriptions')
            ->whereIn('tenant_id', [1, $tenantId])
            ->where('active', 1)
            ->get(['tenant_id', 'id', 'name', 'symbols', 'intervals', 'channels'])
            ->map(fn ($r) => [
                $r->tenant_id, $r->id, $r->name, $r->symbols, $r->intervals, $r->channels,
            ])->all();
        $this->table(['tenant', 'sub#', 'name', 'symbols', 'intervals', 'channels'], $rows);

        $this->newLine();
        $this->line('Next（WSL）:');
        $this->line('  1) 重启 php artisan market:feed                         # 重新加载双订阅，WS 只建一条');
        $this->line('  2) 重启 php artisan queue:work --queue=ai,notifications  # 加载 P3 QuotaGuard 依赖');
        $this->line('  3) 等下一个 5m 整点，php artisan poc:billing-demo --status');

        return self::SUCCESS;
    }

    private function status(): int
    {
        $tenant = DB::table('tenants')->where('slug', self::SLUG)->first();
        if ($tenant === null) {
            $this->warn('poc-billing 租户不存在，请先运行 php artisan poc:billing-demo');

            return self::FAILURE;
        }

        $day = Carbon::now('UTC')->format('Y-m-d');
        $dayStart = $day . ' 00:00:00';
        $dayEnd = $day . ' 23:59:59';

        $meterRows = DB::table('meter_events')
            ->whereIn('tenant_id', [1, (int) $tenant->id])
            ->whereBetween('occurred_at', [$dayStart, $dayEnd])
            ->select('tenant_id', 'event_type', 'status', DB::raw('COUNT(*) as c'))
            ->groupBy('tenant_id', 'event_type', 'status')
            ->orderBy('tenant_id')->orderBy('event_type')->orderBy('status')
            ->get()
            ->map(fn ($r) => [$r->tenant_id, $r->event_type, $r->status, $r->c])->all();

        $this->info('当日（UTC ' . $day . '）计量事件：');
        $this->table(['tenant', 'event', 'status', 'count'], $meterRows !== [] ? $meterRows : [['-', '-', '-', '0']]);

        $recent = DB::table('signals')
            ->whereIn('tenant_id', [1, (int) $tenant->id])
            ->orderByDesc('id')->limit(10)
            ->get(['tenant_id', 'symbol', 'interval', 'source', 'direction', 'confidence', 'created_at'])
            ->map(fn ($r) => [
                $r->tenant_id, $r->symbol, $r->interval, $r->source,
                $r->direction, $r->confidence, $r->created_at,
            ])->all();
        $this->newLine();
        $this->info('最近 10 条信号：');
        $this->table(['tenant', 'symbol', 'interval', 'source', 'dir', 'conf', 'at'], $recent);

        return self::SUCCESS;
    }

    private function cleanup(): int
    {
        $tenant = DB::table('tenants')->where('slug', self::SLUG)->first();
        if ($tenant === null) {
            $this->warn('poc-billing 租户不存在，无需清理');

            return self::SUCCESS;
        }
        $tenantId = (int) $tenant->id;

        $signalIds = DB::table('signals')->where('tenant_id', $tenantId)->pluck('id')->all();
        if ($signalIds !== []) {
            DB::table('signal_notifications')->whereIn('signal_id', $signalIds)->delete();
        }
        DB::table('signals')->where('tenant_id', $tenantId)->delete();
        DB::table('signal_subscriptions')->where('tenant_id', $tenantId)->delete();
        DB::table('meter_events')->where('tenant_id', $tenantId)->delete();
        DB::table('subscriptions')->where('tenant_id', $tenantId)->delete();
        DB::table('exchange_accounts')->where('tenant_id', $tenantId)->delete();
        DB::table('tenants')->where('id', $tenantId)->delete();

        $day = Carbon::now('UTC')->format('Ymd');
        foreach (['ai_call', 'order_placed'] as $event) {
            \Illuminate\Support\Facades\Redis::connection('default')
                ->del("tenant:{$tenantId}:meter:{$event}:{$day}");
        }

        $this->info("poc-billing 租户（id={$tenantId}）及其订阅/信号/计量数据已清理");

        return self::SUCCESS;
    }
}
