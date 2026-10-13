<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\Context;
use RuntimeException;

/**
 * 租户上下文单例。
 *
 * 在请求/Job/Queue/协程生命周期内持有当前 tenant_id，
 * 供 TenantScope 自动注入 WHERE tenant_id=?。
 *
 * 沿用 CRM 项目的命名约定。Octane 长驻 worker 下，服务层禁止 static 缓存，
 * 本类仅做请求级 ThreadLocal 隔离（基于 Laravel 11+ 的 Context API），
 * Octane 在 requestReceived / JobReceived 事件中自动重置 Context，不缓存配置。
 */
final class TenantContext
{
    private const CTXT_KEY_TENANT_ID = 'tenant_context.tenant_id';
    private const CTXT_KEY_TENANT = 'tenant_context.tenant';

    private function __construct()
    {
    }

    /**
     * 初始化租户上下文。中间件 / Job / 协程启动时调用。
     */
    public static function initialize(int $tenantId): void
    {
        Context::add(self::CTXT_KEY_TENANT_ID, $tenantId);
        // 不预加载 Tenant 模型，按需查 DB/缓存，避免 Octane 长驻下脏数据
        Context::forget(self::CTXT_KEY_TENANT);
    }

    /**
     * 注入已加载的 Tenant 模型，避免重复查库。
     */
    public static function setTenant(Tenant $tenant): void
    {
        Context::add(self::CTXT_KEY_TENANT, $tenant);
        Context::add(self::CTXT_KEY_TENANT_ID, $tenant->getKey());
    }

    /**
     * 获取当前 tenant_id。
     *
     * @return int|null 平台超管上下文为 null
     */
    public static function getTenantId(): ?int
    {
        /** @var int|null $value */
        $value = Context::get(self::CTXT_KEY_TENANT_ID);

        return $value;
    }

    /**
     * 获取当前 Tenant 模型（按需查库）。
     */
    public static function getTenant(): ?Tenant
    {
        /** @var Tenant|null $cached */
        $cached = Context::get(self::CTXT_KEY_TENANT);
        if ($cached instanceof Tenant) {
            return $cached;
        }
        $tenantId = self::getTenantId();
        if ($tenantId === null) {
            return null;
        }
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant instanceof Tenant) {
            Context::add(self::CTXT_KEY_TENANT, $tenant);
        }

        return $tenant;
    }

    /**
     * 是否处于平台超管上下文（无租户隔离）。
     */
    public static function isPlatformAdmin(): bool
    {
        return self::getTenantId() === null;
    }

    /**
     * 必须有租户上下文，否则抛异常（用于租户级 API 入口校验）。
     */
    public static function requireTenantId(): int
    {
        $id = self::getTenantId();
        if ($id === null) {
            throw new RuntimeException('Tenant context not initialized. Did the BootstrapTenancy middleware run?');
        }

        return $id;
    }

    /**
     * 清理上下文（请求结束/Job 结束/协程退出时）。
     */
    public static function clear(): void
    {
        Context::forget(self::CTXT_KEY_TENANT_ID);
        Context::forget(self::CTXT_KEY_TENANT);
    }

    /**
     * 在无租户上下文的回调中执行平台级操作。
     *
     * 注意：仅用于平台超管后台/系统级聚合，禁止跨租户查询业务数据。
     */
    public static function runWithoutTenant(callable $callback): mixed
    {
        $previousId = self::getTenantId();
        $previousTenant = Context::get(self::CTXT_KEY_TENANT);
        Context::add(self::CTXT_KEY_TENANT_ID, null);
        Context::forget(self::CTXT_KEY_TENANT);
        try {
            return $callback();
        } finally {
            if ($previousId !== null) {
                Context::add(self::CTXT_KEY_TENANT_ID, $previousId);
            }
            if ($previousTenant !== null) {
                Context::add(self::CTXT_KEY_TENANT, $previousTenant);
            }
        }
    }
}
