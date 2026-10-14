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
 * 下单 Job（orders 队列）。
 *
 * HTTP API / 策略调度器把订单请求投递到 orders 队列，由 order worker 调用
 * ExchangeAdapter::placeOrder() 真正落地。
 *
 * 多租户：构造时携带 tenantId，handle() 入口需 TenantContext::initialize($tenantId)
 * 恢复租户隔离，保证 TenantScope 在 Job 内查库时生效（沿用 CRM 项目的 Job 入口约定）。
 * 构造参数仅含可序列化标量/数组，Model 不直接传入，避免跨进程 Model 上下文丢失。
 *
 * POC 阶段：handle() 待实现，对应 P1 POC（架构方案 §3 / §5.1 ExchangeAdapter）。
 */
class PlaceOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 队列名：与下单链路解耦，避免和默认队列混用导致相互阻塞。
     * Laravel 13 Queueable trait 自带 $queue，构造内 onQueue() 指定（坑 #10）。
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $instanceId,
        public readonly array $orderPayload,
    ) {
        $this->onQueue('orders');
    }

    /**
     * P1 POC 实现：恢复 TenantContext → ExchangeManager::for($tenantId)->placeOrder(...)
     * → 落库 strategy_orders（client_order_id 幂等）→ 触发 OrderFilled event。
     */
    public function handle(): void
    {
        throw new RuntimeException('Not implemented. Wait for POC.');
    }
}
