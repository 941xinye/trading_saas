<?php

use App\Domain\Payment\Channels\BtcPayChannel;
use App\Domain\Payment\Channels\FakePaymentChannel;

/**
 * 支付通道与计费配置（spec 007 / data-model.md §6.1）
 *
 * 定义 USDT 收单通道（fake 离线 / btcpay 自托管）、支付单 TTL、续费提醒档位与渠道。
 * 密钥类配置从 env 注入，绝不入库、绝不入 git。
 */

return [

    // 默认通道：生产 MUST 显式 btcpay（research R-004，防「假通道收单」资损）
    'default_channel' => env('PAYMENT_CHANNEL', 'fake'),

    // 支付单有效期（分钟）
    'invoice_ttl_minutes' => (int) env('PAYMENT_INVOICE_TTL_MINUTES', 30),

    // 续费提醒档位（到期前 N 天，FR-007）
    'renewal_reminder_days' => [7, 3, 1],

    // 提醒发送渠道（须在租户套餐 channels 白名单内；log 系统渠道恒放行）
    'reminder_channels' => ['log', 'mail', 'telegram'],

    // 各通道配置
    'channels' => [
        'fake' => [
            'driver' => FakePaymentChannel::class,
            // 非此环境启用即抛异常（research R-004 红线）
            'allowed_env' => ['local', 'testing'],
            'settle_event' => 'fake.settled',
            // fake webhook 验签密钥（仅 local/testing 使用，无生产语义）
            'secret' => env('PAYMENT_FAKE_WEBHOOK_SECRET', 'fake-webhook-secret'),
        ],
        'btcpay' => [
            'driver' => BtcPayChannel::class,
            'base_url' => env('BTCPAY_BASE_URL'),
            'store_id' => env('BTCPAY_STORE_ID'),
            'api_key' => env('BTCPAY_API_KEY'),
            'webhook_secret' => env('BTCPAY_WEBHOOK_SECRET'),
            'settled_events' => ['InvoiceSettled'],
            'processing_events' => ['InvoiceProcessing', 'InvoiceReceivedPayment'],
            'expired_events' => ['InvoiceExpired'],
            'timeout' => 10,
        ],
    ],
];
