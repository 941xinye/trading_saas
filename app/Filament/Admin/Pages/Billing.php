<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Billing\Plans\PlanService;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Payment\Exceptions\PaymentChannelException;
use App\Domain\Payment\PaymentInvoiceService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\PaymentInvoiceResource;
use App\Filament\Admin\Widgets\QuotaUsageWidget;
use BackedEnum;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * 租户计费页（spec 007 / FR-009 / FR-010 / SC-005，契约 §5）。
 *
 * 首屏：当前套餐/周期起止 + QuotaUsageWidget + 待支付单收款面板 + 历史支付单入口；
 * 头部「升级套餐」Action：选套餐 → PaymentInvoiceService::create() → 收款信息即出
 * （点击升级 → 选套餐 → 见收款信息 ≤ 3 步，SC-005）。
 *
 * 页面内全部查询显式走 TenantContext::getTenantId()（双保险，FR-010）。
 * 不提供任何退款入口（FR-014：退款人工线下处理）。
 */
class Billing extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-credit-card';

    protected static UnitEnum|string|null $navigationGroup = '计费';

    protected static ?string $navigationLabel = '套餐与账单';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'billing';

    protected string $view = 'filament.admin.pages.billing';

    /** 续费提醒链接携带的预选套餐（/admin/billing?plan_code=pro，契约 §3 载荷） */
    public ?string $prefillPlanCode = null;

    public function mount(): void
    {
        $planCode = request()->query('plan_code');
        $this->prefillPlanCode = is_string($planCode) && PlanService::planExists($planCode)
            ? $planCode
            : null;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            QuotaUsageWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upgrade')
                ->label('升级套餐')
                ->icon('heroicon-o-arrow-up-circle')
                ->modalHeading('选择目标套餐')
                ->modalSubmitActionLabel('生成支付单')
                ->schema([
                    Select::make('plan_code')
                        ->label('套餐')
                        ->options($this->upgradePlanOptions())
                        ->default($this->prefillPlanCode)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->createUpgradeInvoice((string) $data['plan_code']);
                }),
        ];
    }

    /**
     * 当前套餐 / 订阅周期摘要（与 GET /v1/billing/summary 同数据源）。
     *
     * @return array<string, mixed>
     */
    public function getSummaryProperty(): array
    {
        $tenantId = (int) TenantContext::getTenantId();
        $plans = app(PlanService::class);

        $tenant = $plans->tenant($tenantId);
        $config = $plans->planConfig($tenant);
        $latest = app(SubscriptionService::class)->latestFor($tenantId);

        return [
            'plan_code' => (string) $tenant->plan_code,
            'plan_name' => (string) ($config['name'] ?? $tenant->plan_code),
            'price_monthly' => (string) ($config['price_monthly'] ?? '0'),
            'subscription_status' => $latest?->status !== null ? (string) $latest->status : null,
            'started_at' => $latest?->started_at,
            'ends_at' => $latest?->ends_at,
        ];
    }

    /**
     * 当前待支付单（若有）：金额 / 收款地址 / payUri 二维码 / 倒计时。
     *
     * @return array<string, mixed>|null
     */
    public function getPendingInvoiceProperty(): ?array
    {
        $tenantId = (int) TenantContext::getTenantId();
        $invoice = app(PaymentInvoiceService::class)->pendingFor($tenantId);

        if ($invoice === null) {
            return null;
        }

        $now = Carbon::now('UTC');
        $expired = $invoice->expires_at === null || $invoice->expires_at->isPast();

        return [
            'ref' => (string) $invoice->ref,
            'plan_code' => (string) $invoice->plan_code,
            'amount_usdt' => (string) $invoice->amount_usdt,
            'currency' => (string) $invoice->currency,
            'chain' => (string) $invoice->chain,
            'pay_address' => $invoice->pay_address,
            'pay_uri' => $invoice->pay_uri,
            'qr_data_uri' => $this->qrDataUri($invoice->pay_uri),
            'status' => (string) $invoice->status,
            'expired' => $expired,
            'remaining_seconds' => $expired ? 0 : (int) $now->diffInSeconds($invoice->expires_at),
            'expires_at' => $invoice->expires_at,
        ];
    }

    public function getInvoicesUrlProperty(): string
    {
        return PaymentInvoiceResource::getUrl('index');
    }

    /**
     * @return array<string, string>
     */
    private function upgradePlanOptions(): array
    {
        $options = [];
        foreach ((array) config('plans.plans') as $code => $plan) {
            if ((float) ($plan['price_monthly'] ?? 0) <= 0) {
                continue; // 免费版不可购买
            }

            $options[(string) $code] = sprintf(
                '%s（%s USDT/月）',
                (string) ($plan['name'] ?? $code),
                (string) ($plan['price_monthly'] ?? '0'),
            );
        }

        return $options;
    }

    private function createUpgradeInvoice(string $planCode): void
    {
        $tenantId = (int) TenantContext::getTenantId();
        $invoices = app(PaymentInvoiceService::class);

        // US4-3：已有待支付单 → 引导至既有单（不堆叠）
        if ($invoices->pendingFor($tenantId, $planCode) !== null) {
            Notification::make()
                ->info()
                ->title('您已有该套餐的待支付单，请使用原收款信息完成支付')
                ->send();

            return;
        }

        try {
            $invoices->create($tenantId, $planCode);

            Notification::make()
                ->success()
                ->title('支付单已生成，请在有效期内完成支付')
                ->send();
        } catch (PaymentChannelException $e) {
            // 通道不可用：服务层已删除半状态单，页面不出现收款面板
            Notification::make()
                ->danger()
                ->title('支付通道暂不可用，请稍后重试')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * payUri → SVG 二维码 data URI（chillerlan/php-qrcode 为 filament 既有生产依赖，零新增）。
     */
    private function qrDataUri(?string $payUri): ?string
    {
        if ($payUri === null || $payUri === '') {
            return null;
        }

        try {
            return (new QRCode(new QROptions([
                'outputType' => QRCode::OUTPUT_MARKUP_SVG,
                'outputBase64' => true,
            ])))->render($payUri);
        } catch (\Throwable) {
            return null; // 二维码生成失败不阻断收款信息展示（地址/payUri 文本仍在）
        }
    }
}
