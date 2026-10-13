<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * 租户全局查询 Scope。
 *
 * 自动为模型查询加 WHERE tenant_id = ?，防止跨租户数据泄露。
 * 仅在租户上下文存在时生效；平台超管上下文（tenant_id=null）不过滤。
 */
class TenantScope implements Scope
{
    /**
     * 应用 Scope。
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! method_exists($model, 'getTenantScoped') || ! $model->getTenantScoped()) {
            return;
        }

        $tenantId = TenantContext::getTenantId();
        if ($tenantId !== null) {
            // 用模型列前缀（避免 JOIN 时列名歧义）
            $column = $model->getTable() . '.tenant_id';
            $builder->where($column, $tenantId);
        }
        // tenant_id === null 表示平台超管上下文，不做过滤（用于 Filament Platform 面板）
    }

    /**
     * 扩展 Builder：允许在不污染主上下文的情况下，单次查询绕过租户隔离。
     *
     * 仅限平台超管后台使用。业务接口禁止调用。
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenant', function (Builder $builder) {
            /** @var Builder $builder */
            return $builder->withoutGlobalScope($this);
        });
    }
}
