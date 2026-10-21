<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * 平台级全局急停。
 *
 * 语义：只停「开新仓」，平仓动作（RiskGuard::check('close') / reduceOnly）永远放行。
 * 存储：Redis string `platform:kill_switch`，值为 JSON（reason/actor/activated_at）。
 *
 * 与实例级熔断的关系：
 *  - CircuitBreaker 是单实例、由亏损指标自动触发；
 *  - KillSwitch 是平台级、由运维人工触发，优先级高于实例状态。
 *
 * 可用性取舍：Redis 不可用时 isActive() 返回 false（fail-open），避免缓存故障
 * 连带把正常交易锁死；但此时熔断状态机同样不可用，系统已处于降级状态。
 */
final class PlatformKillSwitch
{
    public const KEY = 'platform:kill_switch';

    public function isActive(): bool
    {
        try {
            return (int) Redis::connection()->exists(self::KEY) > 0;
        } catch (Throwable $e) {
            Log::warning('Platform kill switch read failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array{reason:string,actor:?string,activated_at:string}
     */
    public function activate(string $reason, ?string $actor = null): array
    {
        $payload = [
            'reason' => $reason,
            'actor' => $actor,
            'activated_at' => now()->toIso8601String(),
        ];

        Redis::connection()->set(self::KEY, json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $payload;
    }

    public function deactivate(): void
    {
        Redis::connection()->del(self::KEY);
    }

    /**
     * @return array{reason:string,actor:?string,activated_at:string}|null
     */
    public function status(): ?array
    {
        $raw = Redis::connection()->get(self::KEY);
        if ($raw === null || $raw === false) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : ['reason' => '', 'actor' => null, 'activated_at' => ''];
    }
}
