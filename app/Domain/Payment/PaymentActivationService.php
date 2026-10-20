<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Billing\SubscriptionService;
use App\Models\PaymentInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 支付确认 → 订阅激活（唯一入口，webhook-contract.md §4，FR-006 / constitution IV）。
 *
 * 单事务固定执行序：
 *   ① 支付单条件更新（confirmed + paid_amount + confirmed_at；affected=0 ⇒ 返回 false 不接线）
 *   ② SubscriptionService::provision(tenant, plan, trialDays: 0)
 *   ③ payment_webhook_events 落 processed 行（processed_key "{ref}:{event_type}" 唯一兜底）
 *
 * 通道 IO 与通知投递一律在事务外；任一步异常整体回滚 —— 不存在半激活，
 * 上层 500 让通道重投，重入后①命中失败即幂等跳过。
 */
final class PaymentActivationService
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * @param  string  $paidAmount  实付金额（bcmath 字符串，足额/超额）
     * @param  string  $rawPayload  原始请求体（审计原样留存，FR-004）
     * @return bool true = 本次完成激活；false = 已处理过/状态不命中（幂等跳过）
     */
    public function activate(
        PaymentInvoice $invoice,
        string $paidAmount,
        string $eventType,
        string $rawPayload = '',
    ): bool {
        return DB::transaction(function () use ($invoice, $paidAmount, $eventType, $rawPayload): bool {
            $now = Carbon::now('UTC');

            // ① 条件更新（幂等守卫：状态只进不退，一次性迁移）
            $affected = DB::table('payment_invoices')
                ->where('id', $invoice->getKey())
                ->where('ref', $invoice->ref)
                ->whereIn('status', ['pending', 'processing', 'partial'])
                ->update([
                    'status' => 'confirmed',
                    'paid_amount_usdt' => $paidAmount,
                    'confirmed_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($affected === 0) {
                return false;
            }

            // ② 接线订阅（复用 P3：tenant active + plan_code 更新 + 新建 active 订阅行）
            $this->subscriptions->provision($invoice->tenant_id, $invoice->plan_code, 0);

            // ③ 审计行（processed_key 唯一：重放撞键即异常回滚，并发双保险）
            DB::table('payment_webhook_events')->insert([
                'tenant_id' => $invoice->tenant_id,
                'payment_invoice_id' => $invoice->getKey(),
                'channel_code' => $invoice->channel_code,
                'ref' => $invoice->ref,
                'event_type' => $eventType,
                'signature_valid' => true,
                'status' => 'processed',
                'processed_key' => $invoice->ref.':'.$eventType,
                // webhook 路径必为合法 JSON（parseWebhook 已解码成功）；空串兜底防 MySQL json 列报错
                'raw_payload' => $rawPayload !== '' ? $rawPayload : '{}',
                'error' => null,
                'received_at' => $now,
                'processed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return true;
        });
    }
}
