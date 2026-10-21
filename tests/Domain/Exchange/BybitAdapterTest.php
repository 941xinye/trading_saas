<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange;

use App\Domain\Exchange\Adapters\BybitAdapter;
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
 * Bybit 现货适配器离线断言（US1 REST 行情 / US2 WS / US3 签名下单 / US4 testnet）。
 *
 * 全程 Http::fake 无外网（constitution VI + clarify Q4）；RateLimiter 短路放行
 * （adapter() 工厂内置 weight_limit_per_minute=0，limitFor=0 直接 true 不触 Redis；
 * 生产配置 600/5s 独立于测试）；ExchangeHealthChecker::record 为 best-effort。
 *
 * 仅 symbolFilters 走 Redis 缓存，单独标 #[Group('redis')]。
 */
class BybitAdapterTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private const FILTERS_KEY = 'market:symbols:bybit:BTCUSDT:filters';

    protected function setUp(): void
    {
        parent::setUp();

        // constitution VI：TTL 键在 setUp/tearDown 都精确清理。
        // Redis 不可用时 trait 内部静默降级（非 redis 组测试不依赖 Redis，T009 由 requireRedis() 守卫）。
        $this->cleanupRedis([self::FILTERS_KEY]);
    }

    protected function tearDown(): void
    {
        // constitution VI：外部 IO 清理先于 parent::tearDown()。
        $this->cleanupRedis([self::FILTERS_KEY]);

        parent::tearDown();
    }

    private function adapter(): BybitAdapter
    {
        // RateLimiter 短路放行（Bybit 生产配置 weight_limit_per_minute=600 会触 Redis）
        config(['exchange.exchanges.bybit.weight_limit_per_minute' => 0]);

        return new BybitAdapter(new RateLimiter(), new ExchangeHealthChecker(), []);
    }

    /**
     * 带两段凭据的适配器（US3 签名测试用；无 passphrase——FR-007）。
     */
    private function signedAdapter(): BybitAdapter
    {
        config(['exchange.exchanges.bybit.weight_limit_per_minute' => 0]);

        return new BybitAdapter(new RateLimiter(), new ExchangeHealthChecker(), [
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
        ]);
    }

    // ------------------------------------------------------------------
    // T006 interval 映射（R-004）
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function bybitIntervalProvider(): array
    {
        return [
            '1m → 1' => ['1m', '1'],
            '3m → 3' => ['3m', '3'],
            '5m → 5' => ['5m', '5'],
            '15m → 15' => ['15m', '15'],
            '30m → 30' => ['30m', '30'],
            '1h → 60' => ['1h', '60'],
            '2h → 120' => ['2h', '120'],
            '4h → 240' => ['4h', '240'],
            '6h → 360' => ['6h', '360'],
            '12h → 720' => ['12h', '720'],
            '1d → D' => ['1d', 'D'],
            '1w → W' => ['1w', 'W'],
        ];
    }

    #[DataProvider('bybitIntervalProvider')]
    public function test_to_bybit_interval_maps_platform_interval(string $input, string $expected): void
    {
        $adapter = $this->adapter();

        $method = new ReflectionMethod($adapter, 'toBybitInterval');
        $method->setAccessible(true);

        static::assertSame($expected, $method->invoke($adapter, $input));
    }

    public function test_to_bybit_interval_rejects_out_of_range_input(): void
    {
        $adapter = $this->adapter();

        $method = new ReflectionMethod($adapter, 'toBybitInterval');
        $method->setAccessible(true);

        foreach (['7m', '1M', ''] as $invalid) {
            try {
                $method->invoke($adapter, $invalid);
                static::fail("预期越界 interval [{$invalid}] 抛 ExchangeApiException");
            } catch (ExchangeApiException) {
                static::assertTrue(true);
            }
        }
    }

    // ------------------------------------------------------------------
    // T007 getTicker 响应映射（FR-012 symbol 直传）
    // ------------------------------------------------------------------

    public function test_get_ticker_maps_response(): void
    {
        // contracts/bybit-api-contract.md ticker 样例
        Http::fake([
            '*/v5/market/tickers*' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => ['category' => 'spot', 'list' => [[
                    'symbol' => 'BTCUSDT',
                    'lastPrice' => '65000.1',
                    'bid1Price' => '65000.0',
                    'ask1Price' => '65000.2',
                    'volume24h' => '1234.567',
                    'turnover24h' => '80000000.5',
                    'price24hPcnt' => '0.0123',
                ]]],
                'time' => 1697443200000,
            ]),
        ]);

        $ticker = $this->adapter()->getTicker('BTCUSDT');

        // 字符串字段直传（data-model.md ExchangeTicker 表）
        static::assertSame('BTCUSDT', $ticker->symbol, 'symbol 直传无分隔符转换（FR-012）');
        static::assertSame('65000.1', $ticker->lastPrice);
        static::assertSame('65000.0', $ticker->bidPrice, 'bid1Price → bidPrice');
        static::assertSame('65000.2', $ticker->askPrice, 'ask1Price → askPrice');
        static::assertSame('1234.567', $ticker->volume24h);
        static::assertSame('0.0123', $ticker->changePct24h, 'price24hPcnt 直传不计算');
        // timestamp 毫秒整数（顶层 time）
        static::assertSame(1697443200000, $ticker->timestamp);
        // 数据特征：bid < ask
        static::assertLessThan($ticker->askPrice, $ticker->bidPrice);
    }

    // ------------------------------------------------------------------
    // T008 getKline 响应映射（R-012 倒序反转）
    // ------------------------------------------------------------------

    public function test_get_kline_maps_response_reversed_to_ascending(): void
    {
        // contracts kline 样例：result.list 倒序（最新在前），元素
        // [startTime, open, high, low, close, volume, turnover]
        Http::fake([
            '*/v5/market/kline*' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => ['category' => 'spot', 'symbol' => 'BTCUSDT', 'list' => [
                    ['1697443260000', '65050', '65200', '65000', '65100', '10.0', '651000'],
                    ['1697443200000', '65000', '65100', '64900', '65050', '12.5', '812500'],
                ]],
                'time' => 1697443200001,
            ]),
        ]);

        $klines = $this->adapter()->getKline('BTCUSDT', '1m', 10);

        static::assertCount(2, $klines);

        // 反转为 openTime 升序（R-012）
        static::assertSame(1697443200000, $klines[0]->openTime, 'v5 倒序须反转为升序');
        static::assertSame(1697443260000, $klines[1]->openTime);

        // closeTime = openTime + 60000（1m interval，bcmath 推算）
        static::assertSame(1697443260000, $klines[0]->closeTime);
        static::assertSame(1697443320000, $klines[1]->closeTime);

        // tradesCount=0（v5 不返回 trades count）
        static::assertSame(0, $klines[0]->tradesCount);
        static::assertSame(0, $klines[1]->tradesCount);

        // 平台格式 + 固定字段
        foreach ($klines as $k) {
            static::assertSame('bybit', $k->exchangeCode);
            static::assertSame('BTCUSDT', $k->symbol);
            static::assertSame('1m', $k->interval);
        }

        // 第一根 OHLCV 字段映射：[1-4]=OHLC、[5]=volume、[6]=turnover→quoteVolume
        static::assertSame('65000', $klines[0]->open);
        static::assertSame('65100', $klines[0]->high);
        static::assertSame('64900', $klines[0]->low);
        static::assertSame('65050', $klines[0]->close);
        static::assertSame('12.5', $klines[0]->volume);
        static::assertSame('812500', $klines[0]->quoteVolume, 'turnover → quoteVolume');

        // OHLCV 字符串非空
        foreach (['open', 'high', 'low', 'close', 'volume', 'quoteVolume'] as $field) {
            static::assertNotSame('', (string) $klines[0]->$field, "field {$field} should be non-empty");
        }
    }

    // ------------------------------------------------------------------
    // T009 symbolFilters 映射与 Redis 缓存
    // ------------------------------------------------------------------

    #[Group('redis')]
    public function test_symbol_filters_maps_and_caches(): void
    {
        $this->requireRedis();

        // /v5/market/instruments-info 真实响应结构（v5 spot）
        Http::fake([
            '*/v5/market/instruments-info*' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => ['category' => 'spot', 'list' => [[
                    'symbol' => 'BTCUSDT',
                    'priceFilter' => ['tickSize' => '0.1'],
                    'lotSizeFilter' => [
                        'basePrecision' => '0.000001',
                        'minOrderQty' => '0.00004',
                        'minOrderAmt' => '5',
                    ],
                ]]],
            ]),
        ]);

        $adapter = $this->adapter();

        // 首次调用：发 HTTP + 写 Redis 缓存
        $filters = $adapter->symbolFilters('BTCUSDT');

        static::assertSame('0.000001', $filters['step_size'], 'lotSizeFilter.basePrecision → step_size');
        static::assertSame('0.1', $filters['tick_size'], 'priceFilter.tickSize → tick_size');
        static::assertSame('5', $filters['min_notional'], 'lotSizeFilter.minOrderAmt → min_notional');

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
    // T015 WS 推送映射（US2）
    // ------------------------------------------------------------------

    public function test_map_ws_kline_payload(): void
    {
        // contracts/bybit-api-contract.md WS 推送样例（对象格式，非 REST 数组格式）
        $row = [
            'start' => 1697443200000,
            'end' => 1697443259999,
            'open' => '65000',
            'high' => '65100',
            'low' => '64900',
            'close' => '65050',
            'volume' => '12.5',
            'turnover' => '812500',
            'confirm' => true,
            'timestamp' => 1697443260000,
        ];

        $adapter = $this->adapter();
        $method = new ReflectionMethod($adapter, 'mapWsKlinePayload');
        $method->setAccessible(true);

        $kline = $method->invoke($adapter, $row, 'BTCUSDT', '1m');

        static::assertSame('bybit', $kline->exchangeCode);
        static::assertSame('BTCUSDT', $kline->symbol, 'symbol 直传（FR-012）');
        static::assertSame('1m', $kline->interval, 'interval 平台格式');
        static::assertSame(1697443200000, $kline->openTime, 'start → openTime');
        static::assertSame(1697443259999, $kline->closeTime, 'end → closeTime（WS 直传，无需推算）');
        static::assertSame('65000', $kline->open);
        static::assertSame('65100', $kline->high);
        static::assertSame('64900', $kline->low);
        static::assertSame('65050', $kline->close);
        static::assertSame('12.5', $kline->volume);
        static::assertSame('812500', $kline->quoteVolume, 'turnover → quoteVolume');
        static::assertSame(0, $kline->tradesCount, 'v5 不返 trades count');
    }

    // ------------------------------------------------------------------
    // T016 confirm 分支（US2 + C1 对齐 001 OKX 范式）
    // ------------------------------------------------------------------

    public function test_confirm_true_triggers_on_bar_only(): void
    {
        $adapter = $this->adapter();
        $method = new ReflectionMethod($adapter, 'dispatchKlineRow');
        $method->setAccessible(true);

        $barRow = [
            'start' => 1697443200000, 'end' => 1697443259999,
            'open' => '65000', 'high' => '65100', 'low' => '64900', 'close' => '65050',
            'volume' => '12.5', 'turnover' => '812500', 'confirm' => true,
        ];
        $tickRow = $barRow;
        $tickRow['confirm'] = false;

        // confirm:true → onBar 触发一次（R-006"每 bar 一次"语义）
        $bars = [];
        $method->invoke($adapter, $barRow, 'BTCUSDT', '1m', function ($k) use (&$bars) {
            $bars[] = $k;
        }, null);
        static::assertCount(1, $bars);
        static::assertSame('65050', $bars[0]->close);

        // confirm:false → 不触发 onBar（bar 内 ~250ms 快照更新），无 onTick 时静默
        $method->invoke($adapter, $tickRow, 'BTCUSDT', '1m', function ($k) use (&$bars) {
            $bars[] = $k;
        }, null);
        static::assertCount(1, $bars, 'confirm:false 不得触发 onBar');

        // C1 对齐 001 OKX 范式：confirm:false 且有 onTick → 用 close 构造 ticker 回调
        $ticks = [];
        $method->invoke($adapter, $tickRow, 'BTCUSDT', '1m', function ($k) use (&$bars) {
            $bars[] = $k;
        }, function ($t) use (&$ticks) {
            $ticks[] = $t;
        });
        static::assertCount(1, $bars, 'confirm:false 仍不触发 onBar');
        static::assertCount(1, $ticks);
        static::assertSame('65050', $ticks[0]->lastPrice, 'onTick ticker.lastPrice 用 close 构造');
        static::assertSame('BTCUSDT', $ticks[0]->symbol);
    }

    // ------------------------------------------------------------------
    // T021 凭据两段守卫（US3，FR-007）
    // ------------------------------------------------------------------

    public function test_credentials_missing_guard_throws_before_http(): void
    {
        Http::fake(); // 兜底假响应：若守卫失效发出请求，下方 assertNothingSent 即失败

        // 空凭据（缺 api_key/api_secret，无 passphrase——FR-007 两段守卫）
        config(['exchange.exchanges.bybit.weight_limit_per_minute' => 0]);
        $adapter = new BybitAdapter(new RateLimiter(), new ExchangeHealthChecker(), []);

        try {
            $adapter->getBalance();
            static::fail('预期缺凭据抛 ExchangeApiException');
        } catch (ExchangeApiException $e) {
            static::assertFalse($e->isRetryable(), '缺凭据为 clientError 硬失败');
            static::assertStringContainsString('api_key and api_secret are required', $e->getMessage());
        }

        Http::assertNothingSent('守卫须在限流与 HTTP 之前抛出（FR-007）');
    }

    // ------------------------------------------------------------------
    // T023 mapOrderStatus 状态机（US3）
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function bybitOrderStatusProvider(): array
    {
        return [
            'New → submitted' => ['New', 'submitted'],
            'PartiallyFilled → partial' => ['PartiallyFilled', 'partial'],
            'Filled → filled' => ['Filled', 'filled'],
            'Cancelled → canceled' => ['Cancelled', 'canceled'],
            'Rejected → failed' => ['Rejected', 'failed'],
            '空串 → failed' => ['', 'failed'],
        ];
    }

    #[DataProvider('bybitOrderStatusProvider')]
    public function test_map_order_status(string $input, string $expected): void
    {
        $adapter = $this->adapter();

        $method = new ReflectionMethod($adapter, 'mapOrderStatus');
        $method->setAccessible(true);

        static::assertSame($expected, $method->invoke($adapter, $input));
    }

    // ------------------------------------------------------------------
    // T024 市价买单 marketUnit=quoteCoin（US3，R-011）
    // ------------------------------------------------------------------

    public function test_place_order_market_buy_uses_quote_coin(): void
    {
        Http::fake([
            '*/v5/order/create' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => ['orderId' => '1635000000000123', 'orderLinkId' => 'c1_2_1697443200_ab12'],
            ]),
        ]);

        $order = $this->signedAdapter()->placeOrder('BTCUSDT', 'buy', 'market', '100', opts: [
            'clientOrderId' => 'c1_2_1697443200_ab12',
        ]);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/v5/order/create')) {
                return false;
            }
            $body = json_decode($request->body(), true);

            return ($body['category'] ?? '') === 'spot'
                && ($body['symbol'] ?? '') === 'BTCUSDT'
                && ($body['side'] ?? '') === 'Buy'
                && ($body['orderType'] ?? '') === 'Market'
                && ($body['qty'] ?? '') === '100'
                && ($body['marketUnit'] ?? '') === 'quoteCoin' // R-011：市价买按报价币金额
                && isset($body['orderLinkId'])
                && strlen((string) $body['orderLinkId']) <= 36; // R-011 截断约束
        });

        // 响应映射（v5 order/create result 仅含 orderId/orderLinkId，受理即 New）
        static::assertSame('1635000000000123', $order->exchangeOrderId);
        static::assertSame('c1_2_1697443200_ab12', $order->clientOrderId, 'clientOrderId 与传入一致');
        static::assertSame('buy', $order->side);
        static::assertSame('market', $order->type);
        static::assertSame('100', $order->quantity);
        static::assertSame('submitted', $order->status);
    }

    // ------------------------------------------------------------------
    // T025 双端点幂等查询（US3，R-005 + FR-006）
    // ------------------------------------------------------------------

    public function test_get_order_by_client_order_id_falls_back_realtime_to_history(): void
    {
        // (1)(3) realtime 空 list → history 命中，HTTP 调用顺序 realtime→history
        Http::fake([
            '*/v5/order/realtime*' => Http::response([
                'retCode' => 0, 'retMsg' => 'OK',
                'result' => ['category' => 'spot', 'list' => []],
            ]),
            '*/v5/order/history*' => Http::response([
                'retCode' => 0, 'retMsg' => 'OK',
                'result' => ['category' => 'spot', 'list' => [[
                    'orderId' => '1635000000000123',
                    'orderLinkId' => 'c1',
                    'symbol' => 'BTCUSDT',
                    'side' => 'Buy',
                    'orderType' => 'Limit',
                    'qty' => '0.001',
                    'price' => '50000',
                    'orderStatus' => 'Filled',
                    'cumExecQty' => '0.001',
                    'avgPrice' => '50000',
                    'cumExecFee' => '0',
                    'createdTime' => '1697443200000',
                    'updatedTime' => '1697443260000',
                ]]],
            ]),
        ]);

        $order = $this->signedAdapter()->getOrderByClientOrderId('BTCUSDT', 'c1');

        static::assertNotNull($order, 'realtime 空、history 命中须返回订单');
        static::assertSame('1635000000000123', $order->exchangeOrderId);
        static::assertSame('filled', $order->status);
        static::assertSame('0.001', $order->filledQty);

        Http::assertSentInOrder([
            fn ($request) => str_contains($request->url(), '/v5/order/realtime'),
            fn ($request) => str_contains($request->url(), '/v5/order/history'),
        ]);
    }

    public function test_get_order_by_client_order_id_returns_null_when_both_endpoints_empty(): void
    {
        // (2) 两端皆空 list → 返回 null 而非抛异常（FR-006 + constitution IV 崩溃恢复幂等）
        Http::fake([
            '*/v5/order/realtime*' => Http::response(['retCode' => 0, 'result' => ['list' => []]]),
            '*/v5/order/history*' => Http::response(['retCode' => 0, 'result' => ['list' => []]]),
        ]);

        static::assertNull($this->signedAdapter()->getOrderByClientOrderId('BTCUSDT', 'missing'));
    }

    // ------------------------------------------------------------------
    // T026 撤单 170213 特判（US3，R-008）
    // ------------------------------------------------------------------

    public function test_cancel_order_returns_false_on_170213(): void
    {
        // retCode=170213（订单不存在/已成交/已撤）→ 返 false 而非抛异常
        Http::fake([
            '*/v5/order/cancel' => Http::response([
                'retCode' => 170213, 'retMsg' => 'order not exists', 'result' => [],
            ]),
        ]);

        static::assertFalse($this->signedAdapter()->cancelOrder('BTCUSDT', '1635000000000123'));
    }

    public function test_cancel_order_reraises_non_170213_errors(): void
    {
        // 其他错误码（10016 服务器繁忙）照常抛 ExchangeApiException（server 可重试）
        Http::fake([
            '*/v5/order/cancel' => Http::response([
                'retCode' => 10016, 'retMsg' => 'system busy', 'result' => [],
            ]),
        ]);

        try {
            $this->signedAdapter()->cancelOrder('BTCUSDT', '1635000000000123');
            static::fail('预期非 170213 错误照常抛 ExchangeApiException');
        } catch (ExchangeApiException $e) {
            static::assertTrue($e->isRetryable(), '10016 归 server 可重试');
        }
    }

    // ------------------------------------------------------------------
    // T027 retCode=10002 时钟硬失败 + 校准提示（US3，clarify Q2）
    // ------------------------------------------------------------------

    public function test_recv_window_exceeded_classified_client_error_with_clock_hint(): void
    {
        Http::fake([
            '*/v5/account/wallet-balance*' => Http::response([
                'retCode' => 10002, 'retMsg' => 'timestamp early than recv_window', 'result' => [],
            ]),
        ]);

        try {
            $this->signedAdapter()->getBalance();
            static::fail('预期 retCode=10002 抛 clientError 硬失败');
        } catch (ExchangeApiException $e) {
            static::assertFalse($e->isRetryable(), '10002 为 client 硬失败不重试');
            static::assertStringContainsString('时钟', $e->getMessage(), '错误消息须含时钟校准提示');
            static::assertStringContainsString('NTP', $e->getMessage());
        }

        Http::assertSentCount(1, '不重试、仅一次 HTTP 调用');
    }

    // ------------------------------------------------------------------
    // T028 余额映射（US3，R-010）
    // ------------------------------------------------------------------

    public function test_get_balance_maps_unified_wallet(): void
    {
        Http::fake([
            '*/v5/account/wallet-balance*' => Http::response([
                'retCode' => 0,
                'retMsg' => 'OK',
                'result' => ['list' => [[
                    'totalEquity' => '1000.5',
                    'totalMarginBalance' => '0',
                    'totalPerpUPL' => '0',
                    'coin' => [
                        ['coin' => 'USDT', 'walletBalance' => '1000.5', 'locked' => '100'],
                        ['coin' => 'ETH', 'walletBalance' => '0', 'locked' => '0'],
                    ],
                ]]],
            ]),
        ]);

        $balance = $this->signedAdapter()->getBalance();

        // available = bcsub('1000.5', '100', 8) = '900.5'（R-010：不用 availableToWithdraw）
        static::assertSame('900.5', $balance->available);
        static::assertSame('1000.5', $balance->totalEquity, 'totalEquity 直传');
        static::assertSame('0', $balance->unrealizedPnl, 'totalPerpUPL → unrealizedPnl 直传');
        // assets 聚合（零余额 ETH 不入明细）
        static::assertSame(['USDT'], array_keys($balance->assets));
        static::assertSame('1000.5', $balance->assets['USDT']['total']);
        static::assertSame('900.5', $balance->assets['USDT']['available']);
    }

    // ------------------------------------------------------------------
    // T034-T036 testnet 开关路由（US4，R-001 独立域名机制）
    // ------------------------------------------------------------------

    public function test_testnet_switch_routes_rest_url(): void
    {
        config(['exchange.exchanges.bybit.testnet' => true]);

        $adapter = $this->adapter();
        $method = new ReflectionMethod($adapter, 'restBaseUrl');
        $method->setAccessible(true);

        static::assertSame('https://api-testnet.bybit.com', $method->invoke($adapter));

        // 公共请求打到 testnet 域名，且不含模拟盘标识 header（R-001：独立域名非 header 标识）
        Http::fake([
            'api-testnet.bybit.com/*' => Http::response([
                'retCode' => 0, 'result' => ['list' => []], 'time' => 1697443200000,
            ]),
        ]);

        $adapter->getTicker('BTCUSDT');

        Http::assertSent(function ($request): bool {
            return str_starts_with($request->url(), 'https://api-testnet.bybit.com')
                && ! isset($request->headers()['x-simulated-trading']);
        });
    }

    public function test_testnet_switch_routes_ws_url(): void
    {
        config(['exchange.exchanges.bybit.testnet' => true]);

        $adapter = $this->adapter();
        $method = new ReflectionMethod($adapter, 'wsBaseUrl');
        $method->setAccessible(true);

        static::assertSame('wss://stream-testnet.bybit.com/v5/public/spot', $method->invoke($adapter));
    }

    public function test_production_switch_default_urls(): void
    {
        config(['exchange.exchanges.bybit.testnet' => false]);

        $adapter = $this->adapter();

        $restMethod = new ReflectionMethod($adapter, 'restBaseUrl');
        $restMethod->setAccessible(true);
        $wsMethod = new ReflectionMethod($adapter, 'wsBaseUrl');
        $wsMethod->setAccessible(true);

        static::assertSame('https://api.bybit.com', $restMethod->invoke($adapter));
        static::assertSame(
            'wss://stream.bybit.com/v5/public/spot',
            $wsMethod->invoke($adapter),
            'T001 修正后的 spot 路径（非 linear）'
        );
    }
}
