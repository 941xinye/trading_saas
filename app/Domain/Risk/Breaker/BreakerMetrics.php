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
 * 字段值均为字符串保留精度，避免浮点误差。
 */
class BreakerMetrics
{
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
     * 记录一笔成交，更新 4 维度。
     *
     * @param string $realizedPnl     本笔已实现盈亏（带符号）
     * @param string $allocatedCapital 分配资金
     * @param string $currentEquity    当前净值
     */
    public function recordTrade(string $realizedPnl, string $allocatedCapital, string $currentEquity): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
    }

    /**
     * 计算当前回撤百分比（peak → current）。
     */
    public function computeDrawdown(): float
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P4 POC.');
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
}
