<?php

declare(strict_types=1);

namespace App\Domain\Risk\Contracts;

use App\Domain\Exchange\Contracts\ExchangeOrder;

/**
 * 风控守卫接口契约。
 *
 * 在 Strategy.shouldOpen 与 ExchangeAdapter.placeOrder 之间拦截，
 * 统一编排 4 维度 Rule（单笔/日内/回撤/连亏）+ 熔断状态机。
 *
 * 触发后动作（架构 §6）：
 *  1. 任一 Rule 触发 → 状态转 OPEN → 调度器协程暂停该实例（status=paused_by_breaker）
 *  2. 写 circuit_breaker_events 审计记录
 *  3. 分发通知（带冷却去重）
 *  4. 等待恢复窗口 → 转 HALF_OPEN
 *  5. HALF_OPEN 放行一笔探测单：盈利 → CLOSED；亏损 → OPEN 重置计时
 *
 * 关键约束：熔断只暂停策略的"开新仓"动作，不平掉已有持仓。
 */
interface RiskGuard
{
    /**
     * 在下单前做风控检查。
     *
     * @param int                 $instanceId 策略实例 ID
     * @param string              $action     动作类型 open|close|probe
     * @param array<string,mixed> $payload    载荷：side/qty/price 等
     */
    public function check(int $instanceId, string $action, array $payload = []): RiskDecision;

    /**
     * 记录一笔成交，驱动 4 维度计算。
     *
     * @param int            $instanceId 策略实例 ID
     * @param ExchangeOrder $fill       成交订单
     */
    public function recordTrade(int $instanceId, ExchangeOrder $fill): void;

    /**
     * 尝试恢复：HALF_OPEN → CLOSED（探测单盈利后调用）。
     *
     * @return bool 是否成功恢复到 CLOSED
     */
    public function attemptRecovery(int $instanceId): bool;

    /**
     * 获取当前熔断状态。
     *
     * @return string closed|open|half_open
     */
    public function getBreakerState(int $instanceId): string;
}
