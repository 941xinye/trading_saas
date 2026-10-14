<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Exchange\ExchangeManager;
use App\Domain\Signal\SignalPipeline;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI 信号分析 Job（ai 队列，普通 PHP 进程）。
 *
 * 触发：market:feed 的 SignalFeedRunner 在目标周期 bar 闭合后，按订阅逐份投递。
 *
 * 执行链路：
 *  1. 加载 active 订阅（订阅在投递后可能被停用/删除，失效即放弃）
 *  2. REST 预拉 120 根目标周期 bar（覆盖 MA99 预热，架构 §9.3）
 *  3. SignalPipeline：指标 → AI（限流/超时/解析失败）→ 规则引擎兜底
 *     → 价差守卫 → signals 落库 → 通知入队
 *
 * AI 失败由 pipeline 内部兜底，不再算作 Job 失败；只有兜底本身抛异常才重试（tries=2）。
 * P2 只分析与通知，不触发下单。
 */
class AiAnalyzeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** POC 固定币安现货；公开行情无需凭据（testnet K 线镜像真实市场） */
    private const EXCHANGE = 'binance';

    private const MARKET = 'spot';

    private const WARMUP_LIMIT = 120;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $subscriptionId,
        public readonly string $symbol,
        public readonly string $interval,
        /** @var array<string,mixed> 触发本 Job 的已闭聚合 bar（ExchangeKline 转数组） */
        public readonly array $barData,
    ) {
        // Laravel 13 Queueable trait 自带 $queue 属性，不能重复声明，走 onQueue()
        $this->onQueue('ai');
    }

    public function handle(ExchangeManager $exchangeManager, SignalPipeline $pipeline): void
    {
        // Job 边界恢复租户上下文（TenantScope/计量落库在 worker 进程内按租户隔离）
        TenantContext::initialize($this->tenantId);

        try {
            $this->execute($exchangeManager, $pipeline);
        } finally {
            TenantContext::clear();
        }
    }

    private function execute(ExchangeManager $exchangeManager, SignalPipeline $pipeline): void
    {
        $subscription = DB::table('signal_subscriptions')
            ->where('id', $this->subscriptionId)
            ->where('tenant_id', $this->tenantId)
            ->where('active', 1)
            ->first();

        if ($subscription === null) {
            Log::info('AI 分析跳过：订阅不存在或已停用', [
                'subscription_id' => $this->subscriptionId,
            ]);

            return;
        }

        $adapter = $exchangeManager->adapter(self::EXCHANGE, self::MARKET, []);
        $bars = $adapter->getKline($this->symbol, $this->interval, self::WARMUP_LIMIT);

        if (count($bars) < 30) {
            throw new \RuntimeException(sprintf(
                '历史 bar 不足（%d 根），无法计算指标',
                count($bars),
            ));
        }

        $latest = $bars[count($bars) - 1];

        // REST 最新 bar 与触发 bar 可能错位（边界投递竞态），记录但以 REST 为准
        if (! empty($this->barData['close_time']) && (int) $this->barData['close_time'] !== $latest->closeTime) {
            Log::info('触发 bar 与 REST 最新 bar 错位，以 REST 为准', [
                'subscription_id' => $this->subscriptionId,
                'trigger_close_time' => $this->barData['close_time'],
                'rest_close_time' => $latest->closeTime,
            ]);
        }

        try {
            $pipeline->run($subscription, $latest, $bars);
        } catch (Throwable $e) {
            Log::error('信号管线异常', [
                'subscription_id' => $this->subscriptionId,
                'symbol' => $this->symbol,
                'interval' => $this->interval,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
