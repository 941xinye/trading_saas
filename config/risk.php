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
        // 分发通知（带冷却去重）；P4 实现 log/telegram，wechat_work/mail 标 failed 留 P5
        'notify_channels' => ['log', 'telegram', 'wechat_work', 'mail', 'websocket'],
        // 通知冷却去重分钟数（同 instanceId+ruleType 重复 trip 只通知一次）
        'dedup_ttl_minutes' => 15,
    ],

    // 关键约束：仅业务亏损触发熔断，API 异常不触发
    'count_api_errors' => false,

    // 仓位级风控（spec 005，合约场景）
    // 保证金率 = 维持保证金 / 仓位名义价值；越小越危险
    'position' => [
        // 预警阈值：保证金率 ≤ 50% → 发通知（15min 去重）
        'warning_margin_ratio' => env('RISK_POSITION_WARNING', 0.50),
        // 行动阈值：保证金率 ≤ 20% → 自动减仓（reduce_ratio 比例）
        'action_margin_ratio' => env('RISK_POSITION_ACTION', 0.20),
        // 紧急阈值：保证金率 ≤ 10% → 市价全平
        'emergency_margin_ratio' => env('RISK_POSITION_EMERGENCY', 0.10),
        // 减仓比例（行动阈值触发时减仓 50%）
        'reduce_ratio' => env('RISK_POSITION_REDUCE_RATIO', 0.50),
        // 单飞锁 TTL（秒）：防止风控动作与网格平仓并发重复下单
        'lock_ttl' => env('RISK_POSITION_LOCK_TTL', 30),
        // 通知去重冷却（秒）：同 instanceId+actionType 15min 内只通知一次
        'dedup_cooldown' => env('RISK_POSITION_DEDUP', 900),
        // 默认维持保证金率（按交易对，从 leverageBracket 动态获取后覆盖）
        'default_mmr' => [
            'BTCUSDT' => '0.0040',
            'ETHUSDT' => '0.0050',
        ],
    ],
];
