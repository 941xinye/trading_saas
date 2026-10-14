<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 熔断告警广播事件（Reverb websocket）。
 *
 * 推送到 private-tenant.{tenantId}.alerts 频道（routes/channels.php L36 已授权）。
 * 由 BroadcastBreakerAlert 同步 Listener 在 BreakerTripped 后触发。
 *
 * 前端可订阅该频道实时展示熔断状态迁移告警。
 */
readonly class BreakerAlert implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $instanceId,
        public readonly string $state,
        public readonly string $ruleType,
    ) {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->tenantId}.alerts"),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'state' => $this->state,
            'rule_type' => $this->ruleType,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'breaker.alert';
    }
}
