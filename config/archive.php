<?php

/**
 * P8 ClickHouse 历史归档配置（spec 004 / plan R-001 / R-005 / R-011）。
 *
 * - ch: ClickHouse 单节点 HTTP 连接（零新增 Composer 依赖，Http facade 直连）
 * - types: 四类时序数据的归档开关与限速参数（纯时间片语义，R-005）
 * - 查询/校验参数：统一历史查询与三级校验的公共上限
 *
 * 安全红线（contracts/archive-pipeline-contract.md §3 红线 4）：
 *   chunk_size >= 100、throttle_ms >= 50、query_max_rows <= 200000，
 *   命令层校验违反即退出码 2。
 */

return [

    // ClickHouse 连接（R-001 env 键集；HTTP 接口端口默认 8123）
    'ch' => [
        'host' => env('ARCHIVE_CH_HOST', '127.0.0.1'),
        'port' => env('ARCHIVE_CH_PORT', 8123),
        'database' => env('ARCHIVE_CH_DATABASE', 'trading_archive'),
        'user' => env('ARCHIVE_CH_USER', 'default'),
        'password' => env('ARCHIVE_CH_PASSWORD', ''),
        'timeout_ms' => (int) env('ARCHIVE_CH_TIMEOUT_MS', 30000),
    ],

    // 每类数据的归档策略（data_type 字符串与 ArchiveDataType enum 值一致）
    'types' => [
        'kline_bar' => [
            'enabled' => env('ARCHIVE_KLINE_BAR_ENABLED', true),
            'retention_days' => (int) env('ARCHIVE_KLINE_BAR_RETENTION_DAYS', 90),
            'batch_window_hours' => 24,
            'chunk_size' => 1000,
            'throttle_ms' => 200,
        ],
        'signal' => [
            'enabled' => env('ARCHIVE_SIGNAL_ENABLED', true),
            'retention_days' => (int) env('ARCHIVE_SIGNAL_RETENTION_DAYS', 90),
            'batch_window_hours' => 24,
            'chunk_size' => 1000,
            'throttle_ms' => 200,
        ],
        'strategy_order' => [
            'enabled' => env('ARCHIVE_STRATEGY_ORDER_ENABLED', true),
            'retention_days' => (int) env('ARCHIVE_STRATEGY_ORDER_RETENTION_DAYS', 90),
            'batch_window_hours' => 24,
            'chunk_size' => 1000,
            'throttle_ms' => 200,
        ],
        'liquidation_event' => [
            'enabled' => env('ARCHIVE_LIQUIDATION_EVENT_ENABLED', true),
            'retention_days' => (int) env('ARCHIVE_LIQUIDATION_EVENT_RETENTION_DAYS', 90),
            'batch_window_hours' => 6, // 高频小窗口
            'chunk_size' => 1000,
            'throttle_ms' => 200,
        ],
    ],

    // 统一历史查询分页（FR-011）：单页默认 1000，硬上限超限显式抛错不静默截断
    'query_page_size' => 1000,
    'query_max_rows' => 50000,

    // 三级校验抽样行数（R-006 第三级）
    'verify_sample_rows' => 5,

    // 命令参数最小值下限（红线 4，archive:run 校验）
    'min_chunk_size' => 100,
    'min_throttle_ms' => 50,
    'max_query_rows' => 200000,
];
