<?php

declare(strict_types=1);

namespace App\Domain\Signal\Prompters;

use App\Domain\Billing\Metering\QuotaExceededException;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\RateLimiter;
use App\Domain\Signal\Contracts\AiUnavailableException;
use App\Domain\Signal\Contracts\AIPrompter;
use App\Domain\Signal\Contracts\Signal;
use App\Domain\Signal\PromptBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * AI Prompter 抽象基类（OpenAI 兼容协议）。
 *
 * DeepSeek/OpenAI/Qwen 均为 /chat/completions 兼容协议，HTTP 细节收敛在本类，
 * 子类只需实现 name()/model()（LocalOllama 可重写 requestChat）。
 *
 * 失败语义（架构 §9.3）：任何异常（限流/超时/网络/鉴权/JSON 解析）→ report() 后
 * 包成 AiUnavailableException 上抛，SignalPipeline 捕获后走 RuleEngineFallback，
 * AI 永远不阻塞信号主链路。
 *
 * 限流：Redis 固定窗口，维度 tenant:{tid}:ai:ratelimit:{provider}:{minuteBucket}，
 * 阈值取 config('ai.rate_limiter.requests_per_minute')（30/min/tenant）。
 * 不用交易所 RateLimiter（其 key 维度是 exchange:endpoint）。
 *
 * 关键约束：本类只允许在 queue worker（普通 PHP 进程）内调用，
 * 禁止在 market:feed（Swoole 协程、已摘 CURL hook）内调用，否则真阻塞事件循环。
 */
abstract class AbstractAIPrompter implements AIPrompter
{
    public function __construct(
        protected readonly RateLimiter $rateLimiter,
        protected readonly PromptBuilder $promptBuilder,
        protected readonly QuotaGuard $quotaGuard,
    ) {
    }

