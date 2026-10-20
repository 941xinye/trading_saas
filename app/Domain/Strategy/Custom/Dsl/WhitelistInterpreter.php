<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Custom\Dsl;

/**
 * 沙箱白名单解释器（tasks T009 / contracts §5 / FR-003/004/017）。
 *
 * 判别式 AST 的 `match` 求值，仅原生比较/逻辑/交叉/恒真；绝不 eval/exec/反射/IO。
 * NaN/null ⇒ 条件 false。任一节点求值计 1 step；超 `eval_step_budget` 或耗时上限即抛
 * `DslExecutionException`（不静默，FR-004 / SC-009）。
 *
 * `filter` 段为前置闸门：任一 filter 条件不成立则整 bar 不产生 entry/exit 信号；
 * filter 规则若带非 noop 动作，条件成立时一并产出 `section=filter` 的信号（不静默丢弃动作）。
 */
final class WhitelistInterpreter implements DslInterpreter
{
    private const DEFAULT_STEP_BUDGET = 500;

    private const DEFAULT_TIME_BUDGET_MS = 100;

    private const EPSILON = 1.0e-9;

    private int $stepBudget;

    private int $timeBudgetMs;

    private int $steps = 0;

    private float $startedAt = 0.0;

    public function __construct(?int $stepBudget = null, ?int $timeBudgetMs = null)
    {
        $this->stepBudget = $stepBudget ?? self::resolveInt('eval_step_budget', self::DEFAULT_STEP_BUDGET);
        $this->timeBudgetMs = $timeBudgetMs ?? self::DEFAULT_TIME_BUDGET_MS;
    }

    public function evaluate(DslProgram $program, DslEvalContext $context): DslEvalResult
    {
        $this->steps = 0;
        $this->startedAt = microtime(true);

        $signals = [];

        // filter 段：前置闸门——任一条件不成立即整 bar 无信号
        foreach ($program->filters as $index => $rule) {
            if (! $this->condition($rule->condition, $context)) {
                return new DslEvalResult([], $this->steps);
            }
            if ($rule->action !== 'noop') {
                $signals[] = $this->signal('filter', $index, $rule);
            }
        }

        foreach ($program->entries as $index => $rule) {
            if ($rule->action !== 'noop' && $this->condition($rule->condition, $context)) {
                $signals[] = $this->signal('entry', $index, $rule);
            }
        }

        foreach ($program->exits as $index => $rule) {
            if ($rule->action !== 'noop' && $this->condition($rule->condition, $context)) {
                $signals[] = $this->signal('exit', $index, $rule);
            }
        }

        return new DslEvalResult($signals, $this->steps);
    }

    private function condition(ConditionNode $node, DslEvalContext $context): bool
    {
        $this->tick();

        return match ($node->type) {
            'always' => true,
            'comparison' => $this->comparison($node, $context),
            'cross' => $this->cross($node, $context),
            'logical' => $this->logical($node, $context),
            default => false, // 不可达：编译期白名单已拒（FR-002）
        };
    }

    private function comparison(ConditionNode $node, DslEvalContext $context): bool
    {
        $left = $this->value($node->left, $context);
        $right = $this->value($node->right, $context);

        if ($left === null || $right === null) {
            return false; // NaN/null ⇒ false（FR-008）
        }

        return match ($node->op) {
            'gt' => $left > $right,
            'gte' => $left >= $right,
            'lt' => $left < $right,
            'lte' => $left <= $right,
            'eq' => abs($left - $right) < self::EPSILON,
            'ne' => abs($left - $right) >= self::EPSILON,
            default => false,
        };
    }

    private function cross(ConditionNode $node, DslEvalContext $context): bool
    {
        $curLeft = $this->value($node->left, $context);
        $curRight = $this->value($node->right, $context);
        $prevLeft = $this->prevValue($node->left, $context);
        $prevRight = $this->prevValue($node->right, $context);

        if ($curLeft === null || $curRight === null || $prevLeft === null || $prevRight === null) {
            return false;
        }

        return match ($node->op) {
            'cross_above' => $prevLeft <= $prevRight && $curLeft > $curRight,
            'cross_below' => $prevLeft >= $prevRight && $curLeft < $curRight,
            default => false,
        };
    }

    private function logical(ConditionNode $node, DslEvalContext $context): bool
    {
        return match ($node->op) {
            'and' => $this->every($node->operands, $context),
            'or' => $this->some($node->operands, $context),
            'not' => $node->operand !== null && ! $this->condition($node->operand, $context),
            default => false,
        };
    }

    /**
     * @param ConditionNode[] $nodes
     */
    private function every(array $nodes, DslEvalContext $context): bool
    {
        foreach ($nodes as $node) {
            if (! $this->condition($node, $context)) {
                return false; // 短路
            }
        }

        return true;
    }

