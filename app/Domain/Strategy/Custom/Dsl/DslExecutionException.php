<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * 沙箱求值运行期异常（contracts §5 / FR-004）。
 *
 * 超 `eval_step_budget` 或耗时上限时抛出，携带实际消耗与触发的限额类别，
 * 供上层记录且不静默（FR-004 / SC-009）。
 */
final class DslExecutionException extends \RuntimeException
{
    public const KIND_STEP = 'step';

    public const KIND_TIME = 'time';

    public function __construct(
        string $message,
        public readonly int $costSteps = 0,
        public readonly string $limitKind = self::KIND_STEP,
    ) {
        parent::__construct($message);
    }
}
