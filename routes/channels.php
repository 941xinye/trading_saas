<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

/**
 * Reverb 广播频道授权。
 *
 * 命名约定：private-tenant.{tenantId}.{event}
 *  - signals：信号通知
 *  - dashboard：面板实时推送
 *  - alerts：熔断/健康/失败订单告警
 *
 * 授权校验：当前认证用户的 tenant_id 必须与频道参数 tenantId 一致。
 * 跨租户订阅被 Broadcast 体系自然拒绝，无需额外中间件。
 */
Broadcast::channel('tenant.{tenantId}', function ($user, int $tenantId): array|false {
    return $user->tenant_id === $tenantId
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});

Broadcast::channel('tenant.{tenantId}.signals', function ($user, int $tenantId): array|false {
    return $user->tenant_id === $tenantId
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});

Broadcast::channel('tenant.{tenantId}.dashboard', function ($user, int $tenantId): array|false {
    return $user->tenant_id === $tenantId
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});

Broadcast::channel('tenant.{tenantId}.alerts', function ($user, int $tenantId): array|false {
    return $user->tenant_id === $tenantId
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});
