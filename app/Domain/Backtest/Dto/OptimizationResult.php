<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Dto;

/**
 * 参数寻优结果（不可变值对象）。
 *
 * top 为按 objective 排序的参数组摘要；walk_forward 模式下 score 为各折样本外得分的均值。
 */
final readonly class OptimizationResult
{
    public function __construct(
        public string $mode,
        public string $objective,
        public int $combinationCount,
        public int $trialCount,
        public int $failedTrialCount,
        public ?array $bestParams,
        public ?string $bestScore,
        /** @var list<array<string, mixed>> */
        public array $top,
        /** @var array<string, mixed> */
        public array $report,
    ) {}
}