    /**
     * @param ConditionNode[] $nodes
     */
    private function some(array $nodes, DslEvalContext $context): bool
    {
        foreach ($nodes as $node) {
            if ($this->condition($node, $context)) {
                return true;
            }
        }

        return false;
    }

    private function value(?OperandNode $operand, DslEvalContext $context): ?float
    {
        $this->tick();

        if ($operand === null) {
            return null;
        }

        return match ($operand->type) {
            'const' => $this->num($operand->const),
            'price' => $this->price($context, $operand->price, false),
            'indicator' => $this->indicator($context, $operand, false),
            default => null,
        };
    }

    private function prevValue(?OperandNode $operand, DslEvalContext $context): ?float
    {
        $this->tick();

        if ($operand === null) {
            return null;
        }

        return match ($operand->type) {
            'const' => $this->num($operand->const),
            'price' => $this->price($context, $operand->price, true),
            'indicator' => $this->indicator($context, $operand, true),
            default => null,
        };
    }

    /**
     * `ma` / `macd.hist` 复用计算器内置 prev；其余操作数取 shifted snapshot（data-model §5）。
     */
    private function indicator(DslEvalContext $context, OperandNode $operand, bool $prev): ?float
    {
        $snapshot = $context->indicatorSnapshot;
        $now = $snapshot['now'] ?? null;
        if (! is_array($now)) {
            return null;
        }

        switch ($operand->indicator) {
            case 'ma':
                $block = $now['ma'][$operand->period] ?? null;
                if (! is_array($block)) {
                    return null;
                }

                return $this->num($block[$prev ? 'prev' : 'v'] ?? null);

            case 'macd':
                $source = $operand->source ?? 'dif';
                if ($prev && $source === 'hist') {
                    return $this->num($now['macd']['hist_prev'] ?? null);
                }

                return $this->num($this->fromSnapshot($snapshot, $prev, 'macd')[$source] ?? null);

            case 'rsi':
            case 'atr':
                return $this->num($this->fromSnapshot($snapshot, $prev, $operand->indicator)[$operand->period] ?? null);

            case 'bollinger':
                $key = $this->bollKey($operand->period, $operand->stdDev ?? '2');
                $bands = $this->fromSnapshot($snapshot, $prev, 'boll')[$key] ?? null;

                return is_array($bands) ? $this->num($bands[$operand->band ?? ''] ?? null) : null;

            default:
                return null;
        }
    }

    /**
     * @param array<string,mixed> $snapshot
     *
     * @return array<mixed>
     */
    private function fromSnapshot(array $snapshot, bool $prev, string $key): array
    {
        $block = $prev ? ($snapshot['prev'] ?? null) : ($snapshot['now'] ?? null);
        if (! is_array($block) || ! is_array($block[$key] ?? null)) {
            return [];
        }

        return $block[$key];
    }

    private function price(DslEvalContext $context, ?string $field, bool $prev): ?float
    {
        if ($field === null) {
            return null;
        }

        if (! $prev && $field === 'last' && $context->ticker !== null) {
            return $this->num($context->ticker->lastPrice);
        }

        if (! $prev && $context->bar !== null) {
            $value = match ($field) {
                'open' => $context->bar->open,
                'high' => $context->bar->high,
                'low' => $context->bar->low,
                'close' => $context->bar->close,
                default => null,
            };
            if ($value !== null) {
                return $this->num($value);
            }
        }

        $block = $prev ? ($context->indicatorSnapshot['prev'] ?? null) : ($context->indicatorSnapshot['now'] ?? null);
        $prices = is_array($block) ? ($block['price'] ?? null) : null;

        return is_array($prices) ? $this->num($prices[$field] ?? null) : null;
    }

    private function signal(string $section, int $index, RuleNode $rule): DslSignal
    {
        return new DslSignal(
            section: $section,
            ruleIndex: $index,
            action: $rule->action,
            qty: $rule->qty,
            qtyPct: $rule->qtyPct,
            note: $rule->note,
        );
    }

    private function tick(): void
    {
        $this->steps++;

        if ($this->steps > $this->stepBudget) {
            throw new DslExecutionException(
                sprintf('求值步数超过上限 %d（eval_step_budget，FR-004/SC-009）', $this->stepBudget),
                $this->steps,
                DslExecutionException::KIND_STEP,
            );
        }

        if ($this->timeBudgetMs > 0 && (microtime(true) - $this->startedAt) * 1000 > $this->timeBudgetMs) {
            throw new DslExecutionException(
                sprintf('求值耗时超过上限 %dms（FR-004/SC-009）', $this->timeBudgetMs),
                $this->steps,
                DslExecutionException::KIND_TIME,
            );
        }
    }

    private function num(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function bollKey(?int $period, string $stdDev): string
    {
        return $period.'|'.$stdDev;
    }

    private static function resolveInt(string $key, int $default): int
    {
        try {
            $value = config('strategy.dsl.'.$key);
        } catch (\Throwable) {
            $value = null;
        }

        return is_numeric($value) ? (int) $value : $default;
    }
}
