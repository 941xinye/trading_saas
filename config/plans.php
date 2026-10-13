<?php

/**
 * 订阅计划配置
 *
 * 定义各计划的功能配额、特性开关。沿用 CRM 的 PlanService 思路。
 */

return [

    'default_plan' => 'free',

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
                'strategies' => ['spot_grid', 'spot_dca', 'futures_grid', 'futures_dca', 'smart_martingale'],
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
                'strategies' => [
                    'spot_grid', 'spot_dca',
                    'futures_grid', 'futures_dca',
                    'smart_martingale', 'trend_rolling', 'custom_dsl',
                ],
            ],
        ],
    ],
];
