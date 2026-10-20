<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Services;

use App\Domain\Backtest\Contracts\SimulatedMatcher;
use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Dto\SimulatedTrade;
use App\Domain\Exchange\Contracts\ExchangeKline;

/**
 * 撮合引擎（spec 008 T021 / contracts/backtest-runner-contract.md §2）。
 *
 * 成交价：
 *   buy  = close × (1 + bps/10000)
 *   sell = close × (1 − bps/10000)
 * 手续费 = fillPrice × qty × feeRate（bcmath 中间 12 位，落账 8 位）。
 *
 * 不负责持仓盈亏计算——realizedPnl 由 Runner 在 onFill 后根据策略状态回填。
 */
final class MatchingEngine implements SimulatedMatcher
{
    public function fill(array $decision, ExchangeKline $bar, MatchingAssumptions $assumptions): SimulatedTrade
    {
        $side = (string) ($decision[0] ?? $decision['side'] ?? '');
        $qty  = (string) ($decision[1] ?? $decision['qty'] ?? '0');

        if (bccomp($qty, '0', 12) <= 0) {
            throw new \InvalidArgumentException(
                sprintf('模拟成交数量必须 > 0（收到 side=%s qty=%s）', $side, $qty)
            );
        }

        $slippageFactor = bcdiv((string) $assumptions->slippageBps, '10000', 12);

        $multiplier = match ($side) {
            'buy'  => bcadd('1', $slippageFactor, 12),
            'sell' => bcsub('1', $slippageFactor, 12),
            default => throw new \InvalidArgumentException("不支持的成交方向: {$side}"),
        };

        $fillPrice = bcmul($bar->close, $multiplier, 12);
        $fillPrice = $this->scale($fillPrice, 8);

        $fee = bcmul(bcmul($fillPrice, $qty, 12), $assumptions->feeRate, 12);
        $fee = $this->scale($fee, 8);

        return new SimulatedTrade(
            seq: 0,
            barOpenTime: $bar->openTime,
            side: $side,
            qty: $this->scale($qty, 8),
            price: $fillPrice,
            fee: $fee,
            realizedPnl: '0',
        );
    }

    /**
     * 截断到指定小数位（bcmath 舍入模式 = 截断）。
     */
    private function scale(string $value, int $scale): string
    {
        return bcadd($value, '0', $scale);
    }
}
