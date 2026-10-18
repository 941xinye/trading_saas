<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange;

use App\Domain\Exchange\Adapters\BitgetAdapter;
use App\Domain\Exchange\Exceptions\ExchangeApiException;
use App\Domain\Exchange\ExchangeHealthChecker;
use App\Domain\Exchange\RateLimiter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\TestCase;
use Throwable;

/**
 * Bitget 现货适配器离线断言（US1 REST 行情 / US2 WS / US3 签名下单 / US4 无模拟盘边界）。
 *
 * 全程 Http::fake 无外网（constitution VI + spec US4 场景2）；RateLimiter 短路放行
 * （weight_limit_per_minute=0，limitFor=0 直接 true 不触 Redis）；Bitget 现货无公开
 * 模拟盘（决定 A / FR-013），真实下单验收 = 离线断言全覆盖 + 主网小额单待 key 补测。
 *
 * 仅 symbolFilters 走 Redis 缓存，单独标 #[Group('redis')]。
 */
class BitgetAdapterTest extends TestCase
{
    use ChecksRedisAvailability;

    private const FILTERS_KEY = 'market:symbols:bitget:BTCUSDT:filters';

    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        // constitution VI：TTL 键在 setUp/tearDown 都精确清理。
        // Redis 不可用时静默降级（非 redis 组测试不依赖 Redis，T007 由 requireRedis() 守卫）。
        try {
            Redis::connection('default')->del(self::FILTERS_KEY);
            $this->redisAvailable = true;
        } catch (Throwable) {
            $this->redisAvailable = false;
        }
    }

    protected function tearDown(): void
    {
        // constitution VI：外部 IO 清理先于 parent::tearDown()，可用性标志 + try/catch。
        if ($this->redisAvailable) {
            try {
                Redis::connection('default')->del(self::FILTERS_KEY);
            } catch (Throwable) {
            }
        }

        parent::tearDown();
    }

    private function adapter(): BitgetAdapter
    {
        // RateLimiter 短路放行（Bitget 生产配置 weight_limit_per_minute=600 会触 Redis）
        config(['exchange.exchanges.bitget.weight_limit_per_minute' => 0]);

        return new BitgetAdapter(new RateLimiter(), new ExchangeHealthChecker(), []);
    }

    /**
     * 带三段凭据的适配器（US3 签名测试用；Bitget 与 OKX 同构含 passphrase——FR-006）。
     */
    private function signedAdapter(): BitgetAdapter
    {
        config(['exchange.exchanges.bitget.weight_limit_per_minute' => 0]);

        return new BitgetAdapter(new RateLimiter(), new ExchangeHealthChecker(), [
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
            'passphrase' => 'test-passphrase',
        ]);
    }

    // ------------------------------------------------------------------
    // T004 interval 双形态映射（R-004：REST granularity / WS candle 频道）
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function bitgetGranularityProvider(): array
    {
        return [
            '1m → 1min' => ['1m', '1min'],
            '5m → 5min' => ['5m', '5min'],
            '15m → 15min' => ['15m', '15min'],
            '30m → 30min' => ['30m', '30min'],
            '1h → 1h' => ['1h', '1h'],
            '4h → 4h' => ['4h', '4h'],
            '1d → 1day' => ['1d', '1day'],
        ];
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function bitgetCandleChannelProvider(): array
    {
        return [
            '1m → candle1m' => ['1m', 'candle1m'],
            '5m → candle5m' => ['5m', 'candle5m'],
            '15m → candle15m' => ['15m', 'candle15m'],
            '30m → candle30m' => ['30m', 'candle30m'],
            '1h → candle1h' => ['1h', 'candle1h'],
            '4h → candle4h' => ['4h', 'candle4h'],
            '1d → candle1day' => ['1d', 'candle1day'],
        ];
    }

    #[DataProvider('bitgetGranularityProvider')]
    public function test_to_bitget_granularity_maps_platform_interval(string $input, string $expected): void
    {
        $method = new ReflectionMethod($this->adapter(), 'toBitgetGranularity');
        $method->setAccessible(true);

        static::assertSame($expected, $method->invoke($this->adapter(), $input));
    }

    #[DataProvider('bitgetCandleChannelProvider')]
    public function test_to_bitget_candle_channel_maps_platform_interval(string $input, string $expected): void
    {
        $method = new ReflectionMethod($this->adapter(), 'toBitgetCandleChannel');
        $method->setAccessible(true);

        static::assertSame($expected, $method->invoke($this->adapter(), $input));
    }

    public function test_to_bitget_granularity_rejects_out_of_range_input(): void
    {
        $adapter = $this->adapter();

        $granularity = new ReflectionMethod($adapter, 'toBitgetGranularity');
        $granularity->setAccessible(true);
        $channel = new ReflectionMethod($adapter, 'toBitgetCandleChannel');
        $channel->setAccessible(true);

        // 3m/2h 候选不支持（R-004），7m/空串非法
        foreach (['3m', '2h', '7m', ''] as $invalid) {
            try {
                $granularity->invoke($adapter, $invalid);
                static::fail("预期越界 interval [{$invalid}] 抛 ExchangeApiException");
            } catch (ExchangeApiException $e) {
                static::assertFalse($e->isRetryable(), '越界 interval 为 clientError 硬失败');
            }

            try {
                $channel->invoke($adapter, $invalid);
                static::fail("预期越界 interval [{$invalid}] 抛 ExchangeApiException（WS 形态）");
            } catch (ExchangeApiException) {
                static::assertTrue(true);
            }
        }
    }

    // ------------------------------------------------------------------
    // T005 getTicker 响应映射（FR-011 symbol 直传）
    // ------------------------------------------------------------------

    public function test_get_ticker_maps_response(): void
    {
        // contracts/bitget-api-contract.md ticker 样例（字段名候选待核）
        Http::fake([
            '*/api/v2/spot/market/tickers*' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [[
                    'symbol' => 'BTCUSDT',
                    'lastPr' => '65000.1',
                    'bidPr' => '65000.0',
                    'askPr' => '65000.2',
                    'baseVolume' => '1234.567',
                    'quoteVolume' => '80000000.5',
                    'change24h' => '0.0123',
                    'ts' => '1697443200000',
                ]],
            ]),
        ]);

        $ticker = $this->adapter()->getTicker('BTCUSDT');

        // 字符串字段直传（data-model.md ExchangeTicker 表）
        static::assertSame('BTCUSDT', $ticker->symbol, 'symbol 直传无分隔符转换（FR-011）');
        static::assertSame('65000.1', $ticker->lastPrice, 'lastPr → lastPrice');
        static::assertSame('65000.0', $ticker->bidPrice, 'bidPr → bidPrice');
        static::assertSame('65000.2', $ticker->askPrice, 'askPr → askPrice');
        static::assertSame('1234.567', $ticker->volume24h, 'baseVolume → volume24h');
        static::assertSame('0.0123', $ticker->changePct24h, 'change24h 直传不计算');
        // timestamp 毫秒整数
        static::assertSame(1697443200000, $ticker->timestamp);
        // 数据特征：bid < ask
        static::assertLessThan($ticker->askPrice, $ticker->bidPrice);
    }

    // ------------------------------------------------------------------
    // T006 getKline 响应映射（R-011 排序后升序，不盲信原始方向）
    // ------------------------------------------------------------------

    public function test_get_kline_maps_response_sorted_ascending(): void
    {
        // contracts kline 样例：data 元素为数组 [ts,open,high,low,close,baseVolume,quoteVolume]
        $bars = [
            ['1697443260000', '65050', '65200', '65000', '65100', '10.0', '651000'],
            ['1697443200000', '65000', '65100', '64900', '65050', '12.5', '812500'],
        ];

        // 分别喂倒序与升序两种原始方向（R-011：排序方向候选待核，适配器归一化）
        foreach (['descending' => $bars, 'ascending' => array_reverse($bars)] as $case => $rawRows) {
            Http::fake([
                '*/api/v2/spot/market/candles*' => Http::response([
                    'code' => '00000',
                    'msg' => 'success',
                    'data' => $rawRows,
                ]),
            ]);

            $klines = $this->adapter()->getKline('BTCUSDT', '1m', 10);

            static::assertCount(2, $klines, "case {$case}");

            // 均被归一化为 openTime 升序（R-011）
            static::assertSame(1697443200000, $klines[0]->openTime, "case {$case}：须升序输出");
            static::assertSame(1697443260000, $klines[1]->openTime, "case {$case}：须升序输出");

            // closeTime = openTime + 60000（1m interval，bcmath 推算）
            static::assertSame(1697443260000, $klines[0]->closeTime, "case {$case}");
            static::assertSame(1697443320000, $klines[1]->closeTime, "case {$case}");

            // tradesCount=0（Bitget 不返回 trades count）
            static::assertSame(0, $klines[0]->tradesCount, "case {$case}");

            // 平台格式 + 固定字段
            foreach ($klines as $k) {
                static::assertSame('bitget', $k->exchangeCode, "case {$case}");
                static::assertSame('BTCUSDT', $k->symbol, "case {$case}");
                static::assertSame('1m', $k->interval, "case {$case}");
            }

            // 第一根 OHLCV 字段映射：[1-4]=OHLC、[5]=volume、[6]=quoteVolume
            static::assertSame('65000', $klines[0]->open, "case {$case}");
            static::assertSame('65100', $klines[0]->high, "case {$case}");
            static::assertSame('64900', $klines[0]->low, "case {$case}");
            static::assertSame('65050', $klines[0]->close, "case {$case}");
            static::assertSame('12.5', $klines[0]->volume, "case {$case}");
            static::assertSame('812500', $klines[0]->quoteVolume, "case {$case}：quoteVolume=[6]");

            // OHLCV 字符串非空
            foreach (['open', 'high', 'low', 'close', 'volume', 'quoteVolume'] as $field) {
                static::assertNotSame('', (string) $klines[0]->$field, "case {$case}：field {$field} non-empty");
            }
        }
    }

    // ------------------------------------------------------------------
    // T007 symbolFilters 映射与 Redis 缓存
    // ------------------------------------------------------------------

    #[Group('redis')]
    public function test_symbol_filters_maps_and_caches(): void
    {
        $this->requireRedis();

        // /api/v2/spot/public/symbols 响应（字段名候选待核：pricePrecision/quantityPrecision
        // 为小数位而非步长，实现期对照官方文档核对，数据驱动改值不改结构）
        Http::fake([
            '*/api/v2/spot/public/symbols*' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [[
                    'symbol' => 'BTCUSDT',
                    'pricePrecision' => 2,
                    'quantityPrecision' => 6,
                    'minTradeUSDT' => '5',
                ]],
            ]),
        ]);

        $adapter = $this->adapter();

        // 首次调用：发 HTTP + 写 Redis 缓存
        $filters = $adapter->symbolFilters('BTCUSDT');

        static::assertSame('0.000001', $filters['step_size'], 'quantityPrecision=6 → step 0.000001');
        static::assertSame('0.01', $filters['tick_size'], 'pricePrecision=2 → tick 0.01');
        static::assertSame('5', $filters['min_notional'], 'minTradeUSDT → min_notional');

        $cached = Redis::connection('default')->get(self::FILTERS_KEY);
        static::assertIsString($cached, 'Redis 缓存 key 已写入');
        $decoded = json_decode((string) $cached, true);
        static::assertSame($filters, $decoded, '缓存内容与返回值一致');

        Http::assertSentCount(1);

        // 二次调用：命中缓存，不再发 HTTP
        $adapter->symbolFilters('BTCUSDT');

        Http::assertSentCount(1);
    }
}
