<?php

declare(strict_types=1);

namespace App\Domain\Billing\Metering;

use RuntimeException;

/**
 * 配额耗尽（日计量闸门拒绝 / 资源数超限）。
 *
 * - 日计量（eventType=ai_call/order_placed）：Redis 原子闸门拒绝时抛出，携带 limit/used
 * - 资源数（eventType=max_*）：创建资源前计数超限时抛出
 *
 * 调用方按领域语义决定降级（AI→规则兜底；订单→failedOrder），不应打断调度主循环。
 */
class QuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $eventType,
        public readonly int $limit,
        public readonly int $used,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            'Quota exceeded: tenant=%d type=%s used=%d/%d',
            $tenantId,
            $eventType,
            $used,
            $limit,
        ));
    }
}
