<?php

declare(strict_types=1);

namespace Tests\Unit\Risk;

use App\Domain\Risk\Breaker\IsolatedLiquidationPriceCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 逐仓强平价计算器 + 保证金率纯函数测试（spec 005 WS3）。
 *
 * 无任何外部依赖（纯 bcmath），default 分组。
 *
 * 公式：
 *   多头强平价 = entryPrice × (1 - 1/leverage + MMR)
 *   空头强平价 = entryPrice × (1 + 1/leverage - MMR)
 *   保证金率   = 距强平价的剩余缓冲比例（入场=1.0，强平=0）
 */
class LiquidationPriceCalculatorTest extends TestCase
{
    private IsolatedLiquidationPriceCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new IsolatedLiquidationPriceCalculator();
    }

    // ------------------------------------------------------------------
    // estimate() — 多头
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{entryPrice:string, leverage:int, mmr:string, expected:string}>
     */
    public static function longEstimateProvider(): iterable
    {
        // BTCUSDT, MMR=0.0040；公式 entry × (1 - 1/lev + MMR)
        yield '1x long' => ['50000', 1, '0.0040', '200'];
        // 50000 × (1 - 1 + 0.004) = 50000 × 0.004 = 200
        yield '5x long' => ['50000', 5, '0.0040', '40200'];
        // 50000 × (1 - 0.2 + 0.004) = 50000 × 0.804 = 40200
        yield '10x long' => ['50000', 10, '0.0040', '45200'];
        // 50000 × (1 - 0.1 + 0.004) = 50000 × 0.904 = 45200
        yield '20x long' => ['50000', 20, '0.0040', '47700'];
        // 50000 × (1 - 0.05 + 0.004) = 50000 × 0.954 = 47700
    }

    #[DataProvider('longEstimateProvider')]
    public function test_estimate_long_at_various_leverages(
        string $entryPrice,
        int $leverage,
        string $mmr,
        string $expected,
    ): void {
        $actual = $this->calc->estimate($entryPrice, $leverage, $mmr, 'long');

        static::assertSame($expected, $actual);
    }

    public function test_estimate_long_max_leverage_125x(): void
    {
        // 50000 × (1 - 0.008 + 0.004) = 50000 × 0.996 = 49800
        $actual = $this->calc->estimate('50000', 125, '0.0040', 'long');

        static::assertSame('49800', $actual);
    }

    // ------------------------------------------------------------------
    // estimate() — 空头
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{entryPrice:string, leverage:int, mmr:string, expected:string}>
     */
    public static function shortEstimateProvider(): iterable
    {
        // 公式 entry × (1 + 1/lev - MMR)
        yield '1x short' => ['50000', 1, '0.0040', '99800'];
        // 50000 × (1 + 1 - 0.004) = 50000 × 1.996 = 99800
        yield '5x short' => ['50000', 5, '0.0040', '59800'];
        // 1 + 0.2 - 0.004 = 1.196; 50000 × 1.196 = 59800
        yield '10x short' => ['50000', 10, '0.0040', '54800'];
        // 1 + 0.1 - 0.004 = 1.096; 50000 × 1.096 = 54800
        yield '20x short' => ['50000', 20, '0.0040', '52300'];
        // 1 + 0.05 - 0.004 = 1.046; 50000 × 1.046 = 52300
    }

    #[DataProvider('shortEstimateProvider')]
    public function test_estimate_short_at_various_leverages(
        string $entryPrice,
        int $leverage,
        string $mmr,
        string $expected,
    ): void {
        $actual = $this->calc->estimate($entryPrice, $leverage, $mmr, 'short');

        static::assertSame($expected, $actual);
    }

    // ------------------------------------------------------------------
    // estimate() — MMR 影响
    // ------------------------------------------------------------------

    public function test_estimate_long_higher_mmr_pushes_liq_closer_to_entry(): void
    {
        // MMR 越高，强平价越接近开仓价（更早爆仓）
        $lowMmr = $this->calc->estimate('50000', 10, '0.0040', 'long');  // 45200
        $highMmr = $this->calc->estimate('50000', 10, '0.0250', 'long'); // 50000 × 0.925 = 46250

        static::assertSame('45200', $lowMmr);
        static::assertSame('46250', $highMmr);
        static::assertGreaterThan($lowMmr, $highMmr, 'MMR 越大强平价越高（越接近入场）');
    }

    public function test_estimate_short_higher_mmr_pushes_liq_closer_to_entry(): void
    {
        // 空头：MMR 越高，强平价越低（越接近入场）
        $lowMmr = $this->calc->estimate('50000', 10, '0.0040', 'short');  // 54800
        $highMmr = $this->calc->estimate('50000', 10, '0.0250', 'short'); // 50000 × 1.075 = 53750

        static::assertSame('54800', $lowMmr);
        static::assertSame('53750', $highMmr);
        static::assertLessThan($lowMmr, $highMmr, '空头 MMR 越大强平价越低（越接近入场）');
    }

    // ------------------------------------------------------------------
    // estimate() — 边界与异常
    // ------------------------------------------------------------------

    public function test_estimate_leverage_1_is_valid(): void
    {
        // leverage=1（无杠杆）：多头强平价 = entry × MMR（极低），空头 = entry × (2 - MMR)（极高）
        $long = $this->calc->estimate('100', 1, '0.0050', 'long');
        $short = $this->calc->estimate('100', 1, '0.0050', 'short');

        static::assertSame('0.5', $long);
        static::assertSame('199.5', $short);
    }

    public function test_estimate_throws_on_zero_leverage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc->estimate('50000', 0, '0.0040', 'long');
    }

    public function test_estimate_throws_on_negative_leverage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc->estimate('50000', -1, '0.0040', 'long');
    }

    public function test_estimate_throws_on_invalid_side(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc->estimate('50000', 10, '0.0040', 'bogus');
    }

    // ------------------------------------------------------------------
    // marginRatio() — 多头
    // ------------------------------------------------------------------

    public function test_margin_ratio_long_at_entry_equals_one(): void
    {
        // 入场时缓冲 = 100%
        $ratio = $this->calc->marginRatio('50000', '50000', '0.0040', 10, 'long');

        static::assertSame('1', $ratio);
    }

    public function test_margin_ratio_long_at_liquidation_equals_zero(): void
    {
        // 强平价 45200
        $ratio = $this->calc->marginRatio('50000', '45200', '0.0040', 10, 'long');

        static::assertSame('0', $ratio);
    }

    public function test_margin_ratio_long_halfway_is_half(): void
    {
        // 入场 50000，强平 45200，中点 47600 → 缓冲 50%
        $ratio = $this->calc->marginRatio('50000', '47600', '0.0040', 10, 'long');

        static::assertSame('0.5', $ratio);
    }

    public function test_margin_ratio_long_past_liquidation_clamped_to_zero(): void
    {
        // 价格跌破强平价 → 钳制为 0
        $ratio = $this->calc->marginRatio('50000', '40000', '0.0040', 10, 'long');

        static::assertSame('0', $ratio);
    }

    public function test_margin_ratio_long_above_entry_clamped_to_one(): void
    {
        // 价格高于入场 → 缓冲 > 100%，钳制为 1
        $ratio = $this->calc->marginRatio('50000', '60000', '0.0040', 10, 'long');

        static::assertSame('1', $ratio);
    }

    // ------------------------------------------------------------------
    // marginRatio() — 空头
    // ------------------------------------------------------------------

    public function test_margin_ratio_short_at_entry_equals_one(): void
    {
        $ratio = $this->calc->marginRatio('50000', '50000', '0.0040', 10, 'short');

        static::assertSame('1', $ratio);
    }

    public function test_margin_ratio_short_at_liquidation_equals_zero(): void
    {
        // 空头强平价 54800
        $ratio = $this->calc->marginRatio('50000', '54800', '0.0040', 10, 'short');

        static::assertSame('0', $ratio);
    }

    public function test_margin_ratio_short_halfway_is_half(): void
    {
        // 入场 50000，强平 54800，中点 52400 → 缓冲 50%
        $ratio = $this->calc->marginRatio('50000', '52400', '0.0040', 10, 'short');

        static::assertSame('0.5', $ratio);
    }

    public function test_margin_ratio_short_past_liquidation_clamped_to_zero(): void
    {
        $ratio = $this->calc->marginRatio('50000', '60000', '0.0040', 10, 'short');

        static::assertSame('0', $ratio);
    }

    public function test_margin_ratio_short_below_entry_clamped_to_one(): void
    {
        // 空头价格下跌（盈利）→ 缓冲 > 100%，钳制为 1
        $ratio = $this->calc->marginRatio('50000', '40000', '0.0040', 10, 'short');

        static::assertSame('1', $ratio);
    }

    // ------------------------------------------------------------------
    // marginRatio() — 不同杠杆下的阈值对齐
    // ------------------------------------------------------------------

    public function test_margin_ratio_matches_warning_threshold_for_10x_long(): void
    {
        // warning=0.50 → markPrice 恰好距强平剩 50% 缓冲
        // entry=50000, liq=45200, 50% 缓冲 → markPrice = 45200 + 0.5×(50000-45200) = 47600
        $ratio = $this->calc->marginRatio('50000', '47600', '0.0040', 10, 'long');

        static::assertSame('0.5', $ratio);
    }

    public function test_margin_ratio_matches_action_threshold_for_10x_long(): void
    {
        // action=0.20 → markPrice = 45200 + 0.2×4800 = 46160
        $ratio = $this->calc->marginRatio('50000', '46160', '0.0040', 10, 'long');

        static::assertSame('0.2', $ratio);
    }

    public function test_margin_ratio_matches_emergency_threshold_for_10x_long(): void
    {
        // emergency=0.10 → markPrice = 45200 + 0.1×4800 = 45680
        $ratio = $this->calc->marginRatio('50000', '45680', '0.0040', 10, 'long');

        static::assertSame('0.1', $ratio);
    }

    // ------------------------------------------------------------------
    // marginRatio() — 异常
    // ------------------------------------------------------------------

    public function test_margin_ratio_throws_on_invalid_leverage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc->marginRatio('50000', '50000', '0.0040', 0, 'long');
    }

    public function test_margin_ratio_throws_on_invalid_side(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc->marginRatio('50000', '50000', '0.0040', 10, 'bogus');
    }
}
