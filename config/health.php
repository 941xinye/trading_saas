<?php

return [
    // 单项探活超时（ClickHouse HTTP 探针；DB/Redis 走各自连接超时）
    'timeout_ms' => (int) env('HEALTH_TIMEOUT_MS', 1500),

    // 近 24h 失败任务超过该阈值 → degraded（0 表示只要有失败就降级）
    'failed_jobs_threshold' => (int) env('HEALTH_FAILED_JOBS_THRESHOLD', 10),

    // 队列积压超过该阈值 → degraded（仅 redis/database 驱动可读）
    'queue_backlog_threshold' => (int) env('HEALTH_QUEUE_BACKLOG_THRESHOLD', 1000),

    // 参与积压统计的队列名（与 deploy/supervisor 的 worker 队列保持一致）
    'queue_names' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HEALTH_QUEUE_NAMES', 'default,ai,notifications,orders,backtest')),
    ))),

    'checks' => [
        // ClickHouse 为冷层可选依赖：未部署时不参与判定
        'clickhouse' => (bool) env('HEALTH_CHECK_CLICKHOUSE', true),
    ],
];
