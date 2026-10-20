<?php

declare(strict_types=1);

namespace App\Domain\Risk\Breaker;

use App\Domain\Risk\Contracts\LiquidationPriceCalculator;
use InvalidArgumentException;

/**
 * 逐仓模式强平价计算器。
 *
 * 公式（research.md §2.1）：
 *   多头强平价 = entryPrice × (1 - 1/leverage + maintMarginRate)
 *   空头强平价 = entryPrice × (1 + 1/leverage - maintMarginRate)
 *
 * 全部使用 bcmath 字符串运算，避免浮点误差。
 */
class IsolatedLiquidationPriceCalculator implements LiquidationPriceCalculator
{
    private const SCALE = 12;

    public function estimate(
        string $entryPrice,
        int $leverage,
        string $maintMarginRate,
        string $side,
    ): string {
        if ($leverage < 1) {
            throw new InvalidArgumentException("Leverage must be >= 1, got {$leverage}");
        }

        if (! in_array($side, ['long', 'short'], true)) {
            throw new InvalidArgumentException("Side must be 'long' or 'short', got {$side}");
        }

        // 1/leverage（bcmath 除法，scale 12）
        $invLeverage = bcdiv('1', (string) $leverage, self::SCALE);

        if ($side === 'long') {
            // (1 - 1/leverage + MMR)
            $factor = bcadd(bcsub('1', $invLeverage, self::SCALE), $maintMarginRate, self::SCALE);
        } else {
            // (1 + 1/leverage - MMR)
            $factor = bcsub(bcadd('1', $invLeverage, self::SCALE), $maintMarginRate, self::SCALE);
        }

        $liqPrice = bcmul($entryPrice, $factor, self::SCALE);

        return $this->trimZeros($liqPrice);
    }

    public function marginRatio(
        string $entryPrice,
        string $markPrice,
        string $maintMarginRate,
        int $leverage,
        string $side,
    ): string {
        if ($leverage < 1) {
            throw new InvalidArgumentException("Leverage must be >= 1, got {$leverage}");
        }

        if (! in_array($side, ['long', 'short'], true)) {
            throw new InvalidArgumentException("Side must be 'long' or 'short', got {$side}");
        }

        // 先算强平价
        $liqPrice = $this->estimate($entryPrice, $leverage, $maintMarginRate, $side);

        if ($side === 'long') {
            // (markPrice - liqPrice) / (entryPrice - liqPrice)
            $numerator = bcsub($markPrice, $liqPrice, self::SCALE);
            $denominator = bcsub($entryPrice, $liqPrice, self::SCALE);
        } else {
            // (liqPrice - markPrice) / (liqPrice - entryPrice)
            $numerator = bcsub($liqPrice, $markPrice, self::SCALE);
            $denominator = bcsub($liqPrice, $entryPrice, self::SCALE);
        }

        if (bccomp($denominator, '0', self::SCALE) === 0) {
            return '0';
        }

        $ratio = bcdiv($numerator, $denominator, self::SCALE);

        // 钳制到 [0, 1]：markPrice 越过强平价时为负，入场前可能 > 1
        if (bccomp($ratio, '0', self::SCALE) < 0) {
            return '0';
        }
        if (bccomp($ratio, '1', self::SCALE) > 0) {
            return '1';
        }

        return $this->trimZeros($ratio);
    }

    private function trimZeros(string $value): string
    {
        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
