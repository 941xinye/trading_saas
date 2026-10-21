<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange;

use App\Domain\Exchange\Adapters\OkxAdapter;
use App\Domain\Exchange\Exceptions\ExchangeApiException;
use App\Domain\Exchange\ExchangeHealthChecker;
use App\Domain\Exchange\RateLimiter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Support\RedisCleanup;
use Tests\TestCase;

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
    use RedisCleanup;

    private const FILTERS_KEY = 'market:symbols:okx:BTCUSDT:filters';

    protected function setUp(): void
    {
        parent::setUp();

        // constitution VI：TTL 键在 setUp/tearDown 都精确清理。
        // Redis 不可用时 trait 内部静默降级（T007-T009 不依赖 Redis，T010 由 requireRedis() 守卫）。
        $this->cleanupRedis([self::FILTERS_KEY]);
    }

    protected function tearDown(): void
    {
        // constitution VI：外部 IO 清理先于 parent::tearDown()。
        $this->cleanupRedis([self::FILTERS_KEY]);

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

    // ------------------------------------------------------------------
    // T016/T017 WS K 线 payload 映射（US2）
    // ------------------------------------------------------------------

    /**
     * OKX WS 推送 data[0] 样本（参考 contracts/okx-api-contract.md）：
     * [ts, O, H, L, C, vol, volCcy, volCcyQuote, confirm, ...]
     */
    public static function wsKlinePayloadProvider(): array
    {
        return [
            'confirm=1 bar close' => [
                // row 12 字段，data[0] 中 OKX 实际推送
                ['1697443200000', '0.001', '0.002', '0.0005', '0.0015', '100', '0.1', '1', '1', '0', '0', '0'],
                'BTC-USDT',
                'candle1m',
                1, // confirm
            ],
            'confirm=0 mid bar' => [
                ['1697443200000', '0.001', '0.002', '0.0005', '0.0015', '100', '0.1', '1', '0', '0', '0', '0'],
                'BTC-USDT',
                'candle1m',
                0,
            ],
        ];
    }

    #[DataProvider('wsKlinePayloadProvider')]
    public function test_mapWsKlinePayload_maps_okx_ws_row_to_dto(
        array $row,
        string $okxInstId,
        string $channel,
        int $confirm,
    ): void {
        $adapter = $this->adapter();

        $method = new ReflectionMethod($adapter, 'mapWsKlinePayload');
        $method->setAccessible(true);

        $kline = $method->invoke($adapter, $row, $okxInstId, $channel);

        // 平台格式 symbol（去 -）
        static::assertSame('BTCUSDT', $kline->symbol, 'instId 归一化为平台格式');
        // exchangeCode/interval 固定
        static::assertSame('okx', $kline->exchangeCode);
        static::assertSame('1m', $kline->interval, 'channel candle1m 去前缀 candle 得 1m');

        // 时间字段：openTime 直传，closeTime=openTime+60000（1m intervalMs 推算）
        static::assertSame(1697443200000, $kline->openTime);
        static::assertSame(1697443260000, $kline->closeTime);

        // OHLCV 字段映射：[1-4]=OHLC、[5]=vol、[7]=volCcyQuote
        static::assertSame('0.001', $kline->open);
        static::assertSame('0.002', $kline->high);
        static::assertSame('0.0005', $kline->low);
        static::assertSame('0.0015', $kline->close);
        static::assertSame('100', $kline->volume);
        static::assertSame('1', $kline->quoteVolume);

        // tradesCount=0（OKX 不返回 trades count，data-model.md）
        static::assertSame(0, $kline->tradesCount);

        // OHLCV 字符串非空
        foreach (['open', 'high', 'low', 'close', 'volume', 'quoteVolume'] as $field) {
            static::assertNotSame('', (string) $kline->$field, "field {$field} should be non-empty");
        }
    }

    /**
     * T017：confirm 标志位（row[8]）决定 onBar/onTick 触发分支。
     *
     * 真实 WS 触发分支需网络手测（无网络环境无法起 WsClient）；本测试以纯函数
     * 方式验证 mapWsKlinePayload 对 confirm=0 与 confirm=1 的 row 都能正确映射，
     * 且 confirm 字段不进入 DTO（两 row 映射结果除时间戳外完全一致）。
     *
     * 标 #[Group('ws-integration')] 跳 CI，手动验证用——onBar/onTick 触发分支
     * 需要起 runKlineWsLoop 接 wspap.okx.com:8443 公共流实测。
     */
    #[Group('ws-integration')]
    public function test_confirm_flag_does_not_affect_dto_mapping(): void
    {
        $adapter = $this->adapter();

        $method = new ReflectionMethod($adapter, 'mapWsKlinePayload');
        $method->setAccessible(true);

        $rowBase = ['1697443200000', '0.001', '0.002', '0.0005', '0.0015', '100', '0.1', '1'];
        $rowClosed = array_merge($rowBase, ['1', '0', '0', '0']); // confirm=1
        $rowMid = array_merge($rowBase, ['0', '0', '0', '0']); // confirm=0

        $closed = $method->invoke($adapter, $rowClosed, 'BTC-USDT', 'candle1m');
        $mid = $method->invoke($adapter, $rowMid, 'BTC-USDT', 'candle1m');

        // confirm 字段不入 DTO——除确认位外两 row 字段相同，DTO 完全一致
        static::assertSame($closed->exchangeCode, $mid->exchangeCode);
        static::assertSame($closed->symbol, $mid->symbol);
        static::assertSame($closed->interval, $mid->interval);
        static::assertSame($closed->openTime, $mid->openTime);
        static::assertSame($closed->closeTime, $mid->closeTime);
        static::assertSame($closed->open, $mid->open);
        static::assertSame($closed->high, $mid->high);
        static::assertSame($closed->low, $mid->low);
        static::assertSame($closed->close, $mid->close);
        static::assertSame($closed->volume, $mid->volume);
        static::assertSame($closed->quoteVolume, $mid->quoteVolume);
        static::assertSame($closed->tradesCount, $mid->tradesCount);

        // 标记说明：onBar 触发分支（confirm=1）与 onTick 触发分支（confirm=0）
        // 在 runKlineWsLoop 内判断，本测试不覆盖，需手测真实 WS 接 OKX 公共流
        static::markTestIncomplete(
            'onBar/onTick 触发分支需手测真实 WS（wspap.okx.com:8443/ws/v5/public）；'
            . '本测试只覆盖 mapWsKlinePayload 的 DTO 映射纯函数行为'
        );
    }

    // ------------------------------------------------------------------
    // T022 passphrase 守卫（US3）
    // ------------------------------------------------------------------

    public function test_passphrase_missing_guard_throws_before_http(): void
    {
        // 构造 credentials 仅含 api_key/api_secret 无 passphrase
        $adapter = new OkxAdapter(new RateLimiter(), new ExchangeHealthChecker(), [
            'api_key' => 'k',
            'api_secret' => 's',
            // 故意缺 passphrase
        ]);

        // 任何请求都视为意外（fake 空响应）；守卫应在前置抛错，不应触发任何请求
        Http::fake([]);

        try {
            $adapter->getBalance();
            static::fail('预期抛 ExchangeApiException::clientError（passphrase 缺失守卫）');
        } catch (ExchangeApiException $e) {
            // FR-007：前置失败不占额度，retryable=false（硬失败）
            static::assertStringContainsString('passphrase', strtolower($e->getMessage()));
            static::assertFalse($e->isRetryable(), 'passphrase 缺失属硬失败，不重试');
        }

        Http::assertSentCount(0);
        // passphrase 守卫前置抛错，不应发起任何 HTTP（不占额度，FR-007）
    }

    // ------------------------------------------------------------------
    // T023 mapOrderStatus 状态映射（US3）
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function okxOrderStatusProvider(): array
    {
        return [
            'live → submitted' => ['live', 'submitted'],
            'partially_filled → partial' => ['partially_filled', 'partial'],
            'filled → filled' => ['filled', 'filled'],
            'canceled → canceled' => ['canceled', 'canceled'],
            'mmp_canceled → failed' => ['mmp_canceled', 'failed'],
            '空字符串 → failed' => ['', 'failed'],
        ];
    }

    #[DataProvider('okxOrderStatusProvider')]
    public function test_map_order_status_maps_okx_state_to_platform_enum(string $okxState, string $expected): void
    {
        $adapter = $this->adapter();

        $method = new ReflectionMethod($adapter, 'mapOrderStatus');
        $method->setAccessible(true);

        static::assertSame($expected, $method->invoke($adapter, $okxState));
    }

    // ------------------------------------------------------------------
    // T024 placeOrder 响应映射（US3）
    // ------------------------------------------------------------------

    public function test_place_order_maps_okx_response_to_dto(): void
    {
        Http::fake([
            '*/api/v5/trade/order*' => Http::response([
                'code' => '0',
                'msg' => '',
                'data' => [[
                    'ordId' => '1234567890',
                    'clOrdId' => '1_1_1697443200_abcd1234',
                    'state' => 'live',
                    'side' => 'buy',
                    'ordType' => 'limit',
                    'sz' => '0.001',
                    'px' => '50000',
                    'accFillSz' => '0',
                    'accFillPx' => '0',
                    'fee' => '0',
                    'cTime' => '1697443200000',
                    'uTime' => '1697443200000',
                ]],
            ]),
        ]);

        $adapter = new OkxAdapter(new RateLimiter(), new ExchangeHealthChecker(), [
            'api_key' => 'k',
            'api_secret' => 's',
            'passphrase' => 'p',
        ]);

        $order = $adapter->placeOrder(
            symbol: 'BTCUSDT',
            side: 'buy',
            type: 'limit',
            quantity: '0.001',
            price: '50000',
            opts: ['clientOrderId' => '1_1_1697443200_abcd1234'],
        );

        // ordId 透传
        static::assertSame('1234567890', $order->exchangeOrderId);
        // clientOrderId 与传入一致（constitution II 模板）
        static::assertSame('1_1_1697443200_abcd1234', $order->clientOrderId);
        // state=live → submitted
        static::assertSame('submitted', $order->status);
        // side/type lower
        static::assertSame('buy', $order->side);
        static::assertSame('limit', $order->type);
        // symbol 平台格式
        static::assertSame('BTCUSDT', $order->symbol);
        // sz 透传
        static::assertSame('0.001', $order->quantity);
        // px 透传
        static::assertSame('50000', $order->price);
        // placedAt = cTime/1000（ms→s）
        static::assertSame(1697443200, $order->placedAt);
        // 现货无杠杆
        static::assertNull($order->leverage);
        static::assertFalse($order->reduceOnly);
    }

    // ------------------------------------------------------------------
    // T025 getOrderByClientOrderId 订单不存在返 null（US3）
    // ------------------------------------------------------------------

    public function test_get_order_by_client_order_id_returns_null_on_not_found(): void
    {
        // OKX 订单不存在错误响应（参考 contracts/okx-api-contract.md + research.md R-006）
        Http::fake([
            '*/api/v5/trade/order*' => Http::response([
                'code' => '51404',
                'msg' => 'Order does not exist',
                'data' => [],
            ], 200),
        ]);

        $adapter = new OkxAdapter(new RateLimiter(), new ExchangeHealthChecker(), [
            'api_key' => 'k',
            'api_secret' => 's',
            'passphrase' => 'p',
        ]);

        $order = $adapter->getOrderByClientOrderId('BTCUSDT', '1_1_1697443200_abcd1234');

        // spec FR-006 + constitution IV：崩溃恢复幂等查询，查无此单返回 null（不抛异常）
        static::assertNull($order, 'OKX 订单不存在错误码应返回 null 而非抛异常');
    }

    // ------------------------------------------------------------------
    // T033-T035 US4 模拟盘环境开关
    // ------------------------------------------------------------------

    /**
     * T033：testnet=true 时签名请求同时注入 x-simulated-trading:1 与 OK-ACCESS-* 四件套。
     *
     * spec FR-003 + research.md R-005：OKX 模拟盘 REST 同域，靠 header 区分；
     * 四件套证明 signed 路径生效；x-simulated-trading 证明 testnet 开关生效。
     */
    public function test_testnet_switch_injects_simulated_trading_header(): void
    {
        config(['exchange.exchanges.okx.testnet' => true]);

        $capturedHeaders = [];
        Http::fake(function ($request) use (&$capturedHeaders): array {
            $capturedHeaders = $request->headers();

            return [
                'code' => '0',
                'msg' => '',
                'data' => [[
                    'totalEq' => '1000',
                    'margin' => '0',
                    'upl' => '0',
                    'details' => [],
                ]],
            ];
        });

        $adapter = new OkxAdapter(new RateLimiter(), new ExchangeHealthChecker(), [
            'api_key' => 'k',
            'api_secret' => 's',
            'passphrase' => 'p',
        ]);

        $adapter->getBalance();

        Http::assertSentCount(1);

        // 模拟盘标识（FR-003）
        static::assertSame(
            ['1'],
            $capturedHeaders['x-simulated-trading'] ?? null,
            'testnet=true 必须注入 x-simulated-trading: 1'
        );

        // OK-ACCESS-* 四件套（signed 路径生效）
        foreach (['OK-ACCESS-KEY', 'OK-ACCESS-SIGN', 'OK-ACCESS-TIMESTAMP', 'OK-ACCESS-PASSPHRASE'] as $name) {
            static::assertArrayHasKey($name, $capturedHeaders, "缺少签名头 {$name}");
            static::assertNotEmpty($capturedHeaders[$name][0] ?? '', "签名头 {$name} 不应为空");
        }

        static::assertSame(['k'], $capturedHeaders['OK-ACCESS-KEY']);
        static::assertSame(['p'], $capturedHeaders['OK-ACCESS-PASSPHRASE']);
        // ISO 8601 UTC 时间戳（research.md R-003）
        static::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $capturedHeaders['OK-ACCESS-TIMESTAMP'][0],
            'OK-ACCESS-TIMESTAMP 必须是 ISO 8601 UTC 毫秒格式'
        );
    }

    /**
     * T034：testnet=true 时 wsBaseUrl() 路由到 wspap 模拟盘 WS。
     *
     * spec FR-003 + research.md R-001：WS 公共流模拟盘独立域名 wspap.okx.com:8443。
     */
    public function test_testnet_switch_routes_ws_url_to_wspap(): void
    {
        config(['exchange.exchanges.okx.testnet' => true]);

        $adapter = $this->adapter();

        $method = new ReflectionMethod($adapter, 'wsBaseUrl');
        $method->setAccessible(true);
        $url = $method->invoke($adapter);

        static::assertSame(
            'wss://wspap.okx.com:8443/ws/v5/public',
            $url,
            'testnet=true 应路由到 OKX 模拟盘 wspap 公共 WS'
        );
    }

    /**
     * T035：testnet=false 时签名请求不带 x-simulated-trading，wsBaseUrl() 走生产 ws.okx.com。
     *
     * spec FR-003：生产环境不携带模拟盘标识，WS 连生产公共流。
     */
    public function test_production_switch_omits_simulated_header_and_uses_production_ws(): void
    {
        config(['exchange.exchanges.okx.testnet' => false]);

        $capturedHeaders = [];
        Http::fake(function ($request) use (&$capturedHeaders): array {
            $capturedHeaders = $request->headers();

            return [
                'code' => '0',
                'msg' => '',
                'data' => [[
                    'totalEq' => '1000',
                    'margin' => '0',
                    'upl' => '0',
                    'details' => [],
                ]],
            ];
        });

        $adapter = new OkxAdapter(new RateLimiter(), new ExchangeHealthChecker(), [
            'api_key' => 'k',
            'api_secret' => 's',
            'passphrase' => 'p',
        ]);

        $adapter->getBalance();

        Http::assertSentCount(1);

        // 生产环境不得携带模拟盘标识（FR-003）
        static::assertArrayNotHasKey(
            'x-simulated-trading',
            $capturedHeaders,
            'testnet=false 不应注入 x-simulated-trading header'
        );

        // 但签名四件套仍应存在（signed 路径依然生效）
        foreach (['OK-ACCESS-KEY', 'OK-ACCESS-SIGN', 'OK-ACCESS-TIMESTAMP', 'OK-ACCESS-PASSPHRASE'] as $name) {
            static::assertArrayHasKey($name, $capturedHeaders, "生产环境签名头 {$name} 必须存在");
        }

        // wsBaseUrl() 走生产 ws.okx.com
        $method = new ReflectionMethod($adapter, 'wsBaseUrl');
        $method->setAccessible(true);
        $url = $method->invoke($adapter);

        static::assertSame(
            'wss://ws.okx.com:8443/ws/v5/public',
            $url,
            'testnet=false 应路由到 OKX 生产公共 WS'
        );
    }

    /**
     * C4（speckit-analyze 补）：FR-004 现货适配器调用 subscribeLiquidationStream
     * 必须抛 LogicException —— 强平流是合约专属能力，现货适配器不应提供。
     */
    public function test_subscribe_liquidation_stream_throws_logic_exception(): void
    {
        $adapter = $this->adapter();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Liquidation stream is futures-only');

        $adapter->subscribeLiquidationStream(static function (): void {});
    }
}
