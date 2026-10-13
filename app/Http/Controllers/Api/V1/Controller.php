<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * API v1 基础控制器。
 *
 * 所有 Api\V1\* 控制器继承此类。骨架阶段仅挂载 Laravel 默认 trait，
 * 后续 POC 可加租户上下文恢复 / 服务层注入等。
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
