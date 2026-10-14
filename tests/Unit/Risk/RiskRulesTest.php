<?php

declare(strict_types=1);

namespace Tests\Unit\Risk;

use App\Domain\Risk\Breaker\BreakerMetrics;
use App\Domain\Risk\Rules\ConsecutiveLossRule;
use App\Domain\Risk\Rules\MaxDailyLossRule;
use App\Domain\Risk\Rules\MaxDrawdownRule;
use App\Domain\Risk\Rules\MaxLossPerTradeRule;
use PHPUnit\Framework\TestCase;

/**
 * 4 维度风控 Rule 触发条件纯逻辑测试（poc:verify-risk 断言 1-4 的规则判定层）。
 *
 * default 组：Rule 只读 BreakerMetrics，不启动 Laravel、无 DB/Redis。
 * 阈值与命令 freshGuard() 一致：单笔 2% / 日内 5% / 回撤 10% / 连亏 5 次。
 */
class RiskRulesTest extends TestCase
{
    private const CAPITAL = '100';

    public function test_max_loss_per_trade_triggers_at_and_above_threshold(): void
    {
        $rule = new MaxLossPerTradeRule('2');

        $hit = new BreakerMetrics(peakEquity: self::CAPITAL);
        $hit->recordTradeWithDate('-2.5', self::CAPITAL, gmdate('Y-m-d'));
        static::assertSame('max_loss_per_trade', $rule->check($hit));

        // 边界：恰好 2% 也触发（≥）
        $boundary = new BreakerMetrics(peakEquity: self::CAPITAL);
        $boundary->recordTradeWithDate('-2', self::CAPITAL, gmdate('Y-m-d'));
        static::assertSame('max_loss_per_trade', $rule->check($boundary));

        $miss = new BreakerMetrics(peakEquity: self::CAPITAL);
        $miss->recordTradeWithDate('-1.5', self::CAPITAL, gmdate('Y-m-d'));
        static::assertNull($rule->check($miss));
    }

    public function test_max_daily_loss_triggers_after_four_small_losses(): void
    {
        $rule = new MaxDailyLossRule('5');

        // 4 笔各 -1.5 = 6%（单笔 1.5% 不触 max_loss_per_trade）
        $hit = new BreakerMetrics(peakEquity: self::CAPITAL);
        for ($i = 0; $i < 4; $i++) {
            $hit->recordTradeWithDate('-1.5', self::CAPITAL, gmdate('Y-m-d'));
        }
        static::assertSame('max_daily_loss', $rule->check($hit));

        // 3 笔 = 4.5% 不触发
        $miss = new BreakerMetrics(peakEquity: self::CAPITAL);
        for ($i = 0; $i < 3; $i++) {
            $miss->recordTradeWithDate('-1.5', self::CAPITAL, gmdate('Y-m-d'));
        }
        static::assertNull($rule->check($miss));
    }

    public function test_max_drawdown_triggers_after_peak_decline_but_not_on_fresh_metrics(): void
    {
        $rule = new MaxDrawdownRule('10');

        static::assertNull($rule->check(new BreakerMetrics(peakEquity: self::CAPITAL)));

        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $dayBefore = gmdate('Y-m-d', time() - 2 * 86400);
        $threeDaysAgo = gmdate('Y-m-d', time() - 3 * 86400);

        // 与断言 3 同序列：峰值 120 → 跨 3 天各亏 4.8% → 末笔小盈利，回撤 11.17% 仍触发
        $metrics = new BreakerMetrics(peakEquity: self::CAPITAL);
        $metrics->recordTradeWithDate('20', self::CAPITAL, $threeDaysAgo);
        $metrics->recordTradeWithDate('-4.8', self::CAPITAL, $dayBefore);
        $metrics->recordTradeWithDate('-4.8', self::CAPITAL, $yesterday);
        $metrics->recordTradeWithDate('-4.8', self::CAPITAL, $today);
        $metrics->recordTradeWithDate('1', self::CAPITAL, $today);

        static::assertSame('max_drawdown', $rule->check($metrics));
    }

    public function test_consecutive_losses_triggers_at_five_and_resets_with_gain(): void
    {
        $rule = new ConsecutiveLossRule(5);

        $metrics = new BreakerMetrics(peakEquity: self::CAPITAL);
        for ($i = 0; $i < 4; $i++) {
            $metrics->recordTradeWithDate('-0.9', self::CAPITAL, gmdate('Y-m-d'));
        }
        static::assertNull($rule->check($metrics));

        $metrics->recordTradeWithDate('-0.9', self::CAPITAL, gmdate('Y-m-d'));
        static::assertSame('consecutive_losses', $rule->check($metrics));

        // 盈利笔清零连亏
        $metrics->recordTradeWithDate('0.1', self::CAPITAL, gmdate('Y-m-d'));
        static::assertNull($rule->check($metrics));
    }
}
