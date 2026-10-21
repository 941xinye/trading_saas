<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 自动跟单决策回放（FR-028）。
 *
 * 按信号 ID 输出 signal_auto_follow_audits 全量决策链 + 对应 strategy_orders，
 * 用于排查「为什么没下单 / 哪一步被拒 / 入队后配置是否变更」。
 */
class SignalAutoFollowTraceCommand extends Command
{
    protected $signature = 'signal:auto-follow-trace
                            {signalId : 信号 ID}
                            {--tenant= : 租户 ID（可选；缺省按信号归属自动识别）}';

    protected $description = 'Replay auto-follow decision audits for a signal';

    public function handle(): int
    {
        $signalId = (int) $this->argument('signalId');
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        $signal = DB::table('signals')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('id', $signalId)
            ->first(['id', 'tenant_id', 'symbol', 'interval', 'direction', 'confidence', 'triggered_at']);

        if ($signal === null) {
            $this->error("信号 #{$signalId} 不存在".($tenantId !== null ? "（租户 #{$tenantId}）" : ''));

            return self::FAILURE;
        }

        $tenantId = (int) $signal->tenant_id;

        $this->info("=== 信号 #{$signalId} 自动跟单决策链 ===");
        $this->line("  tenant:     #{$tenantId}");
        $this->line("  symbol:     {$signal->symbol} / {$signal->interval}");
        $this->line("  direction:  {$signal->direction} (confidence {$signal->confidence})");
        $this->line("  triggered:  {$signal->triggered_at}");

        $audits = DB::table('signal_auto_follow_audits')
            ->where('tenant_id', $tenantId)
            ->where('signal_id', $signalId)
            ->orderBy('id')
            ->get([
                'id', 'subscription_id', 'instance_id', 'decision', 'reason',
                'client_order_id', 'notional', 'reference_price', 'created_at',
            ]);

        $this->newLine();
        $this->info('--- 决策审计 ---');
        if ($audits->isEmpty()) {
            $this->line('  （无审计记录：订阅未开启自动跟单，或审计表启用前的历史信号）');
        } else {
            $this->table(
                ['id', 'subscription', 'instance', 'decision', 'reason', 'client_order_id', 'notional', 'ref_price', 'created_at'],
                $audits->map(fn (object $row): array => [
                    $row->id,
                    $row->subscription_id ?? '-',
                    $row->instance_id ?? '-',
                    $row->decision,
                    $row->reason ?? '-',
                    $row->client_order_id ?? '-',
                    $row->notional ?? '-',
                    $row->reference_price ?? '-',
                    $row->created_at,
                ])->all(),
            );
        }

        $orders = DB::table('strategy_orders')
            ->where('tenant_id', $tenantId)
            ->where('client_order_id', 'like', "sig_{$signalId}_%")
            ->orderBy('id')
            ->get(['id', 'strategy_instance_id', 'client_order_id', 'side', 'status', 'quantity', 'notional', 'avg_fill_price', 'error_message', 'created_at']);

        $this->newLine();
        $this->info('--- 关联订单 ---');
        if ($orders->isEmpty()) {
            $this->line('  （无订单）');
        } else {
            $this->table(
                ['id', 'instance', 'client_order_id', 'side', 'status', 'qty', 'notional', 'avg_fill_price', 'error', 'created_at'],
                $orders->map(fn (object $row): array => [
                    $row->id,
                    $row->strategy_instance_id,
                    $row->client_order_id,
                    $row->side,
                    $row->status,
                    $row->quantity,
                    $row->notional ?? '-',
                    $row->avg_fill_price ?? '-',
                    $row->error_message ?? '-',
                    $row->created_at,
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
