<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Signal\SignalPipeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Support\RedisCleanup;
use Tests\TestCase;
use Throwable;

/**
 * AI 配额耗尽 / 权益失效 → 规则兜底（迁自 poc:verify-billing 第 5 段，@group redis）。
 *
 * 断言：耗尽时 pipeline 确定性产出 rule_fallback long、不投 HTTP、不写 meter 行；
 * suspended 租户跳过 AI 直接兜底。DeepSeek key 显式置为离线假值——配额闸门在
 * HTTP 之前抛出，假 key 若被用到会立即连接失败，反而保证「零外网」可证。
 */
#[Group('redis')]
class AiQuotaFallbackTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private const TENANT_ID = 990033;

    private const EVENT = 'ai_call';

    private TwoPhaseMeter $meter;

    /** tearDown 守卫：仅 setUp ping 成功才清 Redis（坑 #24） */
    private bool $redisAvailable = false;

    /** @var array<string,mixed> 需在 tearDown 还原的配置 */
    private array $savedConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        Queue::fake();
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC'));

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-billing-ai-990033',
            'slug' => 'poc-billing-ai-990033',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->meter = app(TwoPhaseMeter::class);
        $this->cleanupRedis($this->currentRedisKeys());
    }

    protected function tearDown(): void
    {
        foreach ($this->savedConfig as $key => $value) {
            config([$key => $value]);
        }

        if ($this->redisAvailable) {
            try {
                // 分钟桶按调用时刻重算，跨分钟边界也能清干净
                $this->cleanupRedis($this->currentRedisKeys());
            } catch (Throwable) {
                // key 均带 TTL 且使用高位隔离租户 ID，清理失败不吞测试结果
            }
        }
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    /**
     * 本测试涉及的 Redis 键（逻辑名，无前缀）。
     *
     * 坑 #26：逻辑名交给 Redis facade，客户端自动拼一次全局前缀。
     */
    private function currentRedisKeys(): array
    {
        return [
            sprintf('tenant:%d:meter:%s:%s', self::TENANT_ID, self::EVENT, '20261015'),
            $this->rateLimitKey(),
            $this->dedupKey(),
        ];
    }

    public function test_exhausted_quota_emits_deterministic_rule_fallback_without_http_or_meter_row(): void
    {
        $this->savedConfig['plans.plans.free.features.ai_calls_per_day'] =
            config('plans.plans.free.features.ai_calls_per_day');
        $this->savedConfig['ai.providers.deepseek.api_key'] = config('ai.providers.deepseek.api_key');
        config(['plans.plans.free.features.ai_calls_per_day' => 1]);
        config(['ai.providers.deepseek.api_key' => 'sk-offline-test-not-used']);

        // signals.subscription_id 有 FK：插真实的 active=0 脚手架订阅
        $scaffoldId = (int) DB::table('signal_subscriptions')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => 'poc-billing-ai-quota',
            'symbols' => json_encode(['PBFTEST']),
            'intervals' => json_encode(['5m']),
            'ai_prompter' => 'deepseek',
            'ai_model' => null,
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(['log']),
            'active' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // 先占掉唯一配额
        app(QuotaGuard::class)->freezeDaily(self::TENANT_ID, 'ai_call', 'pre-exhaust');
        static::assertSame(1, $this->meter->usedToday(self::TENANT_ID, 'ai_call'), '前置：1 个配额已占');

        $bars = $this->deterministicBullishBars();
        $latest = $bars[count($bars) - 1];
        $subscription = (object) [
            'id' => $scaffoldId,
            'tenant_id' => self::TENANT_ID,
            'ai_prompter' => 'deepseek',
            'rule_fallback_enabled' => 1,
            'channels' => ['log'],
        ];

        $startedAt = microtime(true);
        $signal = app(SignalPipeline::class)->run($subscription, $latest, $bars);
        $elapsed = microtime(true) - $startedAt;

        static::assertNotNull($signal);
        static::assertSame('rule_fallback', $signal->source);
        static::assertSame('long', $signal->direction);
        static::assertLessThan(5.0, $elapsed, '耗尽路径必须不投 HTTP（耗时 < 5s）');

        $aiRows = DB::table('meter_events')
            ->where('tenant_id', self::TENANT_ID)
            ->where('event_type', 'ai_call')
            ->where('ref_id', $scaffoldId.':'.$latest->openTime)
            ->count();
        static::assertSame(0, $aiRows, '配额耗尽时不得写 meter 行');
    }

    public function test_suspended_tenant_skips_ai_and_uses_rule_fallback(): void
    {
        $scaffoldId = (int) DB::table('signal_subscriptions')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => 'poc-billing-ai-suspended',
            'symbols' => json_encode(['PBFTEST']),
            'intervals' => json_encode(['5m']),
            'ai_prompter' => 'deepseek',
            'ai_model' => null,
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(['log']),
            'active' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $bars = $this->deterministicBullishBars();
        $latest = $bars[count($bars) - 1];
        $subscription = (object) [
            'id' => $scaffoldId,
            'tenant_id' => self::TENANT_ID,
            'ai_prompter' => 'deepseek',
            'rule_fallback_enabled' => 1,
            'channels' => ['log'],
        ];

        DB::table('tenants')->where('id', self::TENANT_ID)->update(['status' => 'suspended']);
        try {
            $signal = app(SignalPipeline::class)->run($subscription, $latest, $bars);
        } finally {
            DB::table('tenants')->where('id', self::TENANT_ID)->update(['status' => 'active']);
        }

        static::assertNotNull($signal);
        static::assertSame('rule_fallback', $signal->source);
        static::assertSame('long', $signal->direction);
    }

    private function rateLimitKey(): string
    {
        // AbstractAIPrompter 用真实 time()（不受 Carbon::setTestNow 影响）计算分钟桶
        return sprintf('tenant:%d:ai:ratelimit:deepseek:%d', self::TENANT_ID, intdiv(time(), 60));
    }

    private function dedupKey(): string
    {
        return sprintf('tenant:%d:dedup:signal:%s', self::TENANT_ID, sha1('PBFTEST|5m|long'));
    }

    /**
     * 确定性多头形态（迁自 POC）：100 根走平@100 → 10 根每根-0.2 → 10 根每根+0.2。
     *
     * @return ExchangeKline[]
     */
    private function deterministicBullishBars(): array
    {
        $closes = array_fill(0, 100, 100.0);
        for ($i = 1; $i <= 10; $i++) {
            $closes[] = 100.0 - $i * 0.2;
        }
        $price = 100.0 - 2.0;
        for ($i = 0; $i < 10; $i++) {
            $price += 0.2;
            $closes[] = $price;
        }

        $base = intdiv(Carbon::now()->getTimestamp() * 1000, 300_000) * 300_000 - 120 * 300_000;
        $bars = [];
        foreach ($closes as $i => $c) {
            $bars[] = new ExchangeKline(
                exchangeCode: 'binance',
                symbol: 'PBFTEST',
                interval: '5m',
                openTime: $base + $i * 300_000,
                closeTime: $base + $i * 300_000 + 299_999,
                open: (string) $c,
                high: (string) $c,
                low: (string) $c,
                close: (string) $c,
                volume: '1',
                quoteVolume: (string) $c,
                tradesCount: 1,
            );
        }

        return $bars;
    }
}
