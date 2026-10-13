<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * K 线聚合命令：从 Redis 未闭 bar + tick 重建 / 补齐历史 K 线。
 *
 * 用途：
 *  - 进程 B 异常重启后，从 Redis Sorted Set 增量重建缺失 bar（避免丢 bar）
 *  - 历史数据补齐（调交易所 REST 拉取并写入 kline_bars，平台级共享数据）
 *  - 多周期聚合（1m → 5m/15m/1h 衍生，不重复请求交易所）
 *
 * 与 CloseBarJob 区别：Job 是实时 bar close 触发；本命令是离线补齐 / 周期衍生。
 *
 * POC 阶段：handle 仅占位，实际聚合逻辑待 P1 POC 实现。
 */
class AggregateKlineCommand extends Command
{
    /**
     * 命令签名。
     * --interval: 目标周期，默认 1m
     * --symbol: 交易对，默认 BTCUSDT
     */
    protected $signature = 'kline:aggregate {--interval=1m} {--symbol=BTCUSDT}';

    /**
     * 命令描述。
     */
    protected $description = 'Aggregate or backfill kline bars from Redis tick store / REST fallback';

    public function handle(): int
    {
        $interval = (string) $this->option('interval');
        $symbol = (string) $this->option('symbol');

        $this->info("Skeleton: kline:aggregate --symbol={$symbol} --interval={$interval} not implemented. Wait for P1 POC.");

        return self::SUCCESS;
    }
}
