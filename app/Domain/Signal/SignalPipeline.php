<?php

declare(strict_types=1);

namespace App\Domain\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Signal\Contracts\AiUnavailableException;
use App\Domain\Signal\Contracts\AIPrompter;
use App\Domain\Signal\Contracts\Signal;
use App\Domain\Signal\Prompters\DeepSeekAdapter;
use App\Domain\Signal\Prompters\LocalOllamaAdapter;
use App\Domain\Signal\Prompters\OpenAIAdapter;
use App\Domain\Signal\Prompters\QwenAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 信号管线（P2 POC 主编排，运行在 queue worker 的 ai 队列，非协程进程）。
 *
 * 流程（架构 §5.3）：
 *   历史 bar → 指标计算 → AI 分析 →（AI 不可用）规则引擎兜底
 *   → 1% 价差守卫 → signals 落库 → SignalDispatcher 通知
 *
 * 约定：
 *  - neutral 只记日志不落库（exit_price NOT NULL 且无通知价值）
 *  - 价差守卫丢弃只记日志
 *  - 规则引擎被订阅关闭时（rule_fallback_enabled=0），AI 失败即放弃本轮信号
 *  - P2 只分析与通知，不触发下单
 */
class SignalPipeline
{
    /** provider key → 适配器类 */
    private const ADAPTERS = [
        'deepseek' => DeepSeekAdapter::class,
        'openai' => OpenAIAdapter::class,
        'qwen' => QwenAdapter::class,
        'local' => LocalOllamaAdapter::class,
    ];

    public function __construct(
        private readonly IndicatorCalculator $indicators,
        private readonly RuleEngineFallback $fallback,
        private readonly SignalDispatcher $dispatcher,
    ) {
    }

    /**
     * 执行一轮信号分析。
     *
     * @param object          $subscription signal_subscriptions 行（DB::table 裸对象，json 已解码）
     * @param ExchangeKline   $latestBar    触发本轮分析的已闭 bar
     * @param ExchangeKline[] $historicalBars 升序历史 bar（建议 ≥120 根，含最新）
     */
    public function run(object $subscription, ExchangeKline $latestBar, array $historicalBars): ?Signal
    {
        $tenantId = (int) $subscription->tenant_id;
        $subscriptionId = (int) $subscription->id;

        $indicatorSnapshot = $this->indicators->compute($historicalBars);
        $context = [
            'tenant_id' => $tenantId,
            'historical_bars' => array_values($historicalBars),
            'indicators' => $indicatorSnapshot,
        ];

        // ---- AI 主路（失败/不支持的 provider → 兜底分支） ----
        $signal = null;
        $prompter = $this->resolvePrompter((string) ($subscription->ai_prompter ?? 'deepseek'));

        if ($prompter !== null) {
            try {
                $signal = $prompter->analyze($latestBar, $context);
            } catch (AiUnavailableException $e) {
                Log::warning('AI 不可用，尝试规则引擎兜底', [
                    'tenant_id' => $tenantId,
                    'subscription_id' => $subscriptionId,
                    'provider' => $prompter->name(),
                    'reason' => $e->getMessage(),
                ]);
                $signal = null;
            }
        } else {
            Log::warning('订阅配置了不支持的 AI provider，走兜底', [
                'tenant_id' => $tenantId,
                'provider' => $subscription->ai_prompter ?? null,
            ]);
        }

        if ($signal === null) {
            if (! (int) ($subscription->rule_fallback_enabled ?? 1)) {
                Log::info('AI 失败且订阅关闭规则兜底，本轮无信号', [
                    'subscription_id' => $subscriptionId,
                ]);

                return null;
            }
            $signal = $this->fallback->analyze($latestBar, $context);
        }

        // ---- neutral：只记日志 ----
        if ($signal->direction === 'neutral') {
            Log::info('信号 neutral 不落库', [
                'tenant_id' => $tenantId,
                'symbol' => $latestBar->symbol,
                'interval' => $latestBar->interval,
                'source' => $signal->source,
                'rationale' => $signal->rationale,
            ]);

            return null;
        }

        // ---- 价差守卫：AI 耗时期间价格可能已漂移 ----
        if ($this->priceDeviationExceeds($signal, $latestBar)) {
            Log::warning('信号未通过价差守卫，丢弃', [
                'tenant_id' => $tenantId,
                'symbol' => $signal->symbol,
                'direction' => $signal->direction,
                'entry_price' => $signal->entryPrice,
                'close' => $latestBar->close,
            ]);

            return null;
        }

        // ---- 落库 ----
        $now = Carbon::now();
        $signalId = (int) DB::table('signals')->insertGetId([
            'tenant_id' => $tenantId,
            'subscription_id' => $subscriptionId,
            'symbol' => $signal->symbol,
            'interval' => $signal->interval,
            'direction' => $signal->direction,
            'entry_price' => $signal->entryPrice,
            'exit_price' => $signal->exitPrice,
            'stop_loss' => $signal->stopLoss,
            'confidence' => $signal->confidence,
            'source' => $signal->source,
            'rationale' => $signal->rationale,
            'raw_payload' => $signal->rawPayload !== null
                ? json_encode($signal->rawPayload, JSON_UNESCAPED_UNICODE)
                : null,
            'triggered_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $persisted = $signal->withPersistence($tenantId, $subscriptionId, $signalId);

        // ---- 通知（失败不影响信号落库） ----
        $channels = $subscription->channels;
        if (is_string($channels)) {
            $channels = json_decode($channels, true);
        }
        $channels = is_array($channels) ? $channels : [];

        try {
            $this->dispatcher->dispatch($persisted, $channels);
        } catch (Throwable $e) {
            Log::error('信号通知分发异常（不影响信号落库）', [
                'signal_id' => $signalId,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('信号已落库', [
            'signal_id' => $signalId,
            'tenant_id' => $tenantId,
            'symbol' => $persisted->symbol,
            'direction' => $persisted->direction,
            'source' => $persisted->source,
            'confidence' => $persisted->confidence,
        ]);

        return $persisted;
    }

    private function resolvePrompter(string $name): ?AIPrompter
    {
        $class = self::ADAPTERS[$name] ?? null;

        return $class !== null ? app($class) : null;
    }

    private function priceDeviationExceeds(Signal $signal, ExchangeKline $bar): bool
    {
        $guard = (array) config('ai.signal_price_guard');
        if (! ($guard['enabled'] ?? true)) {
            return false;
        }
        $maxPct = (float) ($guard['max_deviation_pct'] ?? 1.0);

        $entry = (float) $signal->entryPrice;
        $close = (float) $bar->close;
        if ($close <= 0) {
            return true;
        }

        return abs($entry - $close) / $close * 100 > $maxPct;
    }
}
