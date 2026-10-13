<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\TenantKeyResolver;
use App\Models\User;
use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use Illuminate\Support\Facades\Gate;
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
    }
}
