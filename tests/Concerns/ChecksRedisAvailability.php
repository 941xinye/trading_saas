<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * 外部服务可用性守卫（P5.5 步骤 0）。
 *
 * 约定：依赖真实 Redis 的 @group redis 测试必须在 setUp 中先调用 requireRedis()。
 * 服务不可用时 markTestSkipped（计为 skipped），绝不允许连接异常导致 error/fail——
 * 这样「停掉本机 Redis」后整套测试依然是绿的，CI 有 redis service 时断言真实执行。
 */
trait ChecksRedisAvailability
{
    protected function requireRedis(string $connection = 'default'): void
    {
        try {
            Redis::connection($connection)->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped(sprintf(
                'Redis 连接不可用，跳过 @group redis 测试（%s: %s）',
                $connection,
                $e->getMessage(),
            ));
        }
    }
}
