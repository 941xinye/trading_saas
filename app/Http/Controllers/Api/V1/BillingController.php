<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Billing\Plans\PlanService;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Payment\PaymentInvoiceService;
use App\Domain\Tenancy\TenantContext;
use App\Models\PaymentInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 计费 API（billing-integration-contract.md §6，FR-009 / FR-010）。
 *
 * 挂在 auth:sanctum + tenant 分组内；全部查询经服务层显式 tenant_id 双保险，
 * 跨租户一律 404（不泄露存在性）。
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly SubscriptionService $subscriptions,
        private readonly TwoPhaseMeter $meter,
        private readonly PaymentInvoiceService $invoices,
    ) {}

    /**
     * GET /v1/billing/summary —— 当前套餐/周期起止/各计量用量与上限（与计费页同数据源）。
     */
    public function summary(): JsonResponse
    {
        $tenantId = (int) TenantContext::getTenantId();
        $tenant = $this->plans->tenant($tenantId);
        $config = $this->plans->planConfig($tenant);
        $latest = $this->subscriptions->latestFor($tenantId);

        $usage = [];
        foreach (TwoPhaseMeter::DAILY_LIMIT_KEYS as $eventType => $limitKey) {
            $usage[$eventType] = [
                'used' => $this->meter->usedToday($tenantId, $eventType),
                'limit' => $this->plans->limit($tenant, $limitKey),
            ];
        }

        return response()->json([
            'plan_code' => (string) $tenant->plan_code,
            'plan_name' => (string) ($config['name'] ?? ''),
            'price_monthly' => $config['price_monthly'] ?? 0,
            'subscription' => $latest === null ? null : [
                'status' => (string) $latest->status,
                'started_at' => $latest->started_at?->toIso8601String(),
                'ends_at' => $latest->ends_at?->toIso8601String(),
            ],
            'usage' => $usage,
        ]);
    }

    /**
     * GET /v1/billing/invoices —— 本租户支付单，时间倒序。
     */
    public function invoices(): JsonResponse
    {
        $tenantId = (int) TenantContext::getTenantId();

        return response()->json([
            'data' => $this->invoices->listForTenant($tenantId)->map(
                fn (PaymentInvoice $invoice): array => $this->serialize($invoice)
            )->all(),
        ]);
    }

    /**
     * GET /v1/billing/invoices/{ref} —— 单笔详情（跨租户 → 404）。
     */
    public function show(string $ref): JsonResponse
    {
        $tenantId = (int) TenantContext::getTenantId();

        /** @var PaymentInvoice|null $invoice */
        $invoice = PaymentInvoice::query()
            ->withoutGlobalScopes()
            ->where('ref', $ref)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($invoice === null) {
            abort(404);
        }

        return response()->json(['data' => $this->serialize($invoice)]);
    }

    /**
     * POST /v1/billing/invoices —— body {plan_code} → 生成/复用待支付单。
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'max:30'],
        ]);

        $tenantId = (int) TenantContext::getTenantId();
        $invoice = $this->invoices->create($tenantId, (string) $validated['plan_code']);

        return response()->json(['data' => $this->serialize($invoice)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PaymentInvoice $invoice): array
    {
        return [
            'ref' => (string) $invoice->ref,
            'plan_code' => (string) $invoice->plan_code,
            'channel_code' => (string) $invoice->channel_code,
            'currency' => (string) $invoice->currency,
            'chain' => (string) $invoice->chain,
            'amount_usdt' => (string) $invoice->amount_usdt,
            'paid_amount_usdt' => $invoice->paid_amount_usdt !== null ? (string) $invoice->paid_amount_usdt : null,
            'pay_address' => $invoice->pay_address,
            'pay_uri' => $invoice->pay_uri,
            'status' => (string) $invoice->status,
            'expires_at' => $invoice->expires_at?->toIso8601String(),
            'confirmed_at' => $invoice->confirmed_at?->toIso8601String(),
            'created_at' => $invoice->created_at?->toIso8601String(),
        ];
    }
}
