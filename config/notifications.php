<?php

/**
 * 多渠道通知配置
 *
 * 沿用 CRM 的 DedupCooldown 冷却期去重模式，避免同方向同 symbol 多 bar 连续信号刷屏。
 */

return [

    // 默认分发渠道
    'default_channels' => ['websocket', 'telegram', 'mail'],

    // 各渠道配置
    'channels' => [
        'telegram' => [
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
            'timeout' => 10,
            'enabled' => (bool) env('TELEGRAM_BOT_TOKEN', false),
        ],
        'wechat_work' => [
            'webhook' => env('WECHAT_WORK_WEBHOOK'),
            'key' => env('WECHAT_WORK_KEY'),
            'timeout' => 10,
            'enabled' => (bool) env('WECHAT_WORK_WEBHOOK', false),
        ],
        'mail' => [
            'enabled' => true,
            'throttle_per_minute' => 5,
        ],
        'websocket' => [
            'enabled' => true,
            // 推送到 Reverb private-tenant.{tenantId}.signals
            'channel_template' => 'private-tenant.{tenant_id}.signals',
        ],
    ],

    // 冷却去重配置
    'dedup' => [
        'driver' => 'redis',
        'key_prefix' => 'tenant:{tenant_id}:dedup:{entity}:{fingerprint}',
        // 信号去重：同 (symbol, direction, interval) 在 N 分钟内只通知一次
        'signal_cooldown_minutes' => 15,
        // 熔断通知去重：同 instance_id 在 N 分钟内只通知一次
        'breaker_cooldown_minutes' => 30,
        // 通用通知冷却（兜底）
        'default_cooldown_minutes' => 5,
    ],

    // 重试与超时（每个 channel 独立 Job，互不阻塞）
    'retry' => [
        'max_attempts' => 3,
        'backoff_seconds' => [10, 30, 90],
    ],
];
