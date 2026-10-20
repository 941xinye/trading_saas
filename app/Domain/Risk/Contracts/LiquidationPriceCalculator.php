<?php

declare(strict_types=1);

namespace App\Domain\Risk\Contracts;

/**
 * 合约强平价计算器（逐仓模式）。
 *
 * 平台侧保守估算：用最小档位维持保证金率（MMR），
 * 强平价会比交易所实际更接近当前价（更早预警）。
 * 交易所 positionRisk.liquidationPrice 为权威值。
 */
interface LiquidationPriceCalculator
{
    /**
     * 预估强平价。
     *
     * 多头：entryPrice × (1 - 1/leverage + maintMarginRate)
     * 空头：entryPrice × (1 + 1/leverage - maintMarginRate)
     *
     * @param  string  $entryPrice        开仓均价
     * @param  int     $leverage          杠杆倍数
     * @param  string  $maintMarginRate   维持保证金率（如 '0.0040'）
     * @param  string  $side              long|short
     * @return string                     强平价（bcmath 字符串）
     */
    public function estimate(
        string $entryPrice,
        int $leverage,
        string $maintMarginRate,
        string $side,
    ): string;

    /**
     * 计算当前保证金率（距强平价的剩余缓冲比例）。
     *
     * marginRatio = (markPrice - liqPrice) / (entryPrice - liqPrice)  [long]
     *             = (liqPrice - markPrice) / (liqPrice - entryPrice)  [short]
     *
     * 语义：入场时 = 1.0（满缓冲），触及强平价时 = 0（爆仓）。
     * 越小越危险，与三档阈值（warning 0.50 / action 0.20 / emergency 0.10）对齐。
     *
     * @param  string  $entryPrice        开仓均价
     * @param  string  $markPrice         当前标记价
     * @param  string  $maintMarginRate   维持保证金率
     * @param  int     $leverage          杠杆倍数
     * @param  string  $side              long|short
     * @return string 0~1，越小越危险
     */
    public function marginRatio(
        string $entryPrice,
        string $markPrice,
        string $maintMarginRate,
        int $leverage,
        string $side,
    ): string;
}
