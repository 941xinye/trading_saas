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
            'rest_url' => env('EXCHANGE_OKX_REST', 'https://www.okx.com'),
            'ws_url' => env('EXCHANGE_OKX_WS', 'wss://ws.okx.com:8443/ws/v5/public'),
            'markets' => ['spot', 'futures'],
            // OKX 模拟盘开关（research.md R-005：REST 同域 + x-simulated-trading header 区分，无独立域名）
            'testnet' => env('EXCHANGE_OKX_TESTNET', false),
            'testnet_rest_url' => null, // OKX REST 模拟盘同域，由 doRequest 注入 x-simulated-trading:1
            'testnet_ws_url' => env('EXCHANGE_OKX_TESTNET_WS', 'wss://wspap.okx.com:8443/ws/v5/public'),
            // OKX 限流：2s 分类别窗口（research.md R-002），不用 Binance 的 1 分钟 weight 制
            'rate_limits' => [
                'public' => 20,  // 20 req/2s per IP
                'account' => 10, // 10 req/2s per UID
                'trade' => 60,   // 60 req/2s per UID
            ],
            'window_seconds' => 2,
            'weight_warn_threshold' => 0.8,
            'health_check_interval' => 60,
        ],
        'bybit' => [
            'name' => 'Bybit',
            'rest_url' => env('EXCHANGE_BYBIT_REST', 'https://api.bybit.com'),
            // v5 现货公共流路径 /v5/public/spot（002 R-001：修正原 linear 合约路径）
            'ws_url' => env('EXCHANGE_BYBIT_WS', 'wss://stream.bybit.com/v5/public/spot'),
            'markets' => ['spot', 'futures'],
            // Bybit testnet 独立域名（R-001：与 OKX header 标识机制不同，整体替换 base URL）
            'testnet' => env('EXCHANGE_BYBIT_TESTNET', false),
            'testnet_rest_url' => env('EXCHANGE_BYBIT_TESTNET_REST', 'https://api-testnet.bybit.com'),
            'testnet_ws_url' => env('EXCHANGE_BYBIT_TESTNET_WS', 'wss://stream-testnet.bybit.com/v5/public/spot'),
            // v5 无 weight 概念，按类别窗口限流（R-007：public 600/5s per IP，trade/account 10/s per UID）
            'rate_limits' => [
                'public' => ['limit' => 600, 'window' => 5],
                'trade' => ['limit' => 10, 'window' => 1],
                'account' => ['limit' => 10, 'window' => 1],
            ],
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
