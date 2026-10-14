<?php

declare(strict_types=1);

namespace Tests\Integration\Signal;

use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Signal\SignalPipeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\TestCase;
use Throwable;

/**
 * SignalPipeline 全链路测试（P5.5 步骤 3，迁自 poc:verify-ai [4/7]-4b 与 [3/7] 落库断言）。
 *
 *  - AI 401 → RuleEngineFallback 确定性多头形态 → 落 signals(source=rule_fallback) + queued 通知
 *  - AI 合法应答 → 落 signals(source=ai) + queued 通知
 * 全程 Http::fake / Queue::fake，外部零访问；计量与去重依赖 Redis，整类 @group redis。
 */
#[Group('redis')]
class SignalPipelineAiTest extends TestCase
{
    use ChecksRedisAvailability;

    private const TENANT_ID = 990061;

    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();
        $this->redisAvailable = true;

        Queue::fake();
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC'));

        app(TwoPhaseMeter::class)->flushDayCounter(self::TENANT_ID, 'ai_call');

        $now = Carbon::now();
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-ai-pipeline-990061',
            'slug' => 'poc-ai-pipeline-990061',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
            'created_at' => $now,
            'updated_at' => $now,
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
                app(TwoPhaseMeter::class)->flushDayCounter(self::TENANT_ID, 'ai_call');
                // 按精确逻辑名清理（phpredis 全局前缀会自动拼接一次，不能用 keys() 的全名再 del）
                Redis::connection('default')->del(sprintf(
                    'tenant:%d:dedup:signal:%s',
                    self::TENANT_ID,
                    sha1('PBFPHPUNIT|5m|long'),
                ));
                Redis::connection('default')->del(sprintf(
                    'tenant:%d:dedup:signal:%s',
                    self::TENANT_ID,
                    sha1('AIOKPHPUNIT|5m|long'),
                ));
                Redis::connection('default')->del(sprintf(
                    'tenant:%d:ai:ratelimit:deepseek:%d',
                    self::TENANT_ID,
                    intdiv(time(), 60),
                ));
            } catch (Throwable) {
                // 坑 #24：外部清理必须守卫
            }
        }
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    /**
     * poc 断言 [4]-4b-1~7：错误 key → 规则兜底确定性多头形态 → 信号落库且通知排队。
     */
    public function test_ai_unavailable_falls_back_to_rule_engine_and_persists(): void
    {
        Http::fake([
            '*deepseek.invalid/*' => Http::response(['error' => ['message' => 'Invalid Authentication']], 401),
        ]);

        $subscription = $this->makeSubscription('poc-ai-fallback-990061');
        $bars = $this->deterministicBullishBars('PBFPHPUNIT');
        $latest = $bars[count($bars) - 1];
        $beforeId = (int) DB::table('signals')->max('id');

        $signal = app(SignalPipeline::class)->run($subscription, $latest, $bars);

        static::assertNotNull($signal);
        static::assertSame('rule_fallback', $signal->source);
        static::assertSame('long', $signal->direction);
        static::assertSame(0, bccomp($signal->entryPrice, '100', 8));
        static::assertNotNull($signal->exitPrice);
        static::assertGreaterThan(0, bccomp($signal->exitPrice, $signal->entryPrice, 8));
        static::assertNotNull($signal->stopLoss);
        static::assertLessThan(0, bccomp($signal->stopLoss, $signal->entryPrice, 8));

        $row = DB::table('signals')
            ->where('id', '>', $beforeId)
            ->where('source', 'rule_fallback')
            ->latest('id')
            ->first();
        static::assertNotNull($row);

        // 兜底信号同样进入通知分发（首 fingerprint 落 queued，Job 被 Queue::fake 拦截）
        static::assertSame(1, DB::table('signal_notifications')
            ->where('signal_id', $row->id)
            ->where('channel', 'log')
            ->where('status', 'queued')
            ->count());
    }

    /**
     * poc 断言 [3]-6：AI 合法方向信号必须落 signals(source=ai) 并进入通知分发。
     */
    public function test_successful_ai_signal_persists_source_ai_row(): void
    {
        Http::fake([
            '*deepseek.invalid/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'direction' => 'long',
                        'entry_price' => 100,
                        'exit_price' => 101,
                        'stop_loss' => 99,
                        'confidence' => 72,
                        'rationale' => '规则形态确认，趋势向上',
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ]),
        ]);

        $subscription = $this->makeSubscription('poc-ai-success-990061');
        $bars = $this->deterministicBullishBars('AIOKPHPUNIT');
        $latest = $bars[count($bars) - 1];
        $beforeId = (int) DB::table('signals')->max('id');

        $signal = app(SignalPipeline::class)->run($subscription, $latest, $bars);

        static::assertNotNull($signal);
        static::assertSame('ai', $signal->source);
        static::assertSame('long', $signal->direction);

        $row = DB::table('signals')
            ->where('id', '>', $beforeId)
            ->where('source', 'ai')
            ->latest('id')
            ->first();
        static::assertNotNull($row);
        static::assertSame('AIOKPHPUNIT', $row->symbol);
        static::assertSame(1, DB::table('signal_notifications')
            ->where('signal_id', $row->id)
            ->where('status', 'queued')
            ->count());
    }

    /**
     * poc 断言 [3]-neutral 分支：AI 判断 neutral 时 DTO 合法，pipeline 返回 null 且不落 signals。
     */
    public function test_neutral_ai_signal_is_valid_dto_but_not_persisted(): void
    {
        Http::fake([
            '*deepseek.invalid/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'direction' => 'neutral',
                        'entry_price' => 100,
                        'exit_price' => null,
                        'stop_loss' => null,
                        'confidence' => 40,
                        'rationale' => '多空力量均衡，建议观望',
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ]),
        ]);

        $subscription = $this->makeSubscription('poc-ai-neutral-990061');
        $bars = $this->deterministicBullishBars('AINEUPHPUNIT');
        $latest = $bars[count($bars) - 1];

        $signal = app(SignalPipeline::class)->run($subscription, $latest, $bars);

        static::assertNull($signal);
        static::assertSame(0, DB::table('signals')
            ->where('tenant_id', self::TENANT_ID)
            ->where('symbol', 'AINEUPHPUNIT')
            ->count());
    }

    private function makeSubscription(string $name): object
    {
        $now = Carbon::now();
        $id = (int) DB::table('signal_subscriptions')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'name' => $name,
            'symbols' => json_encode(['BTCUSDT']),
            'intervals' => json_encode(['5m']),
            'ai_prompter' => 'deepseek',
            'ai_model' => null,
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(['log']),
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('signal_subscriptions')->where('id', $id)->first();
    }

    /**
     * 确定性多头形态（120 根 5m：100 平 → 10 根等幅下跌 → 10 根等幅回升，末根金叉 + MACD 红柱）。
     *
     * @return ExchangeKline[]
     */
    private function deterministicBullishBars(string $symbol): array
    {
        $d = 0.2;
        $closes = array_fill(0, 100, 100.0);
        for ($i = 1; $i <= 10; $i++) {
            $closes[] = 100.0 - $i * $d;
        }
        $price = 100.0 - 10 * $d;
        for ($i = 0; $i < 10; $i++) {
            $price += $d;
            $closes[] = $price;
        }

        $base = intdiv(time() * 1000, 300_000) * 300_000 - 120 * 300_000;
        $bars = [];
        foreach ($closes as $i => $c) {
            $bars[] = new ExchangeKline(
                exchangeCode: 'binance',
                symbol: $symbol,
                interval: '5m',
                openTime: $base + $i * 300_000,
                closeTime: $base + $i * 300_000 + 299_999,
                open: (string) $c,
                high: (string) $c,
                low: (string) $c,
                close: (string) $c,
                volume: '10',
                quoteVolume: (string) ($c * 10),
                tradesCount: 5,
            );
        }

        return $bars;
    }
}
