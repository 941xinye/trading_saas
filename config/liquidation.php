<?php

/**
 * 大户爆仓模块配置（P5）。
 *
 * - buckets: 按 symbol 的桶宽（约对应典型价格 0.1%）
 * - windows: 滑动窗口定义（5m/15m/60m），每档独立 Redis ZSET+HASH key
 * - retention: liquidation_events 表保留天数（夜间 prune）
 * - snapshot_interval_sec: 每多少秒把 Redis 桶快照写入 liquidation_clusters 表
 * - high_frequency_threshold: 每秒事件数降级阈值（极端行情跳过小额事件）
 * - small_event_threshold_usd: 小额事件阈值（高频降级时跳过 < 该值的事件）
 */

return [

    // 桶宽（字符串，bcmath 兼容）。default 用于未配置 symbol 的兜底
    'buckets' => [
        'default' => '1',
        'BTCUSDT' => '60',      // ~0.1% of 60000
        'ETHUSDT' => '3',       // ~0.1% of 3000
        'SOLUSDT' => '0.2',     // ~0.1% of 200
        'BNBUSDT' => '0.5',     // ~0.1% of 500
        // POC 测试用 symbol（poc:verify-liquidation 隔离测试，不污染生产 key）
        'POCVERIFYBTCUSDT' => '60',
        'POCVERIFYETHUSDT' => '3',
    ],

    // 滑动窗口（秒）+ TTL 倍数
    'windows' => [
        '5m'  => ['seconds' => 300,   'ttl_multiplier' => 2],
        '15m' => ['seconds' => 900,   'ttl_multiplier' => 2],
        '60m' => ['seconds' => 3600,  'ttl_multiplier' => 2],
    ],

    // 默认查询窗口
    'default_window' => '5m',

    // 默认返回 top N 密集区
    'default_top_n' => 10,

    // 事件落库保留天数（夜间 prune，分批 DELETE）
    'retention_days' => 30,

    // Redis 桶快照写入 liquidation_clusters 表的间隔（秒）
    'snapshot_interval_sec' => 60,

    // 高频降级：每秒事件数超过此值时跳过小额事件
    'high_frequency_threshold' => 200,    // events/sec
    'small_event_threshold_usd' => '50000', // $50k 以下事件在降级时跳过

    // Redis key 前缀
    'redis_prefix' => 'liq',
];
