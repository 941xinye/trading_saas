<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * 自动挂载 tenant_id 的 Trait。
 *
 * - 创建时自动注入当前租户 ID（避免 Controller 手动写）
 * - 配合 TenantScope 全局过滤查询
 *
 * 沿用 CRM 项目的 BelongsToTenant 约定。
 */
trait BelongsToTenant
{
    /**
     * 是否启用租户隔离（可被子模型覆盖关闭）。
     */
    protected bool $tenantScoped = true;

    public function getTenantScoped(): bool
    {
        return $this->tenantScoped;
    }

    /**
     * Eloquent boot 钩子：注册 TenantScope + 创建时自动填充 tenant_id。
     */
    protected static function bootBelongsToTenant(): void
    {
        // 全局 Scope：自动加 WHERE tenant_id = ?
        static::addGlobalScope(new TenantScope());

        // 创建时自动注入 tenant_id
        static::creating(function (Model $model): void {
            if (! $model instanceof self) {
                return;
            }
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return; // 平台级创建（seed）允许无 tenant_id
            }
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }

    /**
     * 获取所属租户关联。
     */
    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
