<?php

/**
 * 交易所适配器配置
 *
 * 定义各交易所 REST/WebSocket 端点、限流权重、健康检查间隔等。
 * 平台级共享配置，不区分租户。
 */

return [

    // 主密钥（KEK），从 env 注入，绝不入库
    'master_key' => env('EXCHANGE_MASTER_KEY'),

    // 签名请求 recvWindow（毫秒，本机与交易所时钟漂移容忍度）
    'recv_window' => env('EXCHANGE_RECV_WINDOW', 5000),

    // 默认交易所
    'default' => 'binance',

    // 各交易所配置
    'exchanges' => [
        'binance' => [
            'name' => 'Binance',
            'rest_url' => env('EXCHANGE_BINANCE_REST', 'https://api.binance.com'),
            'ws_url' => env('EXCHANGE_BINANCE_WS', 'wss://stream.binance.com:9443/stream'),
            'futures_rest_url' => env('EXCHANGE_BINANCE_FUTURES_REST', 'https://fapi.binance.com'),
            'futures_ws_url' => env('EXCHANGE_BINANCE_FUTURES_WS', 'wss://fstream.binance.com/ws'),
            // P1 POC：testnet 开关与端点（EXCHANGE_BINANCE_TESTNET=true 时 REST/WS 自动切换）
            'testnet' => env('EXCHANGE_BINANCE_TESTNET', false),
            'testnet_rest_url' => env('EXCHANGE_BINANCE_TESTNET_REST', 'https://testnet.binance.vision'),
            'testnet_ws_url' => env('EXCHANGE_BINANCE_TESTNET_WS', 'wss://stream.testnet.binance.vision:9443/stream'),
            'markets' => ['spot', 'futures'],
            // 1 分钟 weight 上限（Binance 默认 1200/分钟）
            'weight_limit_per_minute' => 1200,
            // 告警阈值
            'weight_warn_threshold' => 0.8,
            // 健康检查间隔（秒）
            'health_check_interval' => 60,
        ],
        'okx' => [
            'name' => 'OKX',
            'rest_url' => 'https://www.okx.com',
            'ws_url' => 'wss://ws.okx.com:8443/ws/v5/public',
            'markets' => ['spot', 'futures'],
            'weight_limit_per_minute' => 600,
            'weight_warn_threshold' => 0.8,
            'health_check_interval' => 60,
        ],
        'bybit' => [
            'name' => 'Bybit',
            'rest_url' => 'https://api.bybit.com',
            'ws_url' => 'wss://stream.bybit.com/v5/public/linear',
            'markets' => ['spot', 'futures'],
            'weight_limit_per_minute' => 600,
            'weight_warn_threshold' => 0.8,
            'health_check_interval' => 60,
        ],
        'bitget' => [
            'name' => 'Bitget',
            'rest_url' => 'https://api.bitget.com',
            'ws_url' => 'wss://ws.bitget.com/v2/ws/public',
            'markets' => ['spot', 'futures'],
            'weight_limit_per_minute' => 600,
            'weight_warn_threshold' => 0.8,
            'health_check_interval' => 60,
        ],
    ],

    // 限流令牌桶配置（按 exchange:endpoint 维度）
    'rate_limiter' => [
        'driver' => 'redis',
        'key_prefix' => 'market:ratelimit',
        // 1 分钟窗口
        'window_seconds' => 60,
    ],

    // WebSocket 重连配置（指数退避）
    'ws_reconnect' => [
        'enabled' => true,
        'initial_delay' => 1,       // 秒
        'max_delay' => 60,          // 秒
        'multiplier' => 2,
        'heartbeat_timeout' => 30,  // 30s 无 tick 主动重连
    ],

    // API Key 解密缓存（秒）
    'decrypt_cache_ttl' => 300, // 5 分钟，支持热轮换
];
