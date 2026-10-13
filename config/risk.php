<?php

/**
 * 风控与熔断配置
 *
 * 定义 4 维度 Rule 默认阈值、熔断状态机、恢复窗口。
 * 租户可在 risk_rules 表覆盖这些默认值。
 */

return [

    // 默认风控规则阈值（可在 risk_rules 表按租户/实例覆盖）
    'defaults' => [
        'max_loss_per_trade' => [
            'threshold_value' => 2,    // 2% of allocated_capital
            'threshold_unit' => 'percent',
            'cooldown_minutes' => 60,
            'auto_recover' => true,
        ],
        'max_daily_loss' => [
            'threshold_value' => 5,    // 5% of allocated_capital
            'threshold_unit' => 'percent',
            'cooldown_minutes' => 1440, // 24 小时
            'auto_recover' => true,
        ],
        'max_drawdown' => [
            'threshold_value' => 10,   // 10%
            'threshold_unit' => 'percent',
            'cooldown_minutes' => 1440,
            'auto_recover' => false,
        ],
        'consecutive_losses' => [
            'threshold_value' => 5,    // 5 次
            'threshold_unit' => 'count',
            'cooldown_minutes' => 120,
            'auto_recover' => true,
        ],
    ],

    // 熔断状态机（Redis）
    'breaker' => [
        'driver' => 'redis',
        'key_prefix' => 'tenant:{tenant_id}:breaker:{instance_id}',
        // HALF_OPEN 探测单放行数量
        'half_open_probe_count' => 1,
        // HALF_OPEN 自动进入 CLOSED 的探测成功次数
        'half_open_success_threshold' => 1,
        // HALF_OPEN 探测失败后重新 OPEN 的等待时间（秒）
        'half_open_failure_backoff' => 300,
    ],

    // 触发后动作编排
    'on_trip' => [
        // 暂停策略（status → paused_by_breaker）
        'pause_strategy' => true,
        // 不平掉已有持仓，交给策略自身的止盈止损逻辑
        'close_existing_positions' => false,
        // 分发通知（带冷却去重）
        'notify_channels' => ['telegram', 'wechat_work', 'mail', 'websocket'],
    ],

    // 关键约束：仅业务亏损触发熔断，API 异常不触发
    'count_api_errors' => false,
];
