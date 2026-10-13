<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

/**
 * 策略调度器（进程 B 内的协程容器）。
 *
 * 入口由 `php artisan market:feed` 启动，承担：
 *  - 扫描 status in (running, paused_by_breaker) 的实例 → 起协程
 *  - 每个策略实例一个协程，互不阻塞
 *  - 协程内：onTick 节流 / onBar 触发决策 / shouldOpen/shouldClose → 下单
 *  - 状态持久化（Redis + DB 双写）
 *  - 跨租户隔离：仅通过 Redis 带租户前缀的 key 共享
 *
 * 关键约束（架构方案 §9.5）：
 *  - 不持有跨租户静态状态
 *  - 协程内不缓存 tenant context
 *  - Swoole Table 仅用于进程内高频共享（订阅表/限流桶），不存租户敏感数据
 */
class StrategyRunner
{
    public function __construct()
    {
    }

    /**
     * 启动指定实例集合的协程调度。
     *
     * @param int[] $instanceIds strategy_instances.id 列表
     */
    public function start(array $instanceIds): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    /**
     * 停止指定实例的协程（优雅退出，保留 Redis 状态供恢复）。
     */
    public function stop(int $instanceId): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }
}
