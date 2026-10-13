<?php

/**
 * 多租户配置
 *
 * 行级 tenant_id 隔离模式。沿用 CRM 的 BelongsToTenant Trait + TenantScope 约定。
 */

return [

    // 是否启用租户上下文（true 时所有业务表自动加 WHERE tenant_id=?）
    'enabled' => true,

    // 中间件别名（在 routes/api.php 中挂载）
    'middleware' => 'tenant',

    // 平台超管角色（不挂租户上下文，可访问 Platform 面板）
    'platform_admin_role' => 'platform_admin',

    // 租户角色枚举
    'roles' => ['platform_admin', 'tenant_admin', 'trader', 'viewer'],

    // 租户状态机
    'statuses' => ['active', 'suspended', 'canceled'],

    // 试用期限（天）
    'trial_days' => 14,

    // Redis Key 前缀模板（运行时按 tenant_id 替换）
    'redis_key_prefix' => 'tenant:{tenant_id}',

    // 禁止跨租户聚合：withoutTenant() 全局禁用
    'disable_cross_tenant_aggregation' => true,
];
