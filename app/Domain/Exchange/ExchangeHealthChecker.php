<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * 交易所健康检查器。
 *
 * 复用 AI-API 项目的 HealthChecker 思路：
 *  - 每次调用 record() 写入 exchange_health_checks 表（best-effort，失败不影响主流程）
 *  - isHealthy() 基于 Redis 内的近期失败计数（滑动窗口内连续失败 ≥ 阈值 → 不健康）
 *
 * Redis 结构：
 *  - market:health:{exchange}:failures   ZSet，score=时间戳，member=失败事件唯一 ID
 *  - 近 5 分钟内失败次数 >= 3 → unhealthy
 *
 * 不使用 static 缓存（Octane 长驻）。
 */
class ExchangeHealthChecker
{
    /** 失败事件滑动窗口（秒） */
    private const FAILURE_WINDOW_SECONDS = 300;

    /** 窗口内失败次数阈值 */
    private const FAILURE_THRESHOLD = 3;

    private const KEY_PREFIX = 'market:health';

    public function __construct()
    {
    }

    /**
     * 记录一次调用结果。
     *
     * @param string      $exchange  交易所代码
     * @param string      $endpoint  端点
     * @param int         $latencyMs 延迟毫秒
     * @param string      $status    ok|degraded|down
     * @param string|null $error     错误信息（成功时为 null）
     */
    public function record(string $exchange, string $endpoint, int $latencyMs, string $status, ?string $error = null): void
    {
        // 状态映射：ok → 表枚举 up
        $dbStatus = $status === 'ok' ? 'up' : $status;

        // DB 落库 best-effort：协程/队列场景下 DB 抖动不应拖垮行情链路
        try {
            DB::table('exchange_health_checks')->insert([
                'exchange_id' => $this->exchangeId($exchange),
                'endpoint' => mb_substr($endpoint, 0, 60),
                'latency_ms' => $latencyMs,
                'status' => $dbStatus,
                'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
                'checked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::debug('Health check DB record skipped', ['error' => $e->getMessage()]);
        }

        if ($status === 'down') {
            $this->markFailure($exchange);
        }
    }

    /**
     * 交易所是否健康（最近窗口失败次数低于阈值）。
     */
    public function isHealthy(string $exchange): bool
    {
        $redis = Redis::connection('default');
        $key = sprintf('%s:%s:failures', self::KEY_PREFIX, $exchange);

        $count = (int) $redis->zCard($key);

        return $count < self::FAILURE_THRESHOLD;
    }

    private function markFailure(string $exchange): void
    {
        $redis = Redis::connection('default');
        $key = sprintf('%s:%s:failures', self::KEY_PREFIX, $exchange);
        $now = time();

        try {
            $redis->zAdd($key, $now, uniqid('f_', true));
            $redis->zRemRangeByScore($key, '-inf', (string) ($now - self::FAILURE_WINDOW_SECONDS));
            $redis->expire($key, self::FAILURE_WINDOW_SECONDS);
        } catch (Throwable $e) {
            Log::debug('Health failure mark skipped', ['error' => $e->getMessage()]);
        }
    }

    private function exchangeId(string $exchange): int
    {
        return (int) (DB::table('exchanges')->where('code', $exchange)->value('id') ?? 0);
    }
}