    public function analyze(ExchangeKline $latestBar, array $context): Signal
    {
        $cfg = (array) config('ai.providers.' . $this->name());
        $tenantId = (int) ($context['tenant_id'] ?? 0);

        // 两阶段计量：仅在「配置就绪 + 每分钟限流通过」后占额（这两类前置失败不扣配额）
        $meter = null;
        try {
            $this->assertConfigured($cfg);
            $this->guardRateLimit($tenantId, $cfg);

            // 日配额闸门（ref=订阅:闭合bar，Job 重试幂等不双扣）；超限直接上抛由管线兜底
            $ref = sprintf('%d:%d', (int) ($context['subscription_id'] ?? 0), $latestBar->openTime);
            $meter = $this->quotaGuard->freezeDaily($tenantId, 'ai_call', $ref);

            $historicalBars = (array) ($context['historical_bars'] ?? []);
            $indicators = (array) ($context['indicators'] ?? []);

            $messages = [
                ['role' => 'system', 'content' => $this->promptBuilder->systemPrompt()],
                ['role' => 'user', 'content' => $this->promptBuilder->build(
                    $latestBar,
                    $historicalBars,
                    $indicators,
                    ['context_limit' => $cfg['context_limit'] ?? 32000, 'truncate_ratio' => $cfg['truncate_ratio'] ?? 0.6],
                )],
            ];

            $raw = $this->requestChat($messages, $cfg);
            $parsed = $this->parseJsonContent($raw);

            if (! $this->isValidShape($parsed)) {
                // 唯一一次纠错重试：把坏输出回灌并要求严格 JSON
                Log::warning('AI 返回 JSON 结构不合法，执行一次纠错重试', [
                    'provider' => $this->name(),
                    'tenant_id' => $tenantId,
                    'raw' => mb_substr((string) $raw, 0, 500),
                ]);
                $messages[] = ['role' => 'assistant', 'content' => (string) $raw];
                $messages[] = ['role' => 'user', 'content' => '上一次输出不是符合契约的 JSON。请只输出一个 JSON 对象，包含 direction/entry_price/exit_price/stop_loss/confidence/rationale 字段，不要输出任何其他文字。'];
                $parsed = $this->parseJsonContent($this->requestChat($messages, $cfg));

                if (! $this->isValidShape($parsed)) {
                    throw new \RuntimeException('AI JSON shape invalid after corrective retry');
                }
            }

            $signal = $this->toSignal($latestBar, $parsed);
            $this->quotaGuard->settle($meter);

            return $signal;
        } catch (QuotaExceededException $e) {
            // 未拿到配额（无 meter 行），直接上抛，管线据此走规则兜底
            throw $e;
        } catch (ConnectionException $e) {
            // 请求未到达/未应答（超时、断连）：配额冲正后兜底
            if ($meter !== null) {
                $this->quotaGuard->release($meter);
            }
            Log::error('AI 连接失败（未计费），转规则引擎兜底', [
                'provider' => $this->name(),
                'tenant_id' => $tenantId,
                'symbol' => $latestBar->symbol,
                'error' => $e->getMessage(),
            ]);

            throw new AiUnavailableException($e->getMessage(), (int) $e->getCode(), $e);
        } catch (AiUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            // 已收到应答（含 4xx/5xx）或应答后解析失败：调用已发生，结算保留配额
            if ($meter !== null) {
                $this->quotaGuard->settle($meter);
            }
            Log::error('AI 分析失败，转规则引擎兜底', [
                'provider' => $this->name(),
                'model' => $this->modelSafe(),
                'tenant_id' => $tenantId,
                'symbol' => $latestBar->symbol,
                'error' => $e->getMessage(),
            ]);

            throw new AiUnavailableException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    // ------------------------------------------------------------------
    // HTTP（OpenAI 兼容；子类需要非兼容协议时重写本方法）
    // ------------------------------------------------------------------

    /**
     * 发起 chat/completions 请求，返回 message.content 原文。
     *
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $cfg
     */
    protected function requestChat(array $messages, array $cfg): string
    {
        $apiBase = rtrim((string) ($cfg['api_base'] ?? ''), '/');
        $timeout = (int) ($cfg['timeout'] ?? 30);

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->withToken((string) $cfg['api_key'])
            ->post($apiBase . '/chat/completions', [
                'model' => $this->model(),
                'messages' => $messages,
                'temperature' => 0.2,
                'max_tokens' => 512,
                'response_format' => ['type' => 'json_object'],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(sprintf(
                'AI HTTP %d: %s',
                $response->status(),
                mb_substr($response->body(), 0, 300),
            ));
        }

        $payload = $response->json();
        $content = $payload['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new \RuntimeException('AI response missing choices[0].message.content');
        }

        return $content;
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $cfg
     */
    private function assertConfigured(array $cfg): void
    {
        if (empty($cfg['api_key'])) {
            throw new \RuntimeException(sprintf('AI provider %s api_key 未配置', $this->name()));
        }
        if (empty($cfg['api_base'])) {
            throw new \RuntimeException(sprintf('AI provider %s api_base 未配置', $this->name()));
        }
    }

    /**
     * Redis 固定窗口限流（每分钟独立桶，首次 INCR 设置 60s 过期）。
     *
     * @param array<string,mixed> $cfg
     */
    private function guardRateLimit(int $tenantId, array $cfg): void
    {
        $limit = (int) ($cfg['requests_per_minute'] ?? config('ai.rate_limiter.requests_per_minute', 30));
        $bucket = intdiv(time(), 60);
        $key = sprintf('tenant:%d:ai:ratelimit:%s:%d', $tenantId, $this->name(), $bucket);

        $redis = Redis::connection('default');
        $count = (int) $redis->incr($key);
        if ($count === 1) {
            $redis->expire($key, 60);
        }

        if ($count > $limit) {
            throw new \RuntimeException(sprintf('AI 限流命中：tenant=%d provider=%s count=%d/%d', $tenantId, $this->name(), $count, $limit));
        }
    }

    /**
     * 容错解析：剥离 ```json 代码围栏后 json_decode。
     *
     * @return array<string,mixed>|null
     */
    private function parseJsonContent(string $raw): ?array
    {
        $text = trim($raw);

        // 剥离 ```json ... ``` / ``` ... ``` 围栏
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }

        // 容错：截取首个 { 到末个 } 之间的内容
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed>|null $data
     */
    private function isValidShape(?array $data): bool
    {
        if ($data === null) {
            return false;
        }
        $direction = $data['direction'] ?? null;
        if (! in_array($direction, ['long', 'short', 'neutral'], true)) {
            return false;
        }
        if (! array_key_exists('confidence', $data)) {
            return false;
        }

        return is_numeric($data['entry_price'] ?? null)
            || ($data['exit_price'] ?? null) === null
            || is_numeric($data['exit_price'] ?? null);
    }

    /**
     * 校验/归一化 AI 输出为 Signal DTO。
     *
     * @param array<string,mixed> $data
     */
    private function toSignal(ExchangeKline $bar, array $data): Signal
    {
        $direction = (string) $data['direction'];
        $close = (float) $bar->close;

        $entry = $this->priceOrNull($data['entry_price'] ?? null);
        if ($entry === null) {
            $entry = $this->formatPrice($close); // 入库 NOT NULL，缺省锚定最新收盘价
        }
        $exit = $this->priceOrNull($data['exit_price'] ?? null);
        $stop = $this->priceOrNull($data['stop_loss'] ?? null);

        // 方向与价位矛盾时：exit 用百分比退化兜底（与 RuleEngineFallback 一致，ATR 不可用退化 0.75%），
        // stop 置 null（signals.stop_loss 列已 nullable，安全）
        // neutral 不持久化，exit 用百分比占位 + stop 置 null（方向无意义，但 exit_price 列 NOT NULL）
        $entryFloat = (float) $entry;
        if ($direction === 'long') {
            if ($exit !== null && (float) $exit <= $entryFloat) {
                $exit = $this->formatPrice($entryFloat * 1.0075);
            }
            if ($stop !== null && (float) $stop >= $entryFloat) {
                $stop = null;
            }
        } elseif ($direction === 'short') {
            if ($exit !== null && (float) $exit >= $entryFloat) {
                $exit = $this->formatPrice($entryFloat * 0.9925);
            }
            if ($stop !== null && (float) $stop <= $entryFloat) {
                $stop = null;
            }
        } else {
            // neutral → exit 用 0.75% 百分比占位（NOT NULL 列必须有值），stop 置 null
            $exit = $this->formatPrice($entryFloat * 1.0075);
            $stop = null;
        }

        $confidence = (int) round((float) ($data['confidence'] ?? 0));
        $confidence = max(0, min(100, $confidence));

        $rationale = isset($data['rationale']) && is_string($data['rationale'])
            ? mb_substr(trim($data['rationale']), 0, 200)
            : null;

        return new Signal(
            symbol: $bar->symbol,
            interval: $bar->interval,
            direction: $direction,
            entryPrice: $entry,
            exitPrice: $exit,
            stopLoss: $stop,
            confidence: $confidence,
            rationale: $rationale !== '' ? $rationale : null,
            source: 'ai',
            rawPayload: $data,
        );
    }

    private function priceOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;
        if ($float <= 0 || ! is_finite($float)) {
            return null;
        }

        return $this->formatPrice($float);
    }

    private function formatPrice(float $price): string
    {
        return number_format($price, 8, '.', '');
    }

    private function modelSafe(): string
    {
        try {
            return $this->model();
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
