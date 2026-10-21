<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\BillingWebhookController;
use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExchangeAccountController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LiquidationController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\OptimizationController;
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
// 公开探活：无需认证，供负载均衡/监控系统调用
Route::get('v1/health', HealthController::class)->name('api.health');

// 公开指标：无需认证，供 Prometheus 等监控系统抓取
Route::get('v1/metrics', MetricsController::class)->name('api.metrics');

Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])
    ->prefix('v1')
    ->name('api.')
    ->group(function (): void {
        // 个人访问令牌：只能管理当前用户自己的 token
        Route::get('tokens', [ApiTokenController::class, 'index'])->name('tokens.index');
        Route::post('tokens', [ApiTokenController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('tokens.store');
        Route::delete('tokens/current', [ApiTokenController::class, 'destroyCurrent'])
            ->name('tokens.current.destroy');
        Route::delete('tokens/{token}', [ApiTokenController::class, 'destroy'])
            ->whereNumber('token')
            ->name('tokens.destroy');

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
        Route::get('strategy-instances/{instance}/optimization-applications', [StrategyInstanceController::class, 'optimizationApplications'])
            ->name('strategy-instances.optimization-applications');

        // 信号订阅 + 信号（信号只读）
        Route::apiResource('signal-subscriptions', SignalSubscriptionController::class)
            ->parameters(['signal-subscriptions' => 'subscription']);
        Route::apiResource('signals', SignalController::class)->only(['index', 'show']);
        Route::post('signals/{signal}/execute', [SignalController::class, 'execute'])
            ->name('signals.execute');

        // 风控规则
        Route::apiResource('risk-rules', RiskRuleController::class)
            ->parameters(['risk-rules' => 'rule']);

        // P11：参数寻优（创建即入 backtest 队列，202；列表/详情/trials 只读）
        Route::apiResource('parameter-optimizations', OptimizationController::class)
            ->parameters(['parameter-optimizations' => 'optimization'])
            ->only(['index', 'store', 'show']);
        Route::get('parameter-optimizations/{optimization}/trials', [OptimizationController::class, 'trials'])
            ->name('parameter-optimizations.trials');
        Route::post('parameter-optimizations/{optimization}/apply', [OptimizationController::class, 'apply'])
            ->name('parameter-optimizations.apply');
        Route::post('parameter-optimization-applications/{application}/rollback', [OptimizationController::class, 'rollback'])
            ->name('parameter-optimization-applications.rollback');

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
    ->middleware('throttle:120,1')
    ->name('api.billing.webhook');
