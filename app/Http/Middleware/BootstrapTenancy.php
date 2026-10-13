<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 初始化租户上下文中间件。
 *
 * 链路：Sanctum 认证 → BootstrapTenancy → 业务 Controller
 *  - 从认证用户解析 tenant_id
 *  - 注入 TenantContext（供 TenantScope 自动生效）
 *  - 设置 Redis key 前缀 / 日志 context
 *
 * 沿用 CRM 项目的 BootstrapTenancy 命名约定。
 */
class BootstrapTenancy
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenantId = $user?->getRelationValue('tenant')?->getKey()
            ?? $user?->tenant_id;

        if ($tenantId !== null) {
            TenantContext::initialize((int) $tenantId);
            // 日志 context 注入 tenant_id（Laravel 11+ Context API，Octane 自动按请求隔离）
            \Illuminate\Support\Facades\Context::add('log.tenant_id', $tenantId);
        }
        // 平台超管请求（tenant_id=null）不初始化上下文，TenantScope 不生效

        return $next($request);
    }
}
