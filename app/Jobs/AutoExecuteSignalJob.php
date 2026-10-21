<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Observability\MetricsStore;
use App\Domain\Signal\Exceptions\SignalExecutionException;
use App\Domain\Signal\SignalAutoFollowAudit;
use App\Domain\Signal\SignalExecutionService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Signal;
use App\Models\StrategyInstance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 信号自动跟单 Job（orders 队列，普通 PHP 进程）。
 *
 * 触发：SignalPipeline 落库信号后，SignalAutoTrader 判定订阅开启自动执行即投递。
 *
 * 执行前二次校验（订阅可能在入队后被修改/停用）：
 *  1. 订阅仍 active 且 auto_execute=1
 *  2. 订阅配置版本与入队时一致（FR-029）
 *  3. 信号方向 long/short、置信度 ≥ execution_min_confidence
 *  4. 绑定实例 running、同租户、同交易对
 *  5. 实例当日信号单未超 execution_daily_limit（0=不限）
 *  6. 租户当日 sig_ 订单名义额未超 execution.daily_notional_cap（0=不限，FR-027）
 *
 * 下单完全复用 SignalExecutionService（TTL / 名义额 / 现货禁空 / 凭据 /
 * client_order_id 幂等 / OrderExecutor 权益与日配额闸门）。
 * tries=1：下单类 Job 不自动重试，避免交易所状态不明时的重复下单。
 * 每个决策分支写 signal_auto_follow_audits（FR-028），成交后投递成交通知（FR-030）。
 */
class AutoExecuteSignalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $signalId,
        public readonly int $subscriptionId,
        public readonly ?int $configVersion = null,
    ) {
        // Laravel 13 Queueable trait 自带 $queue 属性，不能重复声明，走 onQueue()
        $this->onQueue('orders');
    }

    public function handle(SignalExecutionService $execution, SignalAutoFollowAudit $audit, MetricsStore $metrics): void
    {
        TenantContext::initialize($this->tenantId);

        try {
            $this->execute($execution, $audit, $metrics);
        } finally {
            TenantContext::clear();
        }
    }

    private function execute(SignalExecutionService $execution, SignalAutoFollowAudit $audit, MetricsStore $metrics): void
    {
        $subscription = DB::table('signal_subscriptions')
            ->where('id', $this->subscriptionId)
            ->where('tenant_id', $this->tenantId)
            ->where('active', 1)
            ->first();

        if ($subscription === null || (int) $subscription->auto_execute !== 1) {
            $this->skip($audit, $metrics, null, 'subscription_inactive');

            return;
        }

        $instanceId = (int) $subscription->execution_instance_id;
        if ($instanceId <= 0) {
            $this->skip($audit, $metrics, null, 'instance_missing');

            return;
        }

        if ($this->configVersion !== null
            && (int) ($subscription->execution_config_version ?? 1) !== $this->configVersion) {
            $this->skip($audit, $metrics, $instanceId, 'config_version_changed');

            return;
        }

        $signal = Signal::query()
            ->where('tenant_id', $this->tenantId)
            ->find($this->signalId);

        if ($signal === null) {
            $this->skip($audit, $metrics, $instanceId, 'signal_missing');

            return;
        }

        if (! in_array((string) $signal->direction, ['long', 'short'], true)) {
            $this->skip($audit, $metrics, $instanceId, 'direction_invalid');

            return;
        }

        $minConfidence = (int) ($subscription->execution_min_confidence ?? 0);
        if ($minConfidence > 0 && (float) $signal->confidence < $minConfidence) {
            $this->skip($audit, $metrics, $instanceId, 'confidence_below_min');

            return;
        }

        $instance = StrategyInstance::query()
            ->where('tenant_id', $this->tenantId)
            ->find($instanceId);

        if ($instance === null) {
            $this->skip($audit, $metrics, $instanceId, 'instance_missing');

            return;
        }

        if ((string) $instance->status !== 'running') {
            $this->skip($audit, $metrics, $instanceId, 'instance_not_running');

            return;
        }

        if ($this->dailyLimitReached($subscription, $instanceId)) {
            $this->skip($audit, $metrics, $instanceId, 'daily_limit_reached');

            return;
        }

        $notional = (string) ($subscription->execution_notional
            ?? config('signal.execution.default_notional', '100'));

        $cap = (string) config('signal.execution.daily_notional_cap', '0');
        if (bccomp($cap, '0', 8) === 1 && $this->dailyNotionalExceeded($notional)) {
            $this->skip($audit, $metrics, $instanceId, 'daily_notional_cap_reached', $notional);

            return;
        }

        try {
            $result = $execution->execute($signal, $instance, $notional);
        } catch (SignalExecutionException $e) {
            // 预期内的拒绝（TTL/重复/额度/凭据等）：记录后可观测，不重试
            Log::warning('自动跟单被拒绝', [
                'signal_id' => $signal->id,
                'instance_id' => $instanceId,
                'conflict' => $e->conflict,
                'reason' => $e->getMessage(),
            ]);
            $audit->record(
                $this->tenantId,
                (int) $signal->id,
                $this->subscriptionId,
                $instanceId,
                SignalAutoFollowAudit::DECISION_REJECTED,
                $e->getMessage(),
                sprintf('sig_%d_%d', (int) $signal->id, $instanceId),
                $notional,
            );
            $metrics->increment('trading_signals_total', [
                'tenant_id' => $this->tenantId,
                'source' => (string) $signal->source,
                'decision' => 'rejected',
            ]);

            return;
        } catch (Throwable $e) {
            Log::error('自动跟单执行异常', [
                'signal_id' => $signal->id,
                'instance_id' => $instanceId,
                'error' => $e->getMessage(),
            ]);
            $audit->record(
                $this->tenantId,
                (int) $signal->id,
                $this->subscriptionId,
                $instanceId,
                SignalAutoFollowAudit::DECISION_FAILED,
                $e->getMessage(),
                sprintf('sig_%d_%d', (int) $signal->id, $instanceId),
                $notional,
            );
            $metrics->increment('trading_signals_total', [
                'tenant_id' => $this->tenantId,
                'source' => (string) $signal->source,
                'decision' => 'failed',
            ]);

            throw $e;
        }

        $order = $result['order'];

        Log::info('自动跟单已下单', [
            'signal_id' => $signal->id,
            'subscription_id' => $this->subscriptionId,
            'instance_id' => $instanceId,
            'client_order_id' => $result['client_order_id'],
            'side' => $result['side'],
            'notional' => $result['notional'],
            'status' => $order->status,
        ]);

        $audit->record(
            $this->tenantId,
            (int) $signal->id,
            $this->subscriptionId,
            $instanceId,
            SignalAutoFollowAudit::DECISION_EXECUTED,
            null,
            $result['client_order_id'],
            $result['notional'],
            $result['reference_price'],
        );

        $metrics->increment('trading_signals_total', [
            'tenant_id' => $this->tenantId,
            'source' => (string) $signal->source,
            'decision' => 'executed',
        ]);

        $this->notifyExecuted($subscription, $signal, $result, $instanceId);
    }

    /**
     * 务实决策审计：跳过分支统一入口。
     */
    private function skip(
        SignalAutoFollowAudit $audit,
        MetricsStore $metrics,
        ?int $instanceId,
        string $reason,
        ?string $notional = null,
    ): void {
        $audit->record(
            $this->tenantId,
            $this->signalId,
            $this->subscriptionId,
            $instanceId,
            SignalAutoFollowAudit::DECISION_SKIPPED,
            $reason,
            null,
            $notional,
        );

        $metrics->increment('trading_signals_total', [
            'tenant_id' => $this->tenantId,
            'source' => 'unknown',
            'decision' => 'skipped',
        ]);

        Log::info('自动跟单跳过：'.$reason, [
            'signal_id' => $this->signalId,
            'subscription_id' => $this->subscriptionId,
            'instance_id' => $instanceId,
        ]);
    }

    /**
     * 实例当日已占用的信号单数（人工 + 自动共用同一 client_order_id 前缀 sig_）。
     *
     * 计数只算已受理状态，失败单不占用额度。
     */
    private function dailyLimitReached(object $subscription, int $instanceId): bool
    {
        $limit = (int) ($subscription->execution_daily_limit ?? 0);
        if ($limit <= 0) {
            return false;
        }

        $used = DB::table('strategy_orders')
            ->where('tenant_id', $this->tenantId)
            ->where('strategy_instance_id', $instanceId)
            ->whereRaw("substr(client_order_id, 1, 4) = 'sig_'")
            ->whereIn('status', ['pending', 'submitted', 'partial', 'filled'])
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return $used >= $limit;
    }

    /**
     * 租户当日 sig_ 订单名义额汇总（人工 + 自动），加上本次名义额是否超上限。
     *
     * 名义额优先取 notional 快照，缺失时回退 filled_qty × 成交均价。
     */
    private function dailyNotionalExceeded(string $notional): bool
    {
        $cap = (string) config('signal.execution.daily_notional_cap', '0');
        if (bccomp($cap, '0', 8) !== 1) {
            return false;
        }

        $rows = DB::table('strategy_orders')
            ->where('tenant_id', $this->tenantId)
            ->whereRaw("substr(client_order_id, 1, 4) = 'sig_'")
            ->whereIn('status', ['pending', 'submitted', 'partial', 'filled'])
            ->where('created_at', '>=', now()->startOfDay())
            ->get(['notional', 'quantity', 'avg_fill_price']);

        $used = '0';
        foreach ($rows as $row) {
            $value = $row->notional;
            if ($value === null && $row->avg_fill_price !== null) {
                $value = bcmul((string) $row->quantity, (string) $row->avg_fill_price, 8);
            }
            if ($value !== null) {
                $used = bcadd($used, (string) $value, 8);
            }
        }

        return bccomp(bcadd($used, $notional, 8), $cap, 8) === 1;
    }

    /**
     * 成交通知：只投递 Job，绝不因通知失败影响已成交订单。
     *
     * @param  array<string, mixed>  $result
     */
    private function notifyExecuted(
        object $subscription,
        Signal $signal,
        array $result,
        int $instanceId,
    ): void {
        $channels = $subscription->channels;
        if (is_string($channels)) {
            $channels = json_decode($channels, true);
        }
        $channels = is_array($channels) ? array_values(array_unique(array_map('strval', $channels))) : [];

        if ($channels === []) {
            return;
        }

        $order = $result['order'];

        try {
            NotifyAutoFollowExecutedJob::dispatch($this->tenantId, $channels, [
                'signal_id' => (int) $signal->id,
                'subscription_id' => $this->subscriptionId,
                'instance_id' => $instanceId,
                'symbol' => (string) $signal->symbol,
                'direction' => (string) $signal->direction,
                'side' => $result['side'],
                'notional' => $result['notional'],
                'quantity' => (string) $order->quantity,
                'fill_price' => $order->avgFillPrice,
                'status' => (string) $order->status,
                'client_order_id' => $result['client_order_id'],
            ]);
        } catch (Throwable $e) {
            Log::warning('自动跟单成交通知入队失败（订单已成交）', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
