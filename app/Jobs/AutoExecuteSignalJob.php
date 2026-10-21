<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Signal\Exceptions\SignalExecutionException;
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
 *  2. 信号方向 long/short、置信度 ≥ execution_min_confidence
 *  3. 绑定实例 running、同租户、同交易对
 *  4. 实例当日信号单未超 execution_daily_limit（0=不限）
 *
 * 下单完全复用 SignalExecutionService（TTL / 名义额 / 现货禁空 / 凭据 /
 * client_order_id 幂等 / OrderExecutor 权益与日配额闸门）。
 * tries=1：下单类 Job 不自动重试，避免交易所状态不明时的重复下单。
 */
class AutoExecuteSignalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $signalId,
        public readonly int $subscriptionId,
    ) {
        // Laravel 13 Queueable trait 自带 $queue 属性，不能重复声明，走 onQueue()
        $this->onQueue('orders');
    }

    public function handle(SignalExecutionService $execution): void
    {
        TenantContext::initialize($this->tenantId);

        try {
            $this->execute($execution);
        } finally {
            TenantContext::clear();
        }
    }

    private function execute(SignalExecutionService $execution): void
    {
        $subscription = DB::table('signal_subscriptions')
            ->where('id', $this->subscriptionId)
            ->where('tenant_id', $this->tenantId)
            ->where('active', 1)
            ->first();

        if ($subscription === null || (int) $subscription->auto_execute !== 1) {
            Log::info('自动跟单跳过：订阅不存在、已停用或已关闭自动执行', [
                'subscription_id' => $this->subscriptionId,
                'signal_id' => $this->signalId,
            ]);

            return;
        }

        $instanceId = (int) $subscription->execution_instance_id;
        if ($instanceId <= 0) {
            Log::warning('自动跟单跳过：订阅未绑定执行实例', [
                'subscription_id' => $this->subscriptionId,
            ]);

            return;
        }

        $signal = Signal::query()
            ->where('tenant_id', $this->tenantId)
            ->find($this->signalId);

        if ($signal === null) {
            Log::info('自动跟单跳过：信号不存在', ['signal_id' => $this->signalId]);

            return;
        }

        if (! in_array((string) $signal->direction, ['long', 'short'], true)) {
            Log::info('自动跟单跳过：信号方向不可执行', [
                'signal_id' => $signal->id,
                'direction' => $signal->direction,
            ]);

            return;
        }

        $minConfidence = (int) ($subscription->execution_min_confidence ?? 0);
        if ($minConfidence > 0 && (float) $signal->confidence < $minConfidence) {
            Log::info('自动跟单跳过：信号置信度低于订阅阈值', [
                'signal_id' => $signal->id,
                'confidence' => (float) $signal->confidence,
                'min_confidence' => $minConfidence,
            ]);

            return;
        }

        $instance = StrategyInstance::query()
            ->where('tenant_id', $this->tenantId)
            ->find($instanceId);

        if ($instance === null) {
            Log::warning('自动跟单跳过：绑定实例不存在', [
                'subscription_id' => $this->subscriptionId,
                'instance_id' => $instanceId,
            ]);

            return;
        }

        if ((string) $instance->status !== 'running') {
            Log::info('自动跟单跳过：实例未运行', [
                'signal_id' => $signal->id,
                'instance_id' => $instanceId,
                'status' => $instance->status,
            ]);

            return;
        }

        if ($this->dailyLimitReached($subscription, $instanceId)) {
            Log::warning('自动跟单跳过：实例当日信号单已达上限', [
                'subscription_id' => $this->subscriptionId,
                'instance_id' => $instanceId,
                'daily_limit' => (int) ($subscription->execution_daily_limit ?? 0),
            ]);

            return;
        }

        $notional = (string) ($subscription->execution_notional
            ?? config('signal.execution.default_notional', '100'));

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

            return;
        } catch (Throwable $e) {
            Log::error('自动跟单执行异常', [
                'signal_id' => $signal->id,
                'instance_id' => $instanceId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::info('自动跟单已下单', [
            'signal_id' => $signal->id,
            'subscription_id' => $this->subscriptionId,
            'instance_id' => $instanceId,
            'client_order_id' => $result['client_order_id'],
            'side' => $result['side'],
            'notional' => $result['notional'],
            'status' => $result['order']->status,
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
}
