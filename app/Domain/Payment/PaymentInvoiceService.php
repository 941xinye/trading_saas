<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Billing\Plans\PlanService;
use App\Domain\Payment\Contracts\PaymentChannelRegistry;
use App\Domain\Payment\Dto\PaymentInvoiceRequest;
use App\Domain\Payment\Dto\PaymentStatus;
use App\Domain\Payment\Exceptions\PaymentChannelException;
use App\Models\PaymentInvoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 支付单服务（billing-integration-contract.md §1，FR-002 / FR-011 / FR-012 / FR-013）。
 *
 * 红线：
 *  - 同租户 + 同目标套餐至多 1 笔非终态单（存在即原样返回，不堆叠，US4-3）；
 *  - 建单序 = 落 pending 行 → 调通道 → 回填通道字段；通道异常 → 删除刚落的行（无半状态单）；
 *  - 通道返回金额 ≠ 应付 → bccomp 拒建单；金额一律 8 位小数字符串，浮点禁入；
 *  - 全部查询 withoutGlobalScopes + 显式 tenant_id（webhook 无租户上下文）。
 */
final class PaymentInvoiceService
{
    /**
     * 非终态状态集（pendingFor / expireStale 共用口径）。
     *
     * @var string[]
     */
    public const OPEN_STATUSES = ['pending', 'processing', 'partial'];

    public function __construct(
        private readonly PaymentChannelRegistry $channels,
        private readonly PlanService $plans,
    ) {}

    /**
     * 发起升级/续费：生成或复用待支付单。
     *
     * @throws RuntimeException 未知 plan_code（不降级，constitution V）
     * @throws PaymentChannelException 通道不可用 / 通道金额与应付不一致
     */
    public function create(int $tenantId, string $planCode, ?string $channelCode = null): PaymentInvoice
    {
        if (! PlanService::planExists($planCode)) {
            throw new RuntimeException("Unknown plan_code: {$planCode}");
        }

        // 复用既有非终态单（不堆叠）
        $existing = $this->pendingFor($tenantId, $planCode);
        if ($existing !== null) {
            return $existing;
        }

        // 金额来源：目标套餐 price_monthly → 8 位小数字符串（FR-011）
        $priceMonthly = config("plans.plans.{$planCode}.price_monthly");
        $amount = number_format((float) $priceMonthly, 8, '.', '');

        $channel = $channelCode !== null
            ? $this->channels->for($channelCode)
            : $this->channels->default();

        $ttlMinutes = (int) config('payment.invoice_ttl_minutes', 30);
        $now = Carbon::now('UTC');

        // ① 落 pending 行（平台权威 expires_at）
        $invoice = new PaymentInvoice;
        $invoice->forceFill([
            'tenant_id' => $tenantId,
            'ref' => 'pi_'.strtolower((string) Str::ulid()),
            'channel_code' => $channel->code(),
            'plan_code' => $planCode,
            'currency' => 'USDT',
            'chain' => 'TRC20',
            'amount_usdt' => $amount,
            'status' => PaymentStatus::Pending->value,
            'expires_at' => $now->copy()->addMinutes($ttlMinutes),
        ]);
        $invoice->save();

        // ② 调通道建单；异常/金额不符 → 删除刚落的行，不留半状态单（spec Edge / FR-002）
        try {
            $data = $channel->createInvoice(new PaymentInvoiceRequest(
                tenantId: $tenantId,
                ref: $invoice->ref,
                planCode: $planCode,
                symbol: 'USDT',
                chain: 'TRC20',
                amount: $amount,
                description: sprintf('Subscription %s (tenant %d)', $planCode, $tenantId),
                ttlMinutes: $ttlMinutes,
            ));

            if (bccomp($data->amount, $amount, 8) !== 0) {
                throw new PaymentChannelException(sprintf(
                    '通道返回金额 %s 与应付 %s 不一致，拒建单（ref=%s）',
                    $data->amount,
                    $amount,
                    $invoice->ref,
                ));
            }
        } catch (\Throwable $e) {
            $invoice->delete();
            throw $e instanceof PaymentChannelException ? $e : new PaymentChannelException($e->getMessage(), 0, $e);
        }

        // ③ 回填通道字段
        $invoice->forceFill([
            'channel_invoice_id' => $data->channelInvoiceId,
            'pay_address' => $data->payAddress,
            'pay_uri' => $data->payUri,
            'meta' => $data->raw,
        ]);
        $invoice->save();

        return $invoice->refresh();
    }

    /**
     * 本租户支付单（时间倒序）。
     *
     * @return Collection<int, PaymentInvoice>
     */
    public function listForTenant(int $tenantId, int $limit = 20): Collection
    {
        return PaymentInvoice::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * 当前非终态支付单（同租户 + 可选目标套餐，最新一笔）。
     */
    public function pendingFor(int $tenantId, ?string $planCode = null): ?PaymentInvoice
    {
        /** @var PaymentInvoice|null $row */
        $row = PaymentInvoice::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->when($planCode !== null, fn ($q) => $q->where('plan_code', $planCode))
            ->orderByDesc('id')
            ->first();

        return $row;
    }

    /**
     * 过期清扫：非终态且 expires_at < now → expired（条件更新，FR-012）。
     *
     * @return int 本次置为 expired 的行数
     */
    public function expireStale(?int $tenantId = null): int
    {
        $now = Carbon::now('UTC');

        return PaymentInvoice::query()
            ->withoutGlobalScopes()
            ->whereIn('status', self::OPEN_STATUSES)
            ->where('expires_at', '<', $now)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->update([
                'status' => PaymentStatus::Expired->value,
                'updated_at' => $now,
            ]);
    }
}
