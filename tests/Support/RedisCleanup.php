<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Redis 键清理 trait —— 统一处理 TTL 去重键 / 日计数桶 / dedup 槽 的 setUp/tearDown 清理。
 *
 * 坑 24：tearDown 外部 IO 必须用可用性标志 + try/catch，且先于 parent::tearDown()。
 * 坑 26：phpredis 全局前缀下 keys() 返回带前缀全名，del 会二次拼接删不掉；
 *        本 trait 一律按**逻辑名**（无前缀）调用 Redis facade，客户端自动拼一次前缀。
 * 坑 25：全套连跑时上一个测试的 TTL 键残留会让下一个测试假红；
 *        凡是测试间共享、生产语义带 TTL 的键（dedup/限流桶/日计数/unknown streak）
 *        必须在 setUp 和 tearDown **都**调本 trait。
 *
 * 用法：
 *   use Tests\Support\RedisCleanup;
 *
 *   protected function setUp(): void
 *   {
 *       parent::setUp();
 *       $this->cleanupRedis([
 *           'tenant:'.$this->tenantId.':dedup:...',
 *           'tenant:'.$this->tenantId.':quota:...',
 *       ]);
 *   }
 *
 *   protected function tearDown(): void
 *   {
 *       try {
 *           $this->cleanupRedis($this->redisKeys);
 *       } catch (\Throwable) {
 *           // tearDown 清理异常不得污染 RefreshDatabase 事务
 *       }
 *       parent::tearDown();
 *   }
 */
trait RedisCleanup
{
    /**
     * 精确删除若干逻辑名键。
     *
     * @param string[] $logicalKeys 键名（无前缀，Redis facade 自动拼一次）
     */
    protected function cleanupRedis(array $logicalKeys): void
    {
        if ($logicalKeys === []) {
            return;
        }

        try {
            $client = Redis::connection('default');
            foreach ($logicalKeys as $key) {
                if ($key !== '') {
                    $client->del($key);
                }
            }
        } catch (\Throwable) {
            // Redis 不可用时静默跳过（坑 24）
        }
    }

    /**
     * 按 pattern 批量删除，返回删除数量。
     *
     * 注意：phpredis 下 keys() 返回的是**已拼前缀**的全名，
     * 必须 strip 前缀再 del 才不会二次拼接（坑 26）。
     */
    protected function flushRedisPattern(string $pattern): int
    {
        try {
            $client = Redis::connection('default');
            $prefix = $client->getOption(\Redis::OPT_PREFIX);
            $keys = $client->keys($pattern);
            if ($keys === []) {
                return 0;
            }

            $count = 0;
            foreach ($keys as $fullKey) {
                $logicalKey = is_string($prefix) && $prefix !== ''
                    ? str_replace($prefix, '', $fullKey)
                    : $fullKey;
                $count += $client->del($logicalKey);
            }

            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }
}
