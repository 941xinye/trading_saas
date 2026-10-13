<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExchangeAccountController;
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
    });
