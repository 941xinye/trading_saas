<?php

/**
 * 订阅计划配置
 *
 * 定义各计划的功能配额、特性开关。沿用 CRM 的 PlanService 思路。
 */

return [

    'default_plan' => 'free',

    // 订阅 past_due 后宽限天数；超过仍未续费 → tenant suspended（P3 状态机）
    'past_due_grace_days' => env('BILLING_PAST_DUE_GRACE_DAYS', 3),

    'plans' => [
        'free' => [
            'name' => '免费版',
            'price_monthly' => 0,
            'features' => [
                'max_exchange_accounts' => 1,
                'max_strategy_instances' => 2,
                'max_signal_subscriptions' => 1,
                'ai_calls_per_day' => 10,
                'orders_per_day' => 100,
                'channels' => ['websocket'],
                // 只售卖已落地策略（硬闸门见 config('strategy.implemented')，P5.5 步骤 2）
                'strategies' => ['spot_grid', 'spot_dca'],
            ],
        ],
        'pro' => [
            'name' => '专业版',
            'price_monthly' => 49,
            'features' => [
                'max_exchange_accounts' => 3,
                'max_strategy_instances' => 10,
                'max_signal_subscriptions' => 5,
                'ai_calls_per_day' => 500,
                'orders_per_day' => 2000,
                'channels' => ['websocket', 'telegram', 'mail'],
                // 合约/马丁策略落地前从售卖清单摘除（P5.5 步骤 2，恢复时同步 config('strategy.implemented')）
                'strategies' => ['spot_grid', 'spot_dca'],
            ],
        ],
        'enterprise' => [
            'name' => '企业版',
            'price_monthly' => 199,
            'features' => [
                'max_exchange_accounts' => 20,
                'max_strategy_instances' => 100,
                'max_signal_subscriptions' => 50,
                'ai_calls_per_day' => 10000,
                'orders_per_day' => 50000,
                'channels' => ['websocket', 'telegram', 'wechat_work', 'mail'],
                'strategies' => ['spot_grid', 'spot_dca'],
            ],
        ],
    ],
];
