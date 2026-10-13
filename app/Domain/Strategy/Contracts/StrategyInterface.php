<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Contracts;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangeTicker;

/**
 * 策略接口契约。
 *
 * 进程 B 内的 StrategyRunner 持有每个策略实例的实例化对象，
 * 按 onTick（高频）/ onBar（决策点）/ onFill（订单回调）顺序驱动。
 *
 * 生命周期：
 *  1. StrategyRunner 启动 → new Strategy(instanceId, params, context)
 *  2. restoreState() 从 Redis 恢复崩溃前状态
 *  3. 协程循环：onTick → onBar（bar close）→ shouldOpen/shouldClose → 下单
 *  4. ExchangeAdapter 回调 onFill
 *  5. 每 N 秒持久化 getState()
 */
interface StrategyInterface
{
    /**
     * @param int             $instanceId strategy_instances.id
     * @param array<string,mixed> $params    实例参数（已合并默认值）
     * @param StrategyContext $context    运行时上下文（持仓/余额/最近 bar/ticker）
     */
    public function __construct(int $instanceId, array $params, StrategyContext $context);

    /**
     * 高频 tick 回调，仅更新内存态，禁止写库/写 Redis。
     */
    public function onTick(ExchangeTicker $ticker): void;

    /**
     * 低频 bar close 回调，决策点（shouldOpen/shouldClose 在此处或之后调用）。
     */
    public function onBar(ExchangeKline $bar): void;

    /**
     * 订单成交回调，更新本地持仓/盈亏。
     */
    public function onFill(ExchangeOrder $order): void;

    /**
     * 是否应该开仓。
     *
     * @return array{0:string,1:string,2:?string}|null [side, qty, price]，null 表示不开仓
     */
    public function shouldOpen(StrategyContext $ctx): ?array;

    /**
     * 是否应该平仓。
     *
     * @return array{0:string,1:string,2:?string}|null [side, qty, price]，null 表示不平仓
     */
    public function shouldClose(StrategyContext $ctx): ?array;

    /**
     * 获取当前状态用于持久化到 Redis。
     */
    public function getState(): StrategyState;

    /**
     * 崩溃恢复：从 Redis 反序列化状态。按 schemaVersion 迁移。
     */
    public function restoreState(StrategyState $state): void;
}
