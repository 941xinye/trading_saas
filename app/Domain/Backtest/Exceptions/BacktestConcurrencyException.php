<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Exceptions;

/**
 * 回测并发冲突。
 *
 * 同实例已有进行中回测，或租户并发数超套餐上限时抛出（FR-009）。
 * 拒绝语义：不排队，明确错误提示稍后重试（research R-007）。
 */
class BacktestConcurrencyException extends \RuntimeException
{
    public function __construct(string $message = '回测并发冲突：同实例已有进行中任务或租户并发超限（FR-009）')
    {
        parent::__construct($message);
    }
}
