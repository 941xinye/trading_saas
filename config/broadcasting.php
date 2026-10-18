<?php

/**
 * 广播配置（Reverb）。
 *
 * 之前缺失本文件导致 broadcasting.connections.* 全部为空、broadcast() 静默落入
 * BroadcastBreakerAlert 的 try/catch——B7 前端订阅补齐链路时必须补上。
 * 频道授权见 routes/channels.php；/broadcasting/auth 由 withRouting(channels:) 自动注册。
 */

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', 'localhost'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
