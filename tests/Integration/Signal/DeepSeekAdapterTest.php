<?php

declare(strict_types=1);

namespace Tests\Integration\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Signal\Contracts\AiUnavailableException;
use App\Domain\Signal\IndicatorCalculator;
use App\Domain\Signal\Prompters\DeepSeekAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Support\RedisCleanup;
use Tests\TestCase;
use Throwable;

/**
 * DeepSeekAdapter HTTP 契约测试（P5.5 步骤 3，迁自 poc:verify-ai [3/7]/[4/7]-4a）。
 *
 * 全程 Http::fake 无外网；AI 两阶段计量与每分钟限流依赖 Redis，故整类 @group redis。
 * 真实 DeepSeek 连通性仍保留在 poc:verify-ai 命令作人工 smoke。
 */
#[Group('redis')]
class DeepSeekAdapterTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private const TENANT_ID = 990060;

    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        Carbon::setTestNow(Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC'));

        $this->cleanupRedis($this->currentRedisKeys());

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-ai-adapter-990060',
            'slug' => 'poc-ai-adapter-990060',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        config([
            'ai.providers.deepseek.api_key' => 'sk-test',
            'ai.providers.deepseek.api_base' => 'https://deepseek.invalid/v1',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->redisAvailable) {
            try {
                $this->cleanupRedis($this->currentRedisKeys());
            } catch (Throwable) {
            }
        }
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    /**
     * 本测试涉及的 Redis 键（逻辑名，无前缀；分钟桶按调用时刻重算）。
     *
     * 坑 #26：逻辑名交给 Redis facade，客户端自动拼一次全局前缀。
     */
    private function currentRedisKeys(): array
    {
        return [
            sprintf('tenant:%d:meter:ai_call:%s', self::TENANT_ID, '20261015'),
            sprintf('tenant:%d:ai:ratelimit:deepseek:%d', self::TENANT_ID, intdiv(time(), 60)),
        ];
    }

    /**
     * poc 断言 [3]-1~5：合法应答映射为 source=ai 的 Signal DTO（方向/入场/置信度/理由）。
     */
    public function test_success_response_maps_to_valid_ai_signal_dto(): void
    {
        Http::fake([
            '*deepseek.invalid/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'direction' => 'long',
                        'entry_price' => 100.5,
                        'exit_price' => 105,
                        'stop_loss' => 99.5,
                        'confidence' => 77,
                        'rationale' => '均线金叉且放量突破，多头结构明确',
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ]),
        ]);

        $bars = $this->bars();
        $signal = app(DeepSeekAdapter::class)->analyze($bars[count($bars) - 1], [
            'tenant_id' => self::TENANT_ID,
            'subscription_id' => 990601,
            'historical_bars' => $bars,
            'indicators' => app(IndicatorCalculator::class)->compute($bars),
        ]);

        static::assertSame('ai', $signal->source);
        static::assertContains($signal->direction, ['long', 'short']);
        static::assertGreaterThan(0, (float) $signal->entryPrice);
        static::assertSame(0, bccomp($signal->entryPrice, '100.5', 8));
        static::assertGreaterThanOrEqual(0, $signal->confidence);
        static::assertLessThanOrEqual(100, $signal->confidence);
        static::assertNotNull($signal->rationale);
        static::assertNotSame('', $signal->rationale);
    }

    /**
     * poc 断言 [4]-4a：HTTP 401（错误 key）必须包成 AiUnavailableException 上抛，由管线兜底。
     */
    public function test_unauthorized_response_throws_ai_unavailable_exception(): void
    {
        Http::fake([
            '*deepseek.invalid/*' => Http::response(['error' => ['message' => 'Invalid Authentication']], 401),
        ]);

        $bars = $this->bars();

        $this->expectException(AiUnavailableException::class);

        app(DeepSeekAdapter::class)->analyze($bars[count($bars) - 1], [
            'tenant_id' => self::TENANT_ID,
            'subscription_id' => 990602,
            'historical_bars' => $bars,
            'indicators' => app(IndicatorCalculator::class)->compute($bars),
        ]);
    }

    /**
     * AI 返回 long 但 exit_price < entry（方向矛盾），exit 应被替换为 1.0075 百分比兜底值。
     */
    public function test_long_with_backward_exit_price_uses_percent_fallback(): void
    {
        Http::fake([
            '*deepseek.invalid/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'direction' => 'long',
                        'entry_price' => 100.0,
                        'exit_price' => 99.5,     // 矛盾：long 的 exit 应 > entry
                        'stop_loss' => 99.0,
                        'confidence' => 65,
                        'rationale' => '均线金叉',
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ]),
        ]);

        $bars = $this->bars();
        $signal = app(DeepSeekAdapter::class)->analyze($bars[count($bars) - 1], [
            'tenant_id' => self::TENANT_ID,
            'subscription_id' => 990603,
            'historical_bars' => $bars,
            'indicators' => app(IndicatorCalculator::class)->compute($bars),
        ]);

        static::assertSame('long', $signal->direction);
        static::assertNotNull($signal->exitPrice);
        static::assertGreaterThan(0, bccomp($signal->exitPrice, '100', 8), 'long exit 必须 > entry');
        static::assertLessThanOrEqual(0, bccomp($signal->exitPrice, '100.75', 8), 'long exit 兜底为 +0.75%');
    }

    /**
     * AI 返回 short 但 exit_price > entry（方向矛盾），exit 应被替换为 0.9925 百分比兜底值。
     */
    public function test_short_with_forward_exit_price_uses_percent_fallback(): void
    {
        Http::fake([
            '*deepseek.invalid/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'direction' => 'short',
                        'entry_price' => 100.0,
                        'exit_price' => 101.0,    // 矛盾：short 的 exit 应 < entry
                        'stop_loss' => 101.5,
                        'confidence' => 70,
                        'rationale' => '均线死叉',
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ]),
        ]);

        $bars = $this->bars();
        $signal = app(DeepSeekAdapter::class)->analyze($bars[count($bars) - 1], [
            'tenant_id' => self::TENANT_ID,
            'subscription_id' => 990604,
            'historical_bars' => $bars,
            'indicators' => app(IndicatorCalculator::class)->compute($bars),
        ]);

        static::assertSame('short', $signal->direction);
        static::assertNotNull($signal->exitPrice);
        static::assertLessThan(0, bccomp($signal->exitPrice, '100', 8), 'short exit 必须 < entry');
        static::assertGreaterThanOrEqual(0, bccomp($signal->exitPrice, '99.25', 8), 'short exit 兜底为 -0.75%');
    }

    /**
     * @return ExchangeKline[]
     */
    private function bars(): array
    {
        $bars = [];
        $base = intdiv(time() * 1000, 300_000) * 300_000 - 120 * 300_000;
        for ($i = 0; $i < 120; $i++) {
            $close = (string) (100 + $i * 0);
            $bars[] = new ExchangeKline(
                exchangeCode: 'binance',
                symbol: 'BTCUSDT',
                interval: '5m',
                openTime: $base + $i * 300_000,
                closeTime: $base + $i * 300_000 + 299_999,
                open: $close,
                high: $close,
                low: $close,
                close: $close,
                volume: '10',
                quoteVolume: '1000',
                tradesCount: 5,
            );
        }

        return $bars;
    }
}
