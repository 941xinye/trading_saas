<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange;

use App\Domain\Exchange\Adapters\OkxAdapter;
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
 * OKX 现货适配器离线断言（US1 MVP）。
 *
 * 全程 Http::fake 无外网；RateLimiter 短路放行（OKX 无 weight_limit_per_minute 配置，
 * limitFor=0 直接返回 true 不触 Redis）；ExchangeHealthChecker::record 是 best-effort
 * 写 SQLite（FK 不可达时 Log::debug 不抛，详见 constitution VI）。
 *
 * 仅 T010 symbolFilters 走 Redis 缓存，单独标 #[Group('redis')]。
 */
class OkxAdapterTest extends TestCase
{
    use ChecksRedisAvailability;

    private const FILTERS_KEY = 'market:symbols:okx:BTCUSDT:filters';

    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        // constitution VI：TTL 键在 setUp/tearDown 都精确清理。
        // Redis 不可用时静默降级（T007-T009 不依赖 Redis，T010 由 requireRedis() 守卫）。
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

    private function adapter(): OkxAdapter
    {
        return new OkxAdapter(new RateLimiter(), new ExchangeHealthChecker(), []);
    }

    // ------------------------------------------------------------------
    // T007 instId 归一化
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function instIdProvider(): array
    {
        return [
            'BTCUSDT → BTC-USDT' => ['BTCUSDT', 'BTC-USDT'],
            'ETHUSDT → ETH-USDT' => ['ETHUSDT', 'ETH-USDT'],
            'SOLUSDT → SOL-USDT' => ['SOLUSDT', 'SOL-USDT'],
            '已含 - 幂等' => ['BTC-USDT', 'BTC-USDT'],
            '空字符串直传' => ['', ''],
            '单字符直传（无 USDT）' => ['B', 'B'],
        ];
    }

    #[DataProvider('instIdProvider')]
    public function test_toOkxInstId_normalizes_platform_symbol(string $input, string $expected): void
    {
        $adapter = $this->adapter();

        $method = new ReflectionMethod($adapter, 'toOkxInstId');
        $method->setAccessible(true);

        static::assertSame($expected, $method->invoke($adapter, $input));
    }

    public function test_toPlatformSymbol_strips_dash_idempotent(): void
    {
        $adapter = $this->adapter();

        $method = new ReflectionMethod($adapter, 'toPlatformSymbol');
        $method->setAccessible(true);

        static::assertSame('BTCUSDT', $method->invoke($adapter, 'BTC-USDT'));
        static::assertSame('BTCUSDT', $method->invoke($adapter, 'BTCUSDT'));
        static::assertSame('', $method->invoke($adapter, ''));
    }

    // ------------------------------------------------------------------
    // T008 getTicker 响应映射
    // ------------------------------------------------------------------

    public function test_getTicker_maps_okx_response_to_dto(): void
    {
        Http::fake([
            '*/api/v5/market/ticker*' => Http::response([
                'code' => '0',
                'msg' => '',
                'data' => [[
                    'last' => '50000',
                    'bidPx' => '49999',
                    'askPx' => '50001',
                    'vol24h' => '1000',
                    'open24h' => '49500',
                    'ts' => '1697443200000',
                    'instId' => 'BTC-USDT',
                ]],
            ]),
        ]);

        $ticker = $this->adapter()->getTicker('BTCUSDT');

        // 字符串字段直传（data-model.md 映射表）
        static::assertSame('BTCUSDT', $ticker->symbol, 'instId 归一化为平台格式 BTCUSDT');
        static::assertSame('50000', $ticker->lastPrice);
        static::assertSame('49999', $ticker->bidPrice);
        static::assertSame('50001', $ticker->askPrice);
        static::assertSame('1000', $ticker->volume24h);
        // timestamp 毫秒整数直传
        static::assertSame(1697443200000, $ticker->timestamp);
        // 数据特征：bid < ask
        static::assertLessThan($ticker->askPrice, $ticker->bidPrice);

        // changePct24h = (last-open24h)/open24h*100（bcmath，constitution II）
        $expected = bcmul(
            bcdiv(bcsub('50000', '49500', 12), '49500', 12),
            '100',
            12,
        );
        static::assertSame($expected, $ticker->changePct24h);
        // 正向涨幅约 1.01%
        static::assertGreaterThan(0, bccomp($ticker->changePct24h, '0', 12));
    }

