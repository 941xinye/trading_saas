<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Observability\MetricsStore;
use App\Jobs\AutoExecuteSignalJob;
use Illuminate\Support\Facades\Log;

/**
 * 信号自动跟单入口（P2 延伸第二阶段）。
 *
 * 在 SignalPipeline 落库信号后判定订阅是否开启自动执行，满足条件即投递
 * AutoExecuteSignalJob（orders 队列）。真正的下单与全部安全校验仍在 Job 内
 * 通过 SignalExecutionService 完成，本类只做「轻量前置判定 + 入队」，
 * 保证 ai 队列 worker 不直接触碰交易所。
 *
 * 与人工一键下单共用：
 *  - 同一信号同一实例 client_order_id=sig_{signal}_{instance} 幂等
 *  - TTL / running / 同交易对 / 现货禁空 / 名义额上限 / 权益与日配额闸门
 */
final class SignalAutoTrader
{
    public function __construct(
        private readonly SignalAutoFollowAudit $audit,
        private readonly MetricsStore $metrics,
    ) {}

    /**
     * 订阅级前置判定（不查库，仅用已经加载的订阅行）。
     *
     * @param  object  $signal  SignalPipeline 回填持久化信息的 DTO（Signals\Contracts\Signal），或 signals 模型
     * @param  object  $subscription  signal_subscriptions 行（DB::table 裸对象或模型）
     */
    public function isEligible(object $signal, object $subscription): bool
    {
        if ((int) ($subscription->auto_execute ?? 0) !== 1) {
            return false;
        }

        if ((int) ($subscription->execution_instance_id ?? 0) <= 0) {
            return false;
        }

        if (! in_array((string) $signal->direction, ['long', 'short'], true)) {
            return false;
        }

        $minConfidence = (int) ($subscription->execution_min_confidence ?? 0);
        if ($minConfidence > 0 && (float) $signal->confidence < $minConfidence) {
            return false;
        }

        return true;
    }

    /**
     * 满足条件时投递自动跟单 Job。
     *
     * @return bool 是否已入队
     */
    public function maybeQueue(object $signal, object $subscription): bool
    {
        $tenantId = $this->resolveTenantId($signal);
        $source = (string) ($signal->source ?? 'unknown');

        if (! $this->isEligible($signal, $subscription)) {
            $this->metrics->increment('trading_signals_total', [
                'tenant_id' => $tenantId, 'source' => $source, 'decision' => 'skipped',
            ]);

            return false;
        }

        $signalId = $this->resolveSignalId($signal);

        AutoExecuteSignalJob::dispatch(
            $tenantId,
            $signalId,
            (int) $subscription->id,
            (int) ($subscription->execution_config_version ?? 1),
        );

        $this->metrics->increment('trading_signals_total', [
            'tenant_id' => $tenantId, 'source' => $source, 'decision' => 'queued',
        ]);

        $this->audit->record(
            $tenantId,
            $signalId,
            (int) $subscription->id,
            (int) $subscription->execution_instance_id,
            SignalAutoFollowAudit::DECISION_QUEUED,
        );

        Log::info('信号自动跟单已入队', [
            'signal_id' => $signalId,
            'subscription_id' => (int) $subscription->id,
            'instance_id' => (int) $subscription->execution_instance_id,
            'direction' => (string) $signal->direction,
            'confidence' => (float) $signal->confidence,
            'config_version' => (int) ($subscription->execution_config_version ?? 1),
        ]);

        return true;
    }

    /**
     * DTO 用 tenantId，模型用 tenant_id。
     */
    private function resolveTenantId(object $signal): int
    {
        return (int) ($signal->tenantId ?? $signal->tenant_id ?? 0);
    }

    /**
     * DTO 用 persistedId（落库回填），模型用 id。
     */
    private function resolveSignalId(object $signal): int
    {
        return (int) ($signal->persistedId ?? $signal->id ?? 0);
    }
}
