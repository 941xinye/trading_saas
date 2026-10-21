<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Dto;

use App\Domain\Backtest\Optimization\ParameterGrid;

/**
 * 参数寻优请求（不可变值对象）。
 *
 * grid 为参数网格定义（显式值列表或 min/max/step），展开与白名单校验由 ParameterGrid 负责。
 * baseParams 为不参与寻优的固定参数，与每个组合合并后传入回测。
 */
final readonly class OptimizationRequest
{
    public const MODES = ['grid', 'walk_forward'];

    public string $symbol;

    public string $interval;

    public string $mode;

    public function __construct(
        public int $tenantId,
        public string $strategyCode,
        string $symbol,
        string $interval,
        public \DateTimeImmutable $fromUtc,
        public \DateTimeImmutable $toUtc,
        public array $grid,
        string $mode = 'grid',
        public int $folds = 3,
        public string $objective = 'total_return_pct',
        public MatchingAssumptions $assumptions = new MatchingAssumptions('0.001', 5, '1000'),
        public array $baseParams = [],
        public int $topN = 5,
    ) {
        if ($this->tenantId <= 0) {
            throw new \InvalidArgumentException('tenant_id 必须 > 0');
        }

        if (! in_array($this->strategyCode, (array) config('backtest.strategies', []), true)) {
            throw new \InvalidArgumentException("策略 {$this->strategyCode} 不在回测白名单");
        }

        // 网格构造即校验（键白名单 / 轴合法性 / 组合上限）：非法请求直接拒绝，
        // 不落库成 failed 任务（API 422 / UI 通知 / 命令 INVALID）。
        ParameterGrid::expand($this->grid, $this->strategyCode);

        $this->symbol = strtoupper(trim($symbol));
        if ($this->symbol === '') {
            throw new \InvalidArgumentException('symbol 不能为空');
        }

        $this->interval = strtolower(trim($interval));
        if (! in_array($this->interval, BacktestRunRequest::INTERVALS, true)) {
            throw new \InvalidArgumentException(
                sprintf('interval 须为 %s 之一', implode('/', BacktestRunRequest::INTERVALS))
            );
        }

        if ($this->fromUtc >= $this->toUtc) {
            throw new \InvalidArgumentException('寻优区间必须满足 from_utc < to_utc');
        }

        $this->mode = strtolower(trim($mode));
        if (! in_array($this->mode, self::MODES, true)) {
            throw new \InvalidArgumentException(sprintf('mode 须为 %s 之一', implode('/', self::MODES)));
        }

        if ($this->folds < 2 || $this->folds > 52) {
            throw new \InvalidArgumentException('folds 须在 [2, 52] 区间');
        }

        if (! array_key_exists($this->objective, (array) config('optimization.objectives', []))) {
            throw new \InvalidArgumentException("objective {$this->objective} 不在可寻优目标内");
        }

        if ($this->topN <= 0) {
            throw new \InvalidArgumentException('topN 必须 > 0');
        }
    }
}
