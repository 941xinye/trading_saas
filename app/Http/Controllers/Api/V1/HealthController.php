<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Health\HealthCheckService;
use Illuminate\Http\JsonResponse;

/**
 * 生产探活端点（公开，供负载均衡/监控系统调用）。
 *
 * overall=down → 503；ok/degraded → 200。
 * 仅返回组件状态与延迟，不返回异常原文或连接信息。
 */
final class HealthController extends Controller
{
    public function __invoke(HealthCheckService $health): JsonResponse
    {
        $result = $health->run();

        return response()->json($result, $result['status'] === 'down' ? 503 : 200);
    }
}
