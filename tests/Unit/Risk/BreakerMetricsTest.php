<?php

declare(strict_types=1);

namespace Tests\Unit\Risk;

use App\Domain\Risk\Breaker\BreakerMetrics;
use PHPUnit\Framework\TestCase;

/**
 * BreakerMetrics 4 维度指标纯逻辑测试（迁移自 poc:verify-risk 断言 9 及断言 1-4 的指标层）。
 *
 * default 组：不启动 Laravel、无 DB/Redis/网络；金额全部 bcmath 字符串，数值断言用 bccomp。
 * 成交日期显式传入 UTC Y-m-d，与命令中 recordTradeWithDate 的用法一致。
 */
class BreakerMetricsTest extends TestCase
{
    private const CAPITAL = '100';

    public function test_cross_day_reset_clears_yesterday_loss_and_flags_day_changed(): void
    {
        // 断言 9a：昨日亏损后 dailyLossPct=2.5
        $metrics = new BreakerMetrics(peakEquity: self::CAPITAL);
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $today = gmdate('Y-m-d');

        $metrics->recordTradeWithDate('-2.5', self::CAPITAL, $yesterday);
        static::assertSame(0, bccomp($metrics->getDailyLossPct(), '2.5', 8));

        // 断言 9b：今日首笔 → dayChanged=true（内部触发 resetDaily）
        $result = $metrics->recordTradeWithDate('-3', self::CAPITAL, $today);
        static::assertTrue($result['dayChanged']);

        // 断言 9c：跨日后日内亏损只计今日 3%，不累加昨日
        static::assertSame(0, bccomp($metrics->getDailyLossPct(), '3', 8));
        static::assertSame($today, $metrics->getLastTradeDate());
    }

    public function test_single_loss_pct_is_relative_to_allocated_capital(): void
    {
        // 断言 1 的指标层：单笔 -2.5 / 100 = 2.5%（max_loss_per_trade 阈值 2%）
        $metrics = new BreakerMetrics(peakEquity: self::CAPITAL);

        $metrics->recordTradeWithDate('-2.5', self::CAPITAL, gmdate('Y-m-d'));

        static::assertSame(0, bccomp($metrics->getLastTradeLossPct(), '2.5', 8));
        static::assertSame(1, $metrics->getConsecutiveLosses());
    }

    public function test_daily_loss_accumulates_same_day_but_stays_below_per_trade_threshold(): void
    {
        // 断言 2 的指标层：4 笔各 -1.5 → 日内 -6%，单笔始终 1.5% < 2%
        $metrics = new BreakerMetrics(peakEquity: self::CAPITAL);

        for ($i = 0; $i < 4; $i++) {
            $metrics->recordTradeWithDate('-1.5', self::CAPITAL, gmdate('Y-m-d'));
        }

        static::assertSame(0, bccomp($metrics->getLastTradeLossPct(), '1.5', 8));
        static::assertSame(0, bccomp($metrics->getDailyLossPct(), '6', 8));
    }

    public function test_drawdown_tracks_peak_equity_across_three_loss_days(): void
    {
        // 断言 3 的指标层：峰值 120 → 3 天各亏 4.8% → 末笔小盈利，回撤仍 11.17% ≥ 10%
        $metrics = new BreakerMetrics(peakEquity: self::CAPITAL);
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $dayBefore = gmdate('Y-m-d', time() - 2 * 86400);
        $threeDaysAgo = gmdate('Y-m-d', time() - 3 * 86400);

        $metrics->recordTradeWithDate('20', self::CAPITAL, $threeDaysAgo);
        $metrics->recordTradeWithDate('-4.8', self::CAPITAL, $dayBefore);
        $metrics->recordTradeWithDate('-4.8', self::CAPITAL, $yesterday);
        $metrics->recordTradeWithDate('-4.8', self::CAPITAL, $today);
        $metrics->recordTradeWithDate('1', self::CAPITAL, $today);

        static::assertSame(0, bccomp($metrics->getPeakEquity(), '120', 8));
        static::assertSame(0, bccomp($metrics->getCurrentEquity(), '106.6', 8));
        static::assertSame(1, bccomp($metrics->getCurrentDrawdownPct(), '10', 4));
    }

    public function test_consecutive_losses_counts_five_then_resets_on_gain(): void
    {
        // 断言 4 的指标层：5 笔各 -0.9 → 连亏 5；再来一笔盈利清零
        $metrics = new BreakerMetrics(peakEquity: self::CAPITAL);

        for ($i = 0; $i < 5; $i++) {
            $metrics->recordTradeWithDate('-0.9', self::CAPITAL, gmdate('Y-m-d'));
        }

        static::assertSame(5, $metrics->getConsecutiveLosses());
        static::assertSame(0, bccomp($metrics->getDailyLossPct(), '4.5', 8));

        $metrics->recordTradeWithDate('0.1', self::CAPITAL, gmdate('Y-m-d'));
        static::assertSame(0, $metrics->getConsecutiveLosses());
        static::assertSame(0, bccomp($metrics->getLastTradeLossPct(), '0', 8));
    }

    public function test_same_day_trade_does_not_flag_day_changed(): void
    {
        $metrics = new BreakerMetrics(peakEquity: self::CAPITAL);
        $today = gmdate('Y-m-d');

        $metrics->recordTradeWithDate('-1', self::CAPITAL, $today);
        $result = $metrics->recordTradeWithDate('-1', self::CAPITAL, $today);

        static::assertFalse($result['dayChanged']);
        static::assertSame(0, bccomp($metrics->getDailyLossPct(), '2', 8));
    }
}
