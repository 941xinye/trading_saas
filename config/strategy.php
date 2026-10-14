<?php

/**
 * 策略引擎配置
 *
 * 定义 7 种内置策略的默认参数、状态持久化、调度器行为等。
 */

return [

    // 策略状态持久化（Redis）
    'state' => [
        'driver' => 'redis',
        'key_prefix' => 'tenant:{tenant_id}:strategy:{instance_id}:state',
        // 持久化快照间隔（秒）
        'snapshot_interval' => 30,
        // 数据库快照间隔（秒，写入 strategy_instances.position_snapshot）
        'db_snapshot_interval' => 60,
    ],

    // 调度器（进程 B 内的协程容器）
    'runner' => [
        // 单实例最大协程数
        'max_coroutines' => 10000,
        // 协程内 tick 节流（毫秒，避免高频 onTick 风暴）
        'tick_throttle_ms' => 500,
        // 进程启动时自动恢复 status in (running, paused_by_breaker) 的实例
        'auto_restore_on_boot' => true,
    ],

    // 已真实落地、可对客售卖的策略代码（P5.5 步骤 2 起作为套餐白名单的硬闸门）。
    // plans.php 的 strategies 必须与此取交集；未落地的策略代码不得运行/售卖。
    'implemented' => ['spot_grid', 'spot_dca'],

    // 策略默认参数 schema
    'strategies' => [
        'spot_grid' => [
            'name' => '现货网格',
            'category' => 'spot',
            'risk_profile' => 'conservative',
            'default_params' => [
                'upper_price' => null,
                'lower_price' => null,
                'grid_count' => 10,
                'investment_amount' => 1000,
            ],
        ],
        'futures_grid' => [
            'name' => '合约网格',
            'category' => 'futures',
            'risk_profile' => 'balanced',
            'default_params' => [
                'upper_price' => null,
                'lower_price' => null,
                'grid_count' => 10,
                'leverage' => 5,
                'margin' => 1000,
            ],
        ],
        'spot_dca' => [
            'name' => '现货 DCA',
            'category' => 'spot',
            'risk_profile' => 'conservative',
            'default_params' => [
                'amount_per_order' => 100,
                // 默认周投（7 天）；POC/实例参数可覆盖为 1-2 分钟
                'interval_minutes' => 10080,
                'max_orders' => 20,
            ],
        ],
        'futures_dca' => [
            'name' => '合约 DCA',
            'category' => 'futures',
            'risk_profile' => 'balanced',
            'default_params' => [
                'amount_per_order' => 100,
                'interval_minutes' => 60,
                'max_orders' => 20,
                'leverage' => 3,
            ],
        ],
        'smart_martingale' => [
            'name' => '马丁变体',
            'category' => 'futures',
            'risk_profile' => 'aggressive',
            'default_params' => [
                'base_amount' => 100,
                'multiplier' => 1.5,
                'max_layers' => 5,
                'leverage' => 5,
                // 反马丁特征：盈利后减仓，亏损后试探性加仓但限层
                'profit_reduce_ratio' => 0.5,
            ],
        ],
        'trend_rolling' => [
            'name' => '滚仓宝',
            'category' => 'futures',
            'risk_profile' => 'aggressive',
            'default_params' => [
                'initial_margin' => 500,
                'leverage' => 5,
                // 趋势判断周期
                'trend_interval' => '4h',
                'trend_indicator' => 'ema_cross',
                // 浮盈加仓阈值
                'add_position_threshold' => 0.02,
                'max_rolls' => 5,
            ],
        ],
        'custom_dsl' => [
            'name' => '自定义策略',
            'category' => 'spot',
            'risk_profile' => 'custom',
            'default_params' => [
                'dsl_script' => null,
            ],
            'dsl_schema' => [
                'triggers' => ['on_bar', 'on_tick', 'on_fill'],
                'actions' => ['open_long', 'open_short', 'close_long', 'close_short', 'noop'],
                'indicators' => ['ma', 'rsi', 'macd', 'bollinger', 'atr'],
            ],
        ],
    ],

    // 订单幂等性 clientOrderId 模板
    'client_order_id_template' => '{tenant_id}_{instance_id}_{timestamp}_{random}',
];
