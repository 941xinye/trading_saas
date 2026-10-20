<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Services;

use App\Domain\Backtest\Dto\EquityPoint;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Strategy\Contracts\StrategyInterface;

/**
 * 净值曲线构建器（spec 008 T022 / contracts/backtest-runner-contract.md §3）。
 *
 * 净值 = initialCapital + realizedPnl + 未实现盈亏（bcmath 中间 12 位，落账 8 位）。
 * 持仓读取唯一入口 = `$strategy->getState()->positionSnapshot`（FR-003 推论：不读私有属性）。
 *
 * 持仓格式兼容：
 *   - `position`（DslStrategy / FuturesGridStrategy）：qty|size / direction|side / entry_price
 *   - `grids`（SpotGridStrategy）：多格加权平均成本
 */
final class EquityCurveBuilder
{
    /** @var EquityPoint[] */
    private array $points = [];

    public function __construct(
        private readonly string $initialCapital,
    ) {
    }

    public function mark(StrategyInterface $strategy, ExchangeKline $bar): EquityPoint
    {
        $state = $strategy->getState();
        $unrealized = $this->unrealizedPnl($state->positionSnapshot, $bar->close);

        $equity = bcadd(bcadd($this->initialCapital, $state->realizedPnl, 12), $unrealized, 12);
        $equity = bcadd($equity, '0', 8);

        $point = new EquityPoint($bar->openTime, $equity);
        $this->points[] = $point;

        return $point;
    }

    /** @return EquityPoint[] */
    public function points(): array
    {
        return $this->points;
    }

    public function reset(): void
    {
        $this->points = [];
    }

    private function unrealizedPnl(?array $snapshot, string $markPrice): string
    {
        if ($snapshot === null) {
            return '0';
        }

        // 通用 position 键（DslStrategy / FuturesGridStrategy）
        $position = $snapshot['position'] ?? null;
        if (is_array($position)) {
            return $this->pnlFromPosition($position, $markPrice);
        }

        // 现货网格：grids 加权平均成本
        $grids = $snapshot['grids'] ?? null;
        if (is_array($grids) && $grids !== []) {
            return $this->pnlFromGrids($grids, $markPrice);
        }

        return '0';
    }

    /**
     * @param array<string,mixed> $position
     */
    private function pnlFromPosition(array $position, string $markPrice): string
    {
        $qty = (string) ($position['qty'] ?? $position['size'] ?? '0');
        $entry = (string) ($position['entry_price'] ?? '0');
        $direction = $position['direction'] ?? $position['side'] ?? 'long';

        if (bccomp($qty, '0', 12) === 0 || bccomp($entry, '0', 12) === 0) {
            return '0';
        }

        $diff = bcsub($markPrice, $entry, 12);
        if ($direction === 'short') {
            $diff = bcmul($diff, '-1', 12);
        }

        return bcmul($qty, $diff, 12);
    }

    /**
     * @param array<string,array<string,string>> $grids
     */
    private function pnlFromGrids(array $grids, string $markPrice): string
    {
        $totalQty = '0';
        $totalCost = '0';

        foreach ($grids as $grid) {
            $qty = (string) ($grid['qty'] ?? '0');
            $cost = (string) ($grid['cost'] ?? '0');
            $totalQty = bcadd($totalQty, $qty, 12);
            $totalCost = bcadd($totalCost, bcmul($qty, $cost, 12), 12);
        }

        if (bccomp($totalQty, '0', 12) !== 1) {
            return '0';
        }

        $avgCost = bcdiv($totalCost, $totalQty, 12);

        return bcmul($totalQty, bcsub($markPrice, $avgCost, 12), 12);
    }
}
