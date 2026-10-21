<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Archive\Contracts\ArchiveStore;
use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Services\HistoricalQueryService;
use App\Domain\Archive\Stores\ClickHouseStore;
use App\Domain\Observability\MetricsStore;
use App\Domain\Payment\Contracts\PaymentChannelRegistry;
use App\Domain\Payment\PaymentChannelManager;
use App\Domain\Risk\Breaker\IsolatedLiquidationPriceCalculator;
use App\Domain\Risk\Contracts\LiquidationPriceCalculator;
use App\Domain\Risk\Contracts\PositionRiskMonitor as PositionRiskMonitorContract;
use App\Domain\Risk\Position\PositionRiskMonitor;
use App\Domain\Strategy\Custom\Dsl\DslPreviewService;
use App\Domain\Strategy\Custom\Dsl\DslScriptValidator;
use App\Domain\Strategy\Custom\Dsl\DslValidator;
use App\Domain\Strategy\Custom\Dsl\Preview\HistoricalPreviewService;
use App\Domain\Tenancy\TenantKeyResolver;
use App\Models\User;
use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 租户密钥解析（KEK/DEK）：进程级单例，实例属性缓存，Octane 多 worker 隔离
        $this->app->singleton(KeyResolver::class, TenantKeyResolver::class);
        $this->app->singleton(AesGcmCrypt::class, function ($app) {
            return new AesGcmCrypt($app->make(KeyResolver::class));
        });

        // P8 历史归档存储（bind 非 singleton：Octane 长驻禁 static 缓存，spec 004 / T007）
        $this->app->bind(ArchiveStore::class, fn () => ClickHouseStore::fromConfig());

        // P8 统一历史查询（bind 非 singleton，同上；US2 / T020）
        $this->app->bind(HistoricalQuery::class, fn ($app) => new HistoricalQueryService($app->make(ArchiveStore::class)));

        // spec 005 仓位级风控（bind 非 singleton：Octane 长驻禁 static 缓存）
        $this->app->bind(LiquidationPriceCalculator::class, IsolatedLiquidationPriceCalculator::class);
        $this->app->bind(PositionRiskMonitorContract::class, PositionRiskMonitor::class);

        // spec 006 DSL 编辑器校验（bind 非 singleton：Octane 长驻禁 static 缓存）
        $this->app->bind(DslValidator::class, DslScriptValidator::class);

        // spec 006 DSL 回测预览（bind 非 singleton，同上）
        $this->app->bind(DslPreviewService::class, HistoricalPreviewService::class);

        // spec 007 支付通道注册表（bind 非 singleton：Octane 长驻禁 static 缓存）
        $this->app->bind(PaymentChannelRegistry::class, fn ($app) => new PaymentChannelManager($app));

        // 可观测性指标存储（singleton：Redis 连接复用，fail-open 降级）
        $this->app->singleton(MetricsStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 平台超管 Gate：仅 role=platform_admin 通过。
        // 用 Filament Platform 面板的 authMiddleware 链 can:platform-admin 校验。
        Gate::define('platform-admin', function (User $user): bool {
            return $user->getAttribute('role') === 'platform_admin';
        });

        // API 限流基线：按租户聚合（平台用户按 user id），避免单租户打满影响其他租户。
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();
            $key = $user?->tenant_id !== null
                ? 'tenant:'.$user->tenant_id
                : 'user:'.($user?->id ?? $request->ip());

            return Limit::perMinute((int) config('app.api_rate_limit_per_minute', 120))->by($key);
        });
    }
}
