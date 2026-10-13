<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Contracts;

use Illuminate\Contracts\Support\Arrayable;

/**
 * 策略状态快照 DTO。
 *
 * 序列化到 Redis Hash `tenant:{tid}:strategy:{iid}:state`，
 * 每隔 config('strategy.state.snapshot_interval') 秒同步一次。
 *
 * 实现 Arrayable 便于 json_encode/Redis hset 直接落盘。
 * schemaVersion 支持后续字段演进（按版本迁移反序列化逻辑）。
 */
readonly class StrategyState implements Arrayable
{
    public function __construct(
        public int $schemaVersion,
        public array $params,
        public ?array $positionSnapshot,
        public string $realizedPnl,
        public int $consecutiveLosses,
        public ?int $lastBarTime,
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'params' => $this->params,
            'position_snapshot' => $this->positionSnapshot,
            'realized_pnl' => $this->realizedPnl,
            'consecutive_losses' => $this->consecutiveLosses,
            'last_bar_time' => $this->lastBarTime,
        ];
    }

    /**
     * 从数组反序列化（Redis hgetall → StrategyState）。
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            schemaVersion: (int) ($payload['schema_version'] ?? 1),
            params: (array) ($payload['params'] ?? []),
            positionSnapshot: isset($payload['position_snapshot']) ? (array) $payload['position_snapshot'] : null,
            realizedPnl: (string) ($payload['realized_pnl'] ?? '0'),
            consecutiveLosses: (int) ($payload['consecutive_losses'] ?? 0),
            lastBarTime: isset($payload['last_bar_time']) ? (int) $payload['last_bar_time'] : null,
        );
    }
}
