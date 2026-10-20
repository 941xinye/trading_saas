<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * DSL 编译产物（contracts §3 / data-model §2.1）。
 *
 * 不可变 AST，仅供 `DslInterpreter` 白名单求值；`sourceHash = sha1(原始脚本字节)`，
 * 用于崩溃恢复时校验脚本未被改写（FR-010）。
 */
final readonly class DslProgram
{
    /**
     * @param RuleNode[] $filters
     * @param RuleNode[] $entries
     * @param RuleNode[] $exits
     */
    public function __construct(
        public int $version,
        public DslMeta $meta,
        public array $filters,
        public array $entries,
        public array $exits,
        public DslRisk $risk,
        public string $sourceHash,
    ) {}
}
