<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * DSL 编辑器即时校验契约（P7 spec 006 WS5 / contracts §8）。
 *
 * 保存草稿与「试装载」共用：仅收集诊断、绝不启动实例、绝不抛异常（FR-011）。
 */
interface DslValidator
{
    /**
     * @return DslDiagnostic[] 空数组 = 通过
     */
    public function validate(string $script): array;
}
