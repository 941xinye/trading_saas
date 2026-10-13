<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

/**
 * 交易所请求限流器（Redis 令牌桶）。
 *
 * 按 (exchange, endpoint) 维度记录 1 分钟内已用 weight，触达阈值后拒绝。
 * Binance 按 weight 制（默认 1200/分钟）；
 * OKX/Bybit/Bitget 按 RPM 制。
 *
 * 阈值：触达 80% 告警 + 暂停低优任务，触达 100% 拒绝走缓存兜底。
 *
 * 不使用 static 缓存（Octane 长驻 worker 下配置永不生效 + 测试隔离污染）。
 */
class RateLimiter
{
    public function __construct()
    {
    }

    /**
     * 尝试获取配额。
     *
     * @param string $exchange 交易所代码 binance|okx|bybit|bitget
     * @param string $endpoint 端点标识，如 "GET /api/v3/ticker"
     * @param int    $weight   本次请求占用权重，默认 1
     *
     * @return bool true=放行，false=触达 100% 限流
     */
    public function tryAcquire(string $exchange, string $endpoint, int $weight = 1): bool
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }
}
