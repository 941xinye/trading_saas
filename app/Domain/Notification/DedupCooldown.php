<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use Illuminate\Support\Facades\Redis;

/**
 * 冷却去重（Redis SET NX EX）。
 *
 * key 模板（config notifications.dedup.key_prefix）：
 *   tenant:{tenant_id}:dedup:{entity}:{fingerprint}
 *
 * 信号场景 fingerprint=sha1(symbol|interval|direction)，15 分钟内同向信号只通知一次，
 * 避免 AI 在震荡行情连续同向触发导致刷屏（架构 §5.5）。
 */
class DedupCooldown
{
    /**
     * 尝试占用一个冷却槽位。
     *
     * @return bool true=首次（允许发送）；false=冷却中（应标记 deduped）
     */
    public function tryAcquire(int $tenantId, string $entity, string $fingerprint, int $ttlMinutes): bool
    {
        $key = sprintf('tenant:%d:dedup:%s:%s', $tenantId, $entity, $fingerprint);

        // phpredis 返回 bool；predis 返回 Status('OK')/null，两种驱动都兼容
        $result = Redis::connection('default')->set(
            $key,
            (string) time(),
            'EX',
            $ttlMinutes * 60,
            'NX',
        );

        return $result === true || $result === 'OK' || $result === 1;
    }
}
