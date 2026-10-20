<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payment\Contracts\PaymentChannelRegistry;
use App\Domain\Payment\Dto\PaymentStatus;
use App\Domain\Payment\Dto\WebhookEvent;
use App\Domain\Payment\PaymentActivationService;
use App\Domain\Payment\PaymentStateMachine;
use App\Models\PaymentInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * 支付通道 webhook 端点（webhook-contract.md §2 固定流水线，FR-003/004/005/012）。
 *
 * 验签即鉴权：不挂 auth:sanctum / tenant 中间件，路由注册在既有分组之外。
 *
 *   ① 解析 {channel} → Registry（未知 → 404）
 *   ② verifyWebhook 失败 → 401 + rejected 审计（零状态变更）
 *   ③ parseWebhook 不可解析 → 200 + rejected 审计
 *   ④ ref 白名单校验 + 定位支付单（缺失/非法/不存在 → 200 + rejected + error 留痕，不重试）
 *   ⑤ Redis 单飞锁（拿不到 → 200 idempotent_skipped；削峰非正确性依赖，Redis 不可用降级直接处理）
 *   ⑥ 状态机决策 → 激活走 PaymentActivationService（事务内①②③）；
 *      非激活迁移走条件更新（只进不退）；异常 → 回滚 + 500（触发通道重投）
 *   ⑦ 释放锁；响应码见契约 §5（200 终局 / 401 签名非法 / 500 可重试）
 */
class BillingWebhookController extends Controller
{
    /**
     * 单飞锁键名模式（固化为类常量：测试按已知逻辑名精确清理，constitution VI）。
     * sprintf 参数：tenant_id, ref。
     */
    public const LOCK_KEY_PATTERN = 'tenant:%d:payment:webhook:%s';

    /** 锁 TTL（秒）：兜底死锁，远长于正常处理耗时。 */
    public const LOCK_TTL_SECONDS = 15;

    public function __construct(
        private readonly PaymentChannelRegistry $channels,
        private readonly PaymentActivationService $activation,
    ) {}

    public function __invoke(Request $request, string $channel): JsonResponse
    {
        // ① 通道解析（未知 channel → 404，不回落默认通道）
        try {
            $channelImpl = $this->channels->for($channel);
        } catch (RuntimeException) {
            return response()->json(['ok' => false], 404);
        }

        $rawBody = (string) $request->getContent();
        $headers = $request->headers->all();

        // ② 验签（对原始字节；失败 → 401 + 审计，零状态变更）
        if (! $channelImpl->verifyWebhook($rawBody, $headers)) {
            $this->audit($channel, 'unverified', false, 'rejected', $rawBody, 'invalid_signature');

            return response()->json(['ok' => false, 'reason' => 'invalid_signature'], 401);
        }

        // ③ 解析（不可解析 → 200 + rejected 审计，不让通道重投垃圾载荷）
        try {
            $event = $channelImpl->parseWebhook($rawBody, $headers);
        } catch (Throwable) {
            $this->audit($channel, 'unparsable', true, 'rejected', $rawBody, 'unparsable');

            return response()->json(['ok' => false, 'reason' => 'unparsable'], 200);
        }

        // ④ ref 白名单（T038 安全红线：长度 ≤64 且 ^pi_[0-9a-z]{26}$，防注入/超长）+ 定位支付单
        $ref = $event->ref;
        $refValid = is_string($ref) && preg_match('/^pi_[0-9a-z]{26}$/', $ref) === 1;
        $invoice = $refValid
            ? PaymentInvoice::query()->withoutGlobalScopes()->where('ref', $ref)->first()
            : null;
        if ($invoice === null) {
            $this->audit(
                $channel,
                $event->eventType,
                true,
                'rejected',
                $rawBody,
                $refValid ? 'invoice_not_found' : 'invalid_ref',
                $ref !== null ? substr($ref, 0, 64) : null,
            );

            return response()->json(['ok' => false, 'reason' => 'invoice_not_found'], 200);
        }

        // ⑤ 单飞锁（削峰非正确性依赖；Redis 不可用 → 降级直接处理，T037）
        $lockKey = sprintf(self::LOCK_KEY_PATTERN, $invoice->tenant_id, $invoice->ref);
        $lockToken = null;
        $lockConflict = false;
        try {
            $token = bin2hex(random_bytes(8));
            $acquired = Redis::connection('default')->set($lockKey, $token, 'EX', self::LOCK_TTL_SECONDS, 'NX');
            if ($acquired === true || $acquired === 'OK' || $acquired === 1) {
                $lockToken = $token;
            } else {
                $lockConflict = true;
            }
        } catch (Throwable) {
            // Redis 不可用：降级为直接处理（正确性由条件更新 + processed_key 唯一键兜底）
        }

        if ($lockConflict) {
            $this->audit($channel, $event->eventType, true, 'idempotent_skipped', $rawBody, null, $event->ref, $invoice);

            return response()->json(['ok' => true, 'result' => 'idempotent_skipped']);
        }

        try {
            return $this->process($channel, $invoice, $event, $rawBody);
        } finally {
            // ⑦ 释放锁（token 比对防误删他人锁；Redis 降级路径 token 为 null 跳过）
            if ($lockToken !== null) {
                try {
                    Redis::connection('default')->eval(
                        'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end',
                        1,
                        $lockKey,
                        $lockToken,
                    );
                } catch (Throwable) {
                    // 释放失败由 TTL 兜底
                }
            }
        }
    }

