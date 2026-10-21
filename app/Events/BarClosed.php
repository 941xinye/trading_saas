<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * K 线 bar 关闭事件（平台级）。
 *
 * BarAggregator 写完 kline_bars 后触发。不带 tenant_id：bar 数据本身是平台共享，
 * 监听器（如有平台级聚合需求）按需消费。
 *
 * 广播不在此处：用户面向的信号/通知推送走 Reverb + Notification Channel，
 * 本 Event 仅用于应用内分发（Listener / 异步队列消费）。
 */
readonly class BarClosed
{
    use Dispatchable;

    public function __construct(
        public readonly string $exchangeCode,
        public readonly string $symbol,
        public readonly string $interval,
        public readonly array $barData,
    ) {
    }
}
