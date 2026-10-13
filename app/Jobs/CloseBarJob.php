<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * K 线 bar close 处理 Job（aggregation 队列）。
 *
 * 进程 B（MarketFeedWorker）的 BarAggregator 在收到交易所 WS 推送、判定一根 bar
 * 关闭后投递此 Job 到 aggregation 队列。本 Job 是行情聚合与 AI 信号链路的衔接点。
 *
 * 平台级共享：bar 数据本身不带 tenant_id（架构方案 §4.6 kline_bars 平台级），
 * 因此本 Job 不接收 tenantId；写库后按订阅分发到各租户的 AiAnalyzeJob。
 *
 * 执行链路（POC 待实现）：
 *  1. 写 kline_bars 表（uniq(exchange_code, symbol, interval, open_time) 幂等 upsert）
 *  2. event(BarClosed::...) → 触发监听器（如有平台级聚合监听）
 *  3. 查 signal_subscriptions（按 symbols/intervals 匹配）
 *     → 为每个 (tenantId, subscriptionId) 调度 AiAnalyzeJob
 *
 * POC 阶段：handle() 待实现，对应 P1 POC（行情聚合 + 触发 AI 链路）。
 */
class CloseBarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 队列名：聚合写库与下游调度独立成队，与订单/AI 解耦。
     */
    public $queue = 'aggregation';

    public function __construct(
        public readonly string $exchangeCode,
        public readonly string $symbol,
        public readonly string $interval,
        public readonly array $barData,
    ) {
    }

    /**
     * P1 POC 实现：写 kline_bars → event(BarClosed) → 调度 AiAnalyzeJob
     * （按订阅匹配 symbol/interval，多租户各自一份）。
     */
    public function handle(): void
    {
        throw new RuntimeException('Not implemented. Wait for POC.');
    }
}
