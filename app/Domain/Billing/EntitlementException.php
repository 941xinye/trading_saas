<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use RuntimeException;

/**
 * 租户权益失效（suspended/canceled/past_due/试用到期/租户不存在）。
 *
 * 与配额耗尽区分：配额是「用量到顶」，权益是「账户状态不允许发生新动作」。
 */
class EntitlementException extends RuntimeException
{
}