    /**
     * ⑥ 状态机决策与执行（在单飞锁保护内调用）。
     */
    private function process(string $channel, PaymentInvoice $invoice, WebhookEvent $event, string $rawBody): JsonResponse
    {
        // 状态机决策（null = 未知事件/乱序/终态重复 → 幂等跳过）
        $transition = PaymentStateMachine::forChannel($channel)->decide(
            PaymentStatus::from((string) $invoice->status),
            $event->eventType,
            $event->paidAmount,
            (string) $invoice->amount_usdt,
        );
        if ($transition === null) {
            $this->audit($channel, $event->eventType, true, 'idempotent_skipped', $rawBody, null, $event->ref, $invoice);

            return response()->json(['ok' => true, 'result' => 'idempotent_skipped']);
        }

        // 足额到账 → 事务内激活（条件更新 + provision + 审计行一体）
        if ($transition->activate) {
            try {
                $activated = $this->activation->activate(
                    $invoice,
                    $event->paidAmount ?? (string) $invoice->amount_usdt,
                    $event->eventType,
                    $rawBody,
                );
            } catch (Throwable $e) {
                report($e);

                return response()->json(['ok' => false, 'reason' => 'processing_error'], 500);
            }

            if (! $activated) {
                $this->audit($channel, $event->eventType, true, 'idempotent_skipped', $rawBody, null, $event->ref, $invoice);

                return response()->json(['ok' => true, 'result' => 'idempotent_skipped']);
            }

            return response()->json(['ok' => true, 'result' => 'processed']);
        }

        // 非激活迁移（processing / partial / expired / manual）：条件更新 + 审计行同事务
        $fromStates = array_map(fn (PaymentStatus $s): string => $s->value, $transition->fromStates);

        try {
            $affected = DB::transaction(function () use ($invoice, $transition, $fromStates, $event, $channel, $rawBody): int {
                $now = Carbon::now('UTC');
                $updates = [
                    'status' => $transition->target->value,
                    'updated_at' => $now,
                ];
                // 记录实付进度（partial / processing 事件带金额时）
                if ($event->paidAmount !== null && in_array($transition->target, [PaymentStatus::Partial, PaymentStatus::Processing], true)) {
                    $updates['paid_amount_usdt'] = $event->paidAmount;
                }

                $affected = DB::table('payment_invoices')
                    ->where('id', $invoice->getKey())
                    ->where('ref', $invoice->ref)
                    ->whereIn('status', $fromStates)
                    ->update($updates);

                $this->audit(
                    $channel,
                    $event->eventType,
                    true,
                    $affected > 0 ? 'processed' : 'idempotent_skipped',
                    $rawBody,
                    null,
                    $event->ref,
                    $invoice,
                );

                return $affected;
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'reason' => 'processing_error'], 500);
        }

        return response()->json(['ok' => true, 'result' => $affected > 0 ? 'processed' : 'idempotent_skipped']);
    }

    /**
     * 落 payment_webhook_events 审计行（raw_payload 原样留存，FR-004）。
     */
    private function audit(
        string $channel,
        string $eventType,
        bool $signatureValid,
        string $status,
        string $rawPayload,
        ?string $error = null,
        ?string $ref = null,
        ?PaymentInvoice $invoice = null,
    ): void {
        $now = Carbon::now('UTC');

        DB::table('payment_webhook_events')->insert([
            'tenant_id' => $invoice?->tenant_id,
            'payment_invoice_id' => $invoice?->getKey(),
            'channel_code' => $channel,
            'ref' => $ref,
            'event_type' => $eventType,
            'signature_valid' => $signatureValid,
            'status' => $status,
            'processed_key' => null,
            'raw_payload' => $rawPayload !== '' && json_validate($rawPayload) ? $rawPayload : '{}',
            'error' => $error,
            'received_at' => $now,
            'processed_at' => $status === 'processed' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
