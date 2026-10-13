<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 交易所请求限流器（Redis 固定窗口计数）。
 *
 * 按 (exchange, endpoint) 维度记录 1 分钟内已用 weight，触达阈值后拒绝。
 * Binance 按 weight 制（默认 1200/分钟）；
 * OKX/Bybit/Bitget 按 RPM 制。
 *
 * 实现选择：固定窗口（INCRBY + EXPIRE）而非滑动窗口。
 * Binance 官方限流本身就是 1 分钟固定窗口，语义对齐；
 * Lua 脚本保证 INCRBY+EXPIRE 原子性（跨协程/跨进程安全）。
 *
 * 阈值：触达 80% 告警 + 暂停低优任务，触达 100% 拒绝走缓存兜底。
 *
 * 不使用 static 缓存（Octane 长驻 worker 下配置永不生效 + 测试隔离污染）。
 */
class RateLimiter
{
    /** 1 分钟窗口 */
    private const WINDOW_SECONDS = 60;

    /** 窗口 key 过期时间 = 2 倍窗口（避免残留） */
    private const KEY_TTL_SECONDS = 120;

    private const KEY_PREFIX = 'market:ratelimit';

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
        $limit = $this->limitFor($exchange);
        if ($limit <= 0) {
            return true; // 未配置限流的交易所直接放行
        }

        $key = $this->key($exchange, $endpoint);
        $used = $this->increment($key, $weight);

        // 窗口首个请求：设置 TTL
        if ($used === $weight) {
            Redis::connection('default')->expire($key, self::KEY_TTL_SECONDS);
        }

        // 触达 80% → 告警（每次窗口只告警一次，用 Redis 标记去重）
        if ($used >= (int) ceil($limit * 0.8) && $used - $weight < (int) ceil($limit * 0.8)) {
            Log::warning('Exchange rate limit approaching 80%', [
                'exchange' => $exchange,
                'endpoint' => $endpoint,
                'used' => $used,
                'limit' => $limit,
            ]);
        }

        return $used <= $limit;
    }

    /**
     * 当前窗口已用 weight（只读，监控用）。
     */
    public function used(string $exchange, string $endpoint): int
    {
        return (int) Redis::connection('default')->get($this->key($exchange, $endpoint));
    }

    private function increment(string $key, int $weight): int
    {
        $script = <<<'LUA'
local used = redis.call('INCRBY', KEYS[1], ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[2])
return used
LUA;

        return (int) Redis::connection('default')->eval(
            $script,
            1,
            $key,
            $weight,
            self::KEY_TTL_SECONDS,
        );
    }

    private function key(string $exchange, string $endpoint): string
    {
        $bucket = (string) intdiv(time(), self::WINDOW_SECONDS);

        return sprintf('%s:%s:%s:%s', self::KEY_PREFIX, $exchange, sha1($endpoint), $bucket);
    }

    private function limitFor(string $exchange): int
    {
        return (int) config("exchange.exchanges.{$exchange}.weight_limit_per_minute", 0);
    }
}
