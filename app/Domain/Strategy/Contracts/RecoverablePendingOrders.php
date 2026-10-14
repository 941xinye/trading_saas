<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Contracts;

use App\Domain\Exchange\Contracts\ExchangeOrder;

/**
 * 崩溃恢复时存在「待核实挂单」的策略（P5.5 步骤 1）。
 *
 * 进程重启 hydrate 快照后，对 pending 信号携带的 clientOrderId 逐个向交易所
 * 核实真实结果（PendingOrderRecovery）：
 *  - filled                  → applyRecoveredOrder 按成交入账
 *  - canceled/rejected/failed → applyRecoveredOrder 释放占位
 *  - 查无此单（null）         → applyRecoveredOrder 释放占位（交易所未受理）
 *  - submitted/partial        → 保持 pending（订单仍有效）
 *  - 查询网络异常             → 保持 pending（保守，绝不放行）
 */
interface RecoverablePendingOrders
{
    /**
     * 当前待核实的 clientOrderId 列表。
     *
     * @return string[]
     */
    public function pendingClientOrderIds(): array;

    /**
     * 回灌核实后的订单终态（filled 入账；其余终态/合成 failed 单释放占位）。
     */
    public function applyRecoveredOrder(ExchangeOrder $order): void;
}
