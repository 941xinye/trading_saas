<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * API v1 基础控制器。
 *
 * 所有 Api\V1\* 控制器继承此类。
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * 校验路由绑定模型属于当前租户，跨租户一律 404（不泄露存在性）。
     *
     * 说明：api 组的 SubstituteBindings 早于 tenant 中间件执行，路由绑定阶段
     * TenantContext 尚未初始化，因此不能只依赖 TenantScope；此处做二道防线。
     */
    protected function ensureTenantOwned(Model $model): void
    {
        $tenantId = TenantContext::requireTenantId();

        if ((int) $model->getAttribute('tenant_id') !== $tenantId) {
            abort(404);
        }
    }
}
