<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * AI 信号分析 Job（ai 队列）。
 *
 * 触发时机：CloseBarJob 在 K 线 bar close 后，按订阅（symbol+interval 维度）
 * 投递 AiAnalyzeJob 到 ai 队列，每个订阅一份（一 bar 多订阅时各自独立重试）。
 *
 * 执行链路（POC 待实现）：
 *  1. 恢复 TenantContext(tenantId)
 *  2. 加载 signal_subscriptions + bar 数据 → 调 AIPrompter.analyze()
 *  3. LLM 限流 / prompt 截断 / 超时 → 自动降级到 RuleEngineFallback
 *     （基于 MA/RSI/MACD/Bollinger 的确定性规则，硬约束：不阻塞主流程）
 *  4. 写 signals 表 → 调度 DispatchNotificationJob
 *
 * 失败策略：AI 失败必须走 fallback；fallback 也失败才允许 Job 失败（有限重试）。
 * 与架构方案 §1 AI Provider 抽象 + §3 Signal 模块一致。
 *
 * POC 阶段：handle() 待实现，对应 P2 POC（AI 信号链路）。
 */
class AiAnalyzeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 队列名：AI 调用通常较慢，独立队列避免阻塞订单/通知链路。
     */
    public $queue = 'ai';

    public function __construct(
        public readonly int $tenantId,
        public readonly int $subscriptionId,
        public readonly string $symbol,
        public readonly string $interval,
        public readonly array $barData,
    ) {
    }

    /**
     * P2 POC 实现：AIPrompter.analyze + RuleEngineFallback 兜底。
     */
    public function handle(): void
    {
        throw new RuntimeException('Not implemented. Wait for POC.');
    }
}
