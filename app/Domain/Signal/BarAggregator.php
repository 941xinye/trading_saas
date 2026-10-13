<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;

/**
 * K 线聚合器。
 *
 * 按 1m/5m/15m/1h/4h/1d 周期聚合 tick 或低周期 bar 为高周期 bar。
 * 进程 B 内单例，每个 (exchange, symbol, interval) 维护一个未闭 bar 状态。
 *
 * 关闭逻辑：
 *  - 当新 tick/bar 的 openTime 跨越当前周期 closeTime → 当前 bar close
 *  - 返回已闭 ExchangeKline；未达关闭条件返回 null
 *
 * 状态存储：Redis Hash（未闭 bar）+ Sorted Set（最近 bar 缓存）
 */
class BarAggregator
{
    public function __construct()
    {
    }

    /**
     * 聚合 tick/bar 到指定周期。
     *
     * @param ExchangeKline $tick     输入的最新 1m bar 或 tick 聚合体
     * @param string       $interval 目标周期 1m|5m|15m|1h|4h|1d
     *
     * @return ExchangeKline|null bar close 时返回新 bar，否则 null（仍处于当前周期内）
     */
    public function aggregate(ExchangeKline $tick, string $interval): ?ExchangeKline
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P2 POC.');
    }
}
