<?php

declare(strict_types=1);

namespace App\Domain\Signal\Exceptions;

use RuntimeException;

/**
 * 信号无法执行下单。
 *
 * conflict = true 表示资源状态冲突（重复执行 / 实例运行状态不符），HTTP 映射 409；
 * 其余（方向非法 / 信号过期 / 实例不匹配 / 资金与凭据问题）映射 422。
 */
final class SignalExecutionException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $conflict = false)
    {
        parent::__construct($message);
    }
}
