<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * 沙箱解释器契约（contracts §5 / FR-003/017）。
 *
 * 白名单求值：输出信号 + 消耗步数。绝不 eval/exec/反射/IO。
 * 指标快照仅计算一次（data-model §5）；NaN/null ⇒ 对应条件 false。
 */
interface DslInterpreter
{
    /**
     * @throws DslExecutionException 超 step/time budget（不静默）
     */
    public function evaluate(DslProgram $program, DslEvalContext $context): DslEvalResult;
}
