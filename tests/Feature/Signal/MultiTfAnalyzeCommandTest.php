<?php

declare(strict_types=1);

namespace Tests\Feature\Signal;

use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\ExchangeManager;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * MultiTfAnalyzeCommand smoke：mock ExchangeManager + Http::fake，验证 exit code 与输出基本结构。
 */
class MultiTfAnalyzeCommandTest extends TestCase
{
    public function test_command_produces_structured_report(): void
    {
        $adapter = $this->makeAdapterMock([
            '5m'  => $this->makeBars(120, '5m'),
            '15m' => $this->makeBars(120, '15m'),
            '1h'  => $this->makeBars(120, '1h'),
            '4h'  => $this->makeBars(120, '4h'),
        ]);
        $this->mockExchangeManager($adapter);

        Http::fake([
            '*deepseek.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'summary' => '方向不明建议观望',
                        'multi_tf_resonance' => '大周期偏空小周期收敛',
                        'trend' => 'ranging',
                        'conviction' => 42,
                        'plan' => [
                            'action' => 'wait', 'entry_zone' => [100, 101],
                            'stop_loss' => 99, 'take_profit_1' => 102, 'take_profit_2' => 103,
                            'position_size_pct' => 0, 'time_horizon' => '1h',
                            'risk_reward_ratio' => 1.4, 'invalidates_when' => '站稳 105 转多',
                        ],
                        'watchlist' => ['关注 105 压力'],
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ]),
        ]);

        $exitCode = $this->artisan('ai:multitf-analyze', ['symbol' => 'BTCUSDT'])->run();

        $this->assertSame(0, $exitCode);
        Http::assertSentCount(1);  // 验证 AI HTTP 确实被调用
    }

    public function test_json_flag_returns_raw_json(): void
    {
        $adapter = $this->makeAdapterMock([
            '5m' => $this->makeBars(60, '5m'),
        ]);
        $this->mockExchangeManager($adapter);

        Http::fake([
            '*deepseek.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'summary' => 'test', 'multi_tf_resonance' => 'test',
                        'trend' => 'ranging', 'conviction' => 30,
                        'plan' => [
                            'action' => 'wait', 'entry_zone' => [0, 0],
                            'stop_loss' => 0, 'take_profit_1' => 0, 'take_profit_2' => 0,
                            'position_size_pct' => 0, 'time_horizon' => '5m',
                            'risk_reward_ratio' => 1.0, 'invalidates_when' => 'x',
                        ],
                        'watchlist' => [],
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ]),
        ]);

        $exitCode = $this->artisan('ai:multitf-analyze', [
            'symbol' => 'BTCUSDT', '--json' => true, '--tf' => '5m',
        ])->run();

        $this->assertSame(0, $exitCode);
        Http::assertSentCount(1);
    }

    public function test_closed_only_drops_in_progress_bar(): void
    {
        // 5m：最后一根 openTime 对齐当前桶起点 → 模拟进行中 bar
        $adapter = $this->makeAdapterMock([
            '5m' => $this->makeBarsWithInProgress(60, '5m'),
        ]);
        $this->mockExchangeManager($adapter);

        Http::fake([
            '*deepseek.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => json_encode([
                        'summary' => 'closed-only 丢弃进行中 bar 后研判',
                        'multi_tf_resonance' => '单周期',
                        'trend' => 'ranging', 'conviction' => 35,
                        'plan' => [
                            'action' => 'wait', 'entry_zone' => [0, 0],
                            'stop_loss' => 0, 'take_profit_1' => 0, 'take_profit_2' => 0,
                            'position_size_pct' => 0, 'time_horizon' => '5m',
                            'risk_reward_ratio' => 1.0, 'invalidates_when' => 'x',
                        ],
                        'watchlist' => [],
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ]),
        ]);

        $exitCode = $this->artisan('ai:multitf-analyze', [
            'symbol' => 'BTCUSDT', '--closed-only' => true, '--tf' => '5m',
        ])->run();

        $this->assertSame(0, $exitCode);
        Http::assertSentCount(1);

        // 验证传给 AI 的 prompt：closed-only 丢弃后不应有"进行中"提示
        $sent = Http::recorded()->first();
        $this->assertNotNull($sent);
        $sentRequest = $sent[0]; // Illuminate\Http\Client\Request
        $body = $sentRequest->data(); // 请求体数组
        $messages = $body['messages'] ?? [];
        $userContent = $messages[1]['content'] ?? '';
        $this->assertStringContainsString('已闭合', $userContent);
        $this->assertStringNotContainsString('进行中', $userContent);
    }

    private function mockExchangeManager(ExchangeAdapter $adapter): void
    {
        $mgr = Mockery::mock(ExchangeManager::class);
        $mgr->shouldReceive('adapter')->andReturn($adapter);
        $this->app->instance(ExchangeManager::class, $mgr);
    }

    /**
     * @param array<string,ExchangeKline[]> $barsByTf
     */
    private function makeAdapterMock(array $barsByTf): ExchangeAdapter
    {
        $mock = Mockery::mock(ExchangeAdapter::class);
        $mock->shouldReceive('getKline')->andReturnUsing(
            static fn (string $s, string $tf, int $limit = 500) => $barsByTf[$tf] ?? []
        );
        $mock->shouldReceive('getAccountSnapshot')->andReturn([]);
        $mock->shouldReceive('subscribeKlineStream');

        return $mock;
    }

    /**
     * @return ExchangeKline[]
     */
    private function makeBars(int $count, string $interval): array
    {
        $ms = ['5m' => 300_000, '15m' => 900_000, '1h' => 3_600_000, '4h' => 14_400_000][$interval] ?? 300_000;
        $base = intdiv(time() * 1000, $ms) * $ms - $count * $ms;
        $bars = [];
        for ($i = 0; $i < $count; $i++) {
            $c = 100 + sin($i / 10) * 2;
            $bars[] = new ExchangeKline(
                exchangeCode: 'binance', symbol: 'BTCUSDT', interval: $interval,
                openTime: $base + $i * $ms, closeTime: $base + $i * $ms + $ms - 1,
                open: (string) ($c - 0.5), high: (string) ($c + 1), low: (string) ($c - 1),
                close: (string) $c, volume: '100', quoteVolume: (string) ($c * 100), tradesCount: 50,
            );
        }
        return $bars;
    }

    /**
     * 和 makeBars 一样，但最后一根的 openTime 对齐"当前桶起点"（模拟进行中 bar）。
     * 这样 closed-only 逻辑才能命中丢弃分支。
     *
     * @return ExchangeKline[]
     */
    private function makeBarsWithInProgress(int $count, string $interval): array
    {
        $ms = ['5m' => 300_000, '15m' => 900_000, '1h' => 3_600_000, '4h' => 14_400_000][$interval] ?? 300_000;
        // 当前桶起点（按 now 向下对齐）
        $curBucketStart = intdiv((int) round(microtime(true) * 1000), $ms) * $ms;
        // 第一根 openTime：当前桶往前 (count-1) 个周期
        $firstOpen = $curBucketStart - ($count - 1) * $ms;

        $bars = [];
        for ($i = 0; $i < $count; $i++) {
            $openTime = $firstOpen + $i * $ms;
            $closeTime = $openTime + $ms - 1;
            $c = 100 + sin($i / 10) * 2;
            $bars[] = new ExchangeKline(
                exchangeCode: 'binance', symbol: 'BTCUSDT', interval: $interval,
                openTime: $openTime, closeTime: $closeTime,
                open: (string) ($c - 0.5), high: (string) ($c + 1), low: (string) ($c - 1),
                close: (string) $c, volume: '100', quoteVolume: (string) ($c * 100), tradesCount: 50,
            );
        }
        // 确认最后一根 openTime == 当前桶起点
        $lastOpen = $bars[count($bars) - 1]->openTime;
        assert($lastOpen === $curBucketStart, '最后一根 bar 必须对齐当前桶起点才能触发 closed-only 丢弃');

        return $bars;
    }
}
