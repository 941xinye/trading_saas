<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 自动跟单决策审计（FR-028）。
 *
 * 每次入队 / 跳过 / 拒绝 / 成交 / 异常落一行，供按信号或订阅回放完整决策链。
 * 审计写入失败只记 warning，绝不影响下单主链路。
 */
final class SignalAutoFollowAudit
{
    public const DECISION_QUEUED = 'queued';

    public const DECISION_SKIPPED = 'skipped';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_EXECUTED = 'executed';

    public const DECISION_FAILED = 'failed';

    public function record(
        int $tenantId,
        int $signalId,
        ?int $subscriptionId,
        ?int $instanceId,
        string $decision,
        ?string $reason = null,
        ?string $clientOrderId = null,
        ?string $notional = null,
        ?string $referencePrice = null,
    ): void {
        try {
            DB::table('signal_auto_follow_audits')->insert([
                'tenant_id' => $tenantId,
                'signal_id' => $signalId,
                'subscription_id' => $subscriptionId,
                'instance_id' => $instanceId,
                'decision' => $decision,
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'client_order_id' => $clientOrderId !== null ? mb_substr($clientOrderId, 0, 60) : null,
                'notional' => $notional,
                'reference_price' => $referencePrice,
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('自动跟单审计写入失败（不影响主链路）', [
                'signal_id' => $signalId,
                'decision' => $decision,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
