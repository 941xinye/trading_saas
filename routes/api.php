<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BillingWebhookController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExchangeAccountController;
use App\Http\Controllers\Api\V1\LiquidationController;
use App\Http\Controllers\Api\V1\RiskRuleController;
use App\Http\Controllers\Api\V1\SignalController;
use App\Http\Controllers\Api\V1\SignalSubscriptionController;
use App\Http\Controllers\Api\V1\StrategyInstanceController;
use Illuminate\Support\Facades\Route;

/**
 * API v1 路由。
 *
 * 链路：auth:sanctum（认证）→ tenant（BootstrapTenancy 初始化 TenantContext）→ Controller
 *
 * 路由名前缀：api.*（沿用 CRM 约定，避免与 Filament 路由冲突）。
 */
Route::middleware(['auth:sanctum', 'tenant'])
    ->prefix('v1')
    ->name('api.')
    ->group(function (): void {
        // Dashboard 聚合接口（方案 §7：5 类面板数据单接口聚合）
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // 交易所账户
        Route::apiResource('exchange-accounts', ExchangeAccountController::class)
            ->parameters(['exchange-accounts' => 'account']);

        // 策略实例 + start/stop 自定义动作
        Route::apiResource('strategy-instances', StrategyInstanceController::class)
            ->parameters(['strategy-instances' => 'instance']);
        Route::post('strategy-instances/{instance}/start', [StrategyInstanceController::class, 'start'])
            ->name('strategy-instances.start');
        Route::post('strategy-instances/{instance}/stop', [StrategyInstanceController::class, 'stop'])
            ->name('strategy-instances.stop');

        // 信号订阅 + 信号（信号只读）
        Route::apiResource('signal-subscriptions', SignalSubscriptionController::class)
            ->parameters(['signal-subscriptions' => 'subscription']);
        Route::apiResource('signals', SignalController::class)->only(['index', 'show']);

        // 风控规则
        Route::apiResource('risk-rules', RiskRuleController::class)
            ->parameters(['risk-rules' => 'rule']);

        // P5：大户爆仓密集区（市场级数据，无 tenant_id 隔离）
        Route::get('liquidation/map', [LiquidationController::class, 'map'])->name('liquidation.map');
        Route::get('liquidation/recent', [LiquidationController::class, 'recent'])->name('liquidation.recent');

        // P9：计费（summary / 支付单；契约 billing-integration-contract.md §6）
        Route::get('billing/summary', [BillingController::class, 'summary'])->name('billing.summary');
        Route::get('billing/invoices', [BillingController::class, 'invoices'])->name('billing.invoices');
        Route::post('billing/invoices', [BillingController::class, 'store'])->name('billing.invoices.store');
        Route::get('billing/invoices/{ref}', [BillingController::class, 'show'])->name('billing.invoices.show');
    });

// ---------------------------------------------------------------------
// P9：支付通道 webhook（验签即鉴权；MUST 注册在 auth:sanctum + tenant 分组之外）
// api 组天然豁免 CSRF（ValidateCsrfToken 仅作用于 web 组），无需额外放行（T030 核对结论）。
// ---------------------------------------------------------------------
Route::post('v1/billing/webhook/{channel}', BillingWebhookController::class)
    ->name('api.billing.webhook');
