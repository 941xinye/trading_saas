<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

/**
 * 交易所健康检查器。
 *
 * 复用 AI-API 项目的 HealthChecker 思路：
 *  - 每次调用 record() 写入 exchange_health_checks 表
 *  - isHealthy() 聚合最近 N 次记录的 P50 延迟 + 失败率
 *  - 触发降级后通知上层走缓存兜底（Dashboard 行情刷新等低优任务可暂停）
 *
 * 不使用 static 缓存（Octane 长驻）。
 */
class ExchangeHealthChecker
{
    public function __construct()
    {
    }

    /**
     * 记录一次调用结果。
     *
     * @param string      $exchange   交易所代码
     * @param string      $endpoint   端点
     * @param int         $latencyMs  延迟毫秒
     * @param string      $status     ok|degraded|down
     * @param string|null $error      错误信息（成功时为 null）
     */
    public function record(string $exchange, string $endpoint, int $latencyMs, string $status, ?string $error = null): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    /**
     * 交易所是否健康（最近窗口成功率/延迟符合阈值）。
     */
    public function isHealthy(string $exchange): bool
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }
}
