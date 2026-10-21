<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Exceptions;

use RuntimeException;

/**
 * 寻优结果无法应用到策略实例。
 *
 * conflict = true 表示资源状态冲突（实例运行中），HTTP 映射 409；
 * 其余（任务未完成 / 无最优参数 / 实例不匹配）映射 422。
 */
final class OptimizationNotApplicableException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $conflict = false)
    {
        parent::__construct($message);
    }
}
