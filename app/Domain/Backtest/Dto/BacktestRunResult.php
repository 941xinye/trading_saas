<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Dto;

/**
 * 回测运行结果（不可变值对象）。
 *
 * Runner 纯内存产出，持久化由调用方（RunBacktestJob / --sync 命令）负责。
 */
final readonly class BacktestRunResult
{
    public function __construct(
        /** 实际回放 bar 数 */
        public int $barCount,
        /** @var SimulatedTrade[] */
        public array $trades,
        /** @var EquityPoint[] */
        public array $equityPoints,
        /** 数据覆盖率 %（实际 bar 数/区间理论 bar 数 ×100） */
        public string $coveragePct,
    ) {
    }
}
