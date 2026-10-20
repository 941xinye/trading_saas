<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * DSL 规则节点（contracts §3 / data-model §2.2）。
 *
 * 一条规则 = 动作 + 条件 + 数量（`qty` 与 `qtyPct` 二选一）+ 备注。
 */
final readonly class RuleNode
{
    public function __construct(
        public string $action,
        public ConditionNode $condition,
        public ?string $qty = null,
        public ?string $qtyPct = null,
        public ?string $note = null,
    ) {}
}
