<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * 解释器求值结果（contracts §5 / data-model §4.2）。
 *
 * `costSteps` 为本次求值实际消耗步数，供 `eval_step_budget` 校验与 SC-009 上界断言。
 */
final readonly class DslEvalResult
{
    /**
     * @param DslSignal[] $signals
     */
    public function __construct(
        public array $signals,
        public int $costSteps,
    ) {}
}
