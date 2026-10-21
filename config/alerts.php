<?php

declare(strict_types=1);

/**
 * 系统告警规则配置。
 *
 * 规则由 ops:alerts:check 命令周期性评估，命中后通过 alerts.channels 投递
 * （默认 log，可配置 telegram/mail/wechat_work）。同一规则命中后冷却
 * cooldown_minutes 分钟内不重复通知。
 */
return [

    'enabled' => (bool) env('ALERTS_ENABLED', true),

    // 评估周期（分钟）；调度器需自行配置 schedule:run 调用 ops:alerts:check
    'check_interval_minutes' => (int) env('ALERTS_CHECK_INTERVAL', 1),

    // 同一规则命中后的通知冷却（分钟）
    'cooldown_minutes' => (int) env('ALERTS_COOLDOWN_MINUTES', 15),

    // 告警投递渠道（复用 notifications.channel_classes）
    'channels' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALERTS_CHANNELS', 'log')),
    ))),

    'rules' => [
        'order_failure_rate_high' => [
            'severity' => 'critical',
            'metric' => 'trading_orders_total',
            'type' => 'rate',
            'numerator_status' => ['failed', 'entitlement_denied', 'daily_order_quota_exceeded', 'quantity_aligned_to_zero', 'db_insert_failed'],
            'threshold' => 0.10,
            'min_total' => 10,
            'message' => '订单失败率 {value}% 超过阈值 {threshold}%',
        ],
        'queue_backlog_high' => [
            'severity' => 'warning',
            'metric' => 'trading_queue_backlog',
            'type' => 'max',
            'threshold' => 1000,
            'message' => '队列积压 {value} 超过阈值 {threshold}',
        ],
        'breaker_open' => [
            'severity' => 'warning',
            'metric' => 'trading_breaker_open',
            'type' => 'sum',
            'threshold' => 0,
            'message' => '存在 {value} 个熔断中的策略实例',
        ],
        'signal_skip_rate_high' => [
            'severity' => 'warning',
            'metric' => 'trading_signals_total',
            'type' => 'rate',
            'numerator_decision' => ['skipped'],
            'denominator_decision' => ['queued', 'skipped'],
            'threshold' => 0.50,
            'min_total' => 5,
            'message' => '自动跟单跳过率 {value}% 超过阈值 {threshold}%',
        ],
    ],
];
