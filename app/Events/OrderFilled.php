<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 订单成交事件（租户级）。
 *
 * 由 PlaceOrderJob 落库后、或进程 B 收到交易所 WS order trade 推送时触发。
 * side ∈ {buy, sell}，filledQty 为字符串以保留精度（decimal 字段统一字符串化，
 * 避免浮点丢失，沿用 CRM 的 BigDecimal 处理约定）。
 *
 * 监听器职责（POC 阶段填实）：
 *  - StrategyInterface::onFill($order) → 策略状态机推进
 *  - 风控更新（max_loss_per_trade / consecutive_losses 等触发器）
 *  - Reverb 推 private-tenant.{tid}.orders 频道
 */
readonly class OrderFilled
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $instanceId,
        public readonly string $orderId,
        public readonly string $symbol,
        public readonly string $side,
        public readonly string $filledQty,
    ) {
    }
}
