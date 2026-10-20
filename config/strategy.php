<?php

/**
 * 策略引擎配置
 *
 * 定义内置策略的默认参数、状态持久化、调度器行为等。
 * 当前已实现：spot_grid / futures_grid / spot_dca / custom_dsl（4 种）。
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
    'implemented' => ['spot_grid', 'spot_dca', 'futures_grid', 'custom_dsl'],

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

    // 自定义 DSL 策略（P7）解释器硬闸门与配额上限（见 specs/006-custom-dsl-strategy/data-model.md §6.1）。
    // 取值 MUST 与 strategies.custom_dsl.dsl_schema 一致（由 DslConfigConsistencyTest 结构性断言保证）。
    'dsl' => [
        'max_script_bytes' => 8192,
        'max_lines' => 200,
        'max_depth' => 8,
        'max_rules_per_section' => 20,
        'max_indicators' => 8,
        'eval_step_budget' => 500,
        'indicator_window_max' => 150,
        'allowed_triggers' => ['on_bar', 'on_tick', 'on_fill'],
        'allowed_actions' => ['open_long', 'open_short', 'close_long', 'close_short', 'noop'],
        'allowed_indicators' => ['ma', 'rsi', 'macd', 'bollinger', 'atr'],
    ],

    // 订单幂等性 clientOrderId 模板
    'client_order_id_template' => '{tenant_id}_{instance_id}_{timestamp}_{random}',
];
