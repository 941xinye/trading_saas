<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Base;

use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\Contracts\StrategyState;

/**
 * 策略抽象基类。
 *
 * 实现 getState/restoreState 的 Redis 序列化骨架；
 * 子类（SpotGrid/FuturesGrid/DCA/马丁/滚仓/DSL）实现 onBar/onFill/shouldOpen/shouldClose。
 *
 * 状态持久化：写入 Redis `tenant:{tid}:strategy:{iid}:state`（Hash），
 * 进程 B 启动时扫描 status in (running, paused_by_breaker) 的实例，
 * 起协程后调用 restoreState 完成崩溃恢复。
 *
 * 关键约束（继承自架构方案 §9.4）：
 *  - 状态双写：Redis Hash（高频）+ strategy_instances.position_snapshot（每 N 秒刷一次）
 *  - 关键操作先 Redis 后 DB（DB 失败有 Redis 兜底）
 *  - 反序列化按 schemaVersion 迁移
 */
abstract class AbstractStrategy implements StrategyInterface
{
    protected int $instanceId;
    /** @var array<string,mixed> */
    protected array $params;
    protected StrategyContext $context;

    /** @var array<string,mixed>|null 内存态持仓快照 */
    protected ?array $positionSnapshot = null;
    protected string $realizedPnl = '0';
    protected int $consecutiveLosses = 0;
    protected ?int $lastBarTime = null;

    protected const SCHEMA_VERSION = 1;

    public function __construct(int $instanceId, array $params, StrategyContext $context)
    {
        $this->instanceId = $instanceId;
        $this->params = $params;
        $this->context = $context;
    }

    public function getState(): StrategyState
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function restoreState(StrategyState $state): void
    {
        // 骨架阶段：仅注入基础字段，具体迁移逻辑 P1 POC 落地
        $this->positionSnapshot = $state->positionSnapshot;
        $this->realizedPnl = $state->realizedPnl;
        $this->consecutiveLosses = $state->consecutiveLosses;
        $this->lastBarTime = $state->lastBarTime;

        if ($state->schemaVersion !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
        }
    }
}
