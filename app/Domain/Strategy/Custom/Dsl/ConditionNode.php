<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * DSL 条件节点（contracts §3 / data-model §2.3）。
 *
 * 按 `type` 判别：`comparison` / `cross` / `logical` / `always`。
 */
final readonly class ConditionNode
{
    /**
     * @param ConditionNode[] $operands
     */
    public function __construct(
        public string $type,
        public ?string $op = null,
        public ?OperandNode $left = null,
        public ?OperandNode $right = null,
        public array $operands = [],
        public ?ConditionNode $operand = null,
    ) {}

    public static function always(): self
    {
        return new self(type: 'always');
    }

    public static function comparison(string $op, OperandNode $left, OperandNode $right): self
    {
        return new self(type: 'comparison', op: $op, left: $left, right: $right);
    }

    public static function cross(string $op, OperandNode $left, OperandNode $right): self
    {
        return new self(type: 'cross', op: $op, left: $left, right: $right);
    }

    /**
     * @param ConditionNode[] $operands
     */
    public static function logical(string $op, array $operands = [], ?ConditionNode $operand = null): self
    {
        return new self(type: 'logical', op: $op, operands: $operands, operand: $operand);
    }
}
