<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
