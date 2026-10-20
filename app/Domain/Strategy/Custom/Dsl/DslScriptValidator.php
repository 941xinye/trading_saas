<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

use App\Domain\Strategy\Custom\DslParser;

/**
 * DSL 校验器实现（P7 spec 006 WS5 / contracts §8）。
 *
 * 包装 `DslParser::parse()`：编译通过 → 空诊断；非法 → 返回 `DslValidationException`
 * 携带的诊断数组（含 行/字段），不抛异常、不启动策略实例（FR-011/FR-016）。
 */
final class DslScriptValidator implements DslValidator
{
    public function __construct(private readonly ?DslParser $parser = null) {}

    public function validate(string $script): array
    {
        try {
            ($this->parser ?? new DslParser)->parse($script);

            return [];
        } catch (DslValidationException $e) {
            return $e->diagnostics();
        }
    }
}