    // ------------------------------------------------------------------
    // T009 getKline 响应映射
    // ------------------------------------------------------------------

    public function test_getKline_maps_okx_rows_in_ascending_openTime(): void
    {
        Http::fake([
            '*/api/v5/market/candles*' => Http::response([
                'code' => '0',
                'msg' => '',
                'data' => [
                    // OKX REST 默认升序：[ts, O, H, L, C, vol, volCcy, volCcyQuote, confirm, ...]
                    ['1697443200000', '1.0', '1.1', '0.9', '1.05', '100', '100', '105', '1', '0', '0', '0'],
                    ['1697443260000', '1.05', '1.15', '1.0', '1.1', '120', '120', '132', '1', '0', '0', '0'],
                ],
            ]),
        ]);

        $klines = $this->adapter()->getKline('BTCUSDT', '1m', 10);

        static::assertCount(2, $klines);

        // openTime 升序
        static::assertSame(1697443200000, $klines[0]->openTime);
        static::assertSame(1697443260000, $klines[1]->openTime);

        // closeTime = openTime + 60000（1m interval，bcmath 推算）
        static::assertSame(1697443260000, $klines[0]->closeTime);
        static::assertSame(1697443320000, $klines[1]->closeTime);

        // tradesCount=0（OKX 不返回 trades count，data-model.md）
        static::assertSame(0, $klines[0]->tradesCount);
        static::assertSame(0, $klines[1]->tradesCount);

        // 平台格式 + 固定字段
        foreach ($klines as $k) {
            static::assertSame('okx', $k->exchangeCode);
            static::assertSame('BTCUSDT', $k->symbol);
            static::assertSame('1m', $k->interval);
        }

        // 第一根 OHLCV 字段映射：row[1-4]=OHLC、row[5]=vol、row[7]=volCcyQuote
        static::assertSame('1.0', $klines[0]->open);
        static::assertSame('1.1', $klines[0]->high);
        static::assertSame('0.9', $klines[0]->low);
        static::assertSame('1.05', $klines[0]->close);
        static::assertSame('100', $klines[0]->volume);
        static::assertSame('105', $klines[0]->quoteVolume);

        // OHLCV 字符串非空
        foreach (['open', 'high', 'low', 'close', 'volume', 'quoteVolume'] as $field) {
            static::assertNotSame('', (string) $klines[0]->$field, "field {$field} should be non-empty");
        }
    }

    public function test_getKline_caps_limit_to_okx_max_300(): void
    {
        $capturedUrls = [];

        Http::fake(function ($request) use (&$capturedUrls): array {
            $capturedUrls[] = $request->url();

            return ['code' => '0', 'msg' => '', 'data' => []];
        });

        $this->adapter()->getKline('BTCUSDT', '1m', 500);

        // OKX limit 上限 300（contracts/okx-api-contract.md）
        static::assertNotEmpty($capturedUrls);
        static::assertStringContainsString('limit=300', $capturedUrls[0]);
    }

    // ------------------------------------------------------------------
    // T010 symbolFilters 映射与 Redis 缓存
    // ------------------------------------------------------------------

    #[Group('redis')]
    public function test_symbolFilters_maps_and_caches_in_redis(): void
    {
        $this->requireRedis();

        Http::fake([
            '*/api/v5/public/instruments*' => Http::response([
                'code' => '0',
                'msg' => '',
                'data' => [[
                    'tickSz' => '0.01',
                    'lotSz' => '0.0001',
                    'minSz' => '0.001',
                    'instId' => 'BTC-USDT',
                    'instType' => 'SPOT',
                ]],
            ]),
        ]);

        $adapter = $this->adapter();

        // 首次调用：发 HTTP + 写 Redis 缓存
        $filters = $adapter->symbolFilters('BTCUSDT');

        static::assertSame('0.0001', $filters['step_size'], 'lotSz → step_size');
        static::assertSame('0.01', $filters['tick_size'], 'tickSz → tick_size');
        static::assertSame('0.001', $filters['min_notional'], 'minSz → min_notional');

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
