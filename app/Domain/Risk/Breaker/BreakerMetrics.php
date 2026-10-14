<?php

declare(strict_types=1);

namespace App\Domain\Risk\Breaker;

/**
 * 熔断 4 维度指标快照。
 *
 * 在 RiskGuard.check 之前由 recordTrade 累计写入，
 * 4 个 Rule 读取本对象做判断。
 *
 * 4 维度（架构 §6）：
 *  - 单笔最大亏损：单笔成交已实现亏损 ≥ 阈值（2% of allocated_capital）
 *  - 日内最大亏损：当日累计已实现亏损 ≥ 阈值（5%）
 *  - 最大回撤：历史峰值 → 当前净值回撤 ≥ 阈值（10%）
 *  - 连续亏损次数：连续亏损订单数 ≥ 阈值（5 次）
 *
 * 字段值均为字符串保留精度（bcmath），避免浮点误差。
 * 百分比统一以 allocated_capital 为分母。
 */
class BreakerMetrics
{
    /** 当日累计已实现亏损（正数 = 亏损额） */
    private string $dailyLossAbs = '0';

    /** 累计已实现盈亏（带符号），驱动净值 */
    private string $cumPnl = '0';

    /** 最近一笔成交的 UTC 日期（Y-m-d），跨日检测用；崩溃恢复后由外部注入 */
    private string $lastTradeDate = '';

    public function __construct(
        private string $lastTradeLossPct = '0',
        private string $dailyLossPct = '0',
        private string $currentDrawdownPct = '0',
        private int $consecutiveLosses = 0,
        private string $peakEquity = '0',
        private string $currentEquity = '0',
    ) {
    }

    /**
     * 记录一笔成交，更新 4 维度（委托 recordTradeWithDate，utcDate=今日 UTC）。
     *
     * @param string $realizedPnl      本笔已实现盈亏（带符号，quote 计价）
     * @param string $allocatedCapital 分配资金
     * @param string $currentEquity    当前净值（调用方可传 null 由内部累计）
     */
    public function recordTrade(string $realizedPnl, string $allocatedCapital, ?string $currentEquity = null): void
    {
        $this->recordTradeWithDate($realizedPnl, $allocatedCapital, gmdate('Y-m-d'), $currentEquity);
    }

    /**
     * 记录一笔成交并做跨日检测。
     *
     * 比对 utcDate 与 lastTradeDate：不同则 resetDaily() + 更新 lastTradeDate，
     * 返回 dayChanged=true。RiskGuard 据此同步 breaker Redis Hash 的 last_trade_date。
     *
     * @return array{pnl:string, dayChanged:bool}
     */
    public function recordTradeWithDate(string $realizedPnl, string $allocatedCapital, string $utcDate, ?string $currentEquity = null): array
    {
        $dayChanged = false;
        if ($this->lastTradeDate !== '' && $utcDate !== $this->lastTradeDate) {
            $this->resetDaily();
            $dayChanged = true;
        }
        $this->lastTradeDate = $utcDate;

        $this->cumPnl = bcadd($this->cumPnl, $realizedPnl, 12);

        // 净值：优先外部传入（合约场景含浮盈），P1 现货用 allocated + 累计已实现
        $equity = $currentEquity ?? bcadd($allocatedCapital, $this->cumPnl, 12);
        $this->currentEquity = $equity;

        // 峰值与回撤
        if (bccomp($this->peakEquity, '0', 12) === 0 || bccomp($equity, $this->peakEquity, 12) === 1) {
            $this->peakEquity = $equity;
        }
        $this->currentDrawdownPct = $this->drawdownPct($this->peakEquity, $equity);

        if (bccomp($realizedPnl, '0', 12) === -1) {
            // 亏损笔：单笔亏损占比 + 日内累计 + 连亏计数
            $loss = bcmul($realizedPnl, '-1', 12);
            $this->lastTradeLossPct = $this->pctOf($loss, $allocatedCapital);
            $this->dailyLossAbs = bcadd($this->dailyLossAbs, $loss, 12);
            $this->dailyLossPct = $this->pctOf($this->dailyLossAbs, $allocatedCapital);
            $this->consecutiveLosses++;
        } else {
            // 盈利/持平笔：重置单笔与连亏，日内亏损只减不增
            $this->lastTradeLossPct = '0';
            $this->consecutiveLosses = 0;
            if (bccomp($realizedPnl, '0', 12) === 1 && bccomp($this->dailyLossAbs, '0', 12) === 1) {
                $gain = min($realizedPnl, $this->dailyLossAbs);
                $this->dailyLossAbs = bcsub($this->dailyLossAbs, $gain, 12);
                $this->dailyLossPct = $this->pctOf($this->dailyLossAbs, $allocatedCapital);
            }
        }

        return ['pnl' => $realizedPnl, 'dayChanged' => $dayChanged];
    }

    /**
     * 注入持久化的 lastTradeDate（崩溃恢复后由 RiskGuard 从 Redis Hash 加载）。
     * 必须在 recordTrade/recordTradeWithDate 之前调用，否则首笔会被误判跨日。
     */
    public function setLastTradeDate(string $utcDate): void
    {
        $this->lastTradeDate = $utcDate;
    }

    public function getLastTradeDate(): string
    {
        return $this->lastTradeDate;
    }

    /**
     * 计算当前回撤百分比（peak → current），浮点用于展示。
     */
    public function computeDrawdown(): float
    {
        return (float) $this->drawdownPct($this->peakEquity, $this->currentEquity);
    }

    /**
     * 重置日内累计（跨日调用）。
     */
    public function resetDaily(): void
    {
        $this->dailyLossAbs = '0';
        $this->dailyLossPct = '0';
    }

    public function getLastTradeLossPct(): string
    {
        return $this->lastTradeLossPct;
    }

    public function getDailyLossPct(): string
    {
        return $this->dailyLossPct;
    }

    public function getCurrentDrawdownPct(): string
    {
        return $this->currentDrawdownPct;
    }

    public function getConsecutiveLosses(): int
    {
        return $this->consecutiveLosses;
    }

    public function getPeakEquity(): string
    {
        return $this->peakEquity;
    }

    public function getCurrentEquity(): string
    {
        return $this->currentEquity;
    }

    private function pctOf(string $part, string $base): string
    {
        if (bccomp($base, '0', 12) !== 1) {
            return '0';
        }

        return bcmul(bcdiv($part, $base, 12), '100', 8);
    }

    private function drawdownPct(string $peak, string $current): string
    {
        if (bccomp($peak, '0', 12) !== 1 || bccomp($peak, $current, 12) !== 1) {
            return '0';
        }

        return $this->pctOf(bcsub($peak, $current, 12), $peak);
    }
}
