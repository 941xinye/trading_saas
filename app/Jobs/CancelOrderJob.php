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
 * 撤单 Job（orders 队列）。
 *
 * 与 PlaceOrderJob 共用 orders 队列：撤单和下单共享同一限流桶和租户上下文，
 * 避免两个队列相互争抢交易所权重配额。
 *
 * 幂等：clientOrderId 为策略实例生成的幂等键，重复撤单以交易所返回状态为准；
 * 策略层应容忍 already-canceled / already-filled 等终态响应。
 *
 * POC 阶段：handle() 待实现，对应 P1 POC（架构方案 §5.1 ExchangeAdapter::cancelOrder）。
 */
class CancelOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 队列名：与下单共用，便于共享限流配置与租户上下文初始化。
     */
    public $queue = 'orders';

    public function __construct(
        public readonly int $tenantId,
        public readonly string $symbol,
        public readonly string $orderId,
        public readonly ?string $clientOrderId = null,
    ) {
    }

    /**
     * P1 POC 实现：恢复 TenantContext → ExchangeManager::for($tenantId)->cancelOrder(...)
     * → 更新 strategy_orders.status=canceled（幂等）。
     */
    public function handle(): void
    {
        throw new RuntimeException('Not implemented. Wait for POC.');
    }
}
