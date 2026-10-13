<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Base;

use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyInterface;
use App\Domain\Strategy\Contracts\StrategyState;
use RuntimeException;

/**
 * 策略抽象基类。
 *
 * 实现 getState/restoreState 的 Redis 序列化骨架；
 * 子类（SpotGrid/FuturesGrid/DCA/马丁/滚仓/DSL）实现 onBar/onFill/shouldOpen/shouldClose。
 *
 * 状态持久化：写入 Redis `tenant:{tid}:strategy:{iid}:state`（JSON String），
 * 进程 B 启动时扫描 status in (running, paused_by_breaker) 的实例，
 * 起协程后调用 restoreState 完成崩溃恢复。
 *
 * 关键约束（架构方案 §9.4）：
 *  - 状态双写：Redis（每 bar）+ strategy_instances.position_snapshot（每 60s）
 *  - 关键操作先 Redis 后 DB（DB 失败有 Redis 兜底）
 *  - 反序列化按 schemaVersion 迁移：快照版本高于当前支持版本 → 拒绝启动（防脏数据）
 *  - params 以 DB 最新为准，不从快照回填（运行期间参数可能被改）
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
        return new StrategyState(
            schemaVersion: self::SCHEMA_VERSION,
            params: $this->params,
            positionSnapshot: $this->positionSnapshot,
            realizedPnl: $this->realizedPnl,
            consecutiveLosses: $this->consecutiveLosses,
            lastBarTime: $this->lastBarTime,
        );
    }

    public function restoreState(StrategyState $state): void
    {
        if ($state->schemaVersion > self::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'Strategy state schema version %d is newer than supported version %d (instance %d)',
                $state->schemaVersion,
                self::SCHEMA_VERSION,
                $this->instanceId,
            ));
        }

        // v1 快照直接映射；后续版本在此按 version 分支迁移
        $this->positionSnapshot = $state->positionSnapshot;
        $this->realizedPnl = $state->realizedPnl;
        $this->consecutiveLosses = $state->consecutiveLosses;
        $this->lastBarTime = $state->lastBarTime;
    }
}
