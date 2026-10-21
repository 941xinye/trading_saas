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
use Tests\Support\RedisCleanup;
use Tests\TestCase;

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
    use RedisCleanup;

    private const FILTERS_KEY = 'market:symbols:bitget:BTCUSDT:filters';

    protected function setUp(): void
    {
        parent::setUp();

        // constitution VI：TTL 键在 setUp/tearDown 都精确清理。
        // Redis 不可用时 trait 内部静默降级（非 redis 组测试不依赖 Redis，T007 由 requireRedis() 守卫）。
        $this->cleanupRedis([self::FILTERS_KEY]);
    }

    protected function tearDown(): void
    {
        // constitution VI：外部 IO 清理先于 parent::tearDown()。
        $this->cleanupRedis([self::FILTERS_KEY]);

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

    // ------------------------------------------------------------------
    // T013 WS 推送映射（US2）
    // ------------------------------------------------------------------

    public function test_map_ws_kline_payload(): void
    {
        // contracts/bitget-api-contract.md 推送样例：data[0] 为数组 [ts,O,H,L,C,baseVol,quoteVol]
        $row = ['1697443200000', '65000', '65100', '64900', '65050', '12.5', '812500'];

        $adapter = $this->adapter();
        $method = new ReflectionMethod($adapter, 'mapWsKlinePayload');
        $method->setAccessible(true);

        $kline = $method->invoke($adapter, $row, 'BTCUSDT', '1m');

        static::assertSame('bitget', $kline->exchangeCode);
        static::assertSame('BTCUSDT', $kline->symbol, 'symbol 直传（FR-011）');
        static::assertSame('1m', $kline->interval, 'interval 平台格式');
        static::assertSame(1697443200000, $kline->openTime, '[0] → openTime');
        static::assertSame(1697443260000, $kline->closeTime, 'closeTime=openTime+60000 bcmath 推算');
        static::assertSame('65000', $kline->open, '[1] → open');
        static::assertSame('65100', $kline->high, '[2] → high');
        static::assertSame('64900', $kline->low, '[3] → low');
        static::assertSame('65050', $kline->close, '[4] → close');
        static::assertSame('12.5', $kline->volume, '[5] → volume');
        static::assertSame('812500', $kline->quoteVolume, '[6] → quoteVolume');
        static::assertSame(0, $kline->tradesCount, 'Bitget 不返 trades count');
    }

    // ------------------------------------------------------------------
    // T014 bar 切换检测（US2，R-006 判定语义纯函数单测，无网络）
    // ------------------------------------------------------------------

    public function test_bar_switch_detection_triggers_on_bar_once(): void
    {
        $adapter = $this->adapter();
        $method = new ReflectionMethod($adapter, 'processKlineFrame');
        $method->setAccessible(true);

        // bar A：ts=1697443200000（先快照播种，后 update 刷新至最终形态）
        $barAFirst = ['1697443200000', '65000', '65100', '64900', '65000', '5.0', '325000'];
        $barALast = ['1697443200000', '65000', '65100', '64900', '65050', '12.5', '812500'];
        // bar B：ts=1697443260000（bar 切换帧）
        $barB = ['1697443260000', '65050', '65200', '65000', '65100', '10.0', '651000'];

        $state = ['lastBarOpenTime' => null, 'lastBar' => null];

        // (1) 首帧 action=snapshot：只播种 lastBarOpenTime 不触发 onBar（R-006）
        $r = $method->invoke($adapter, $barAFirst, 'snapshot', 'BTCUSDT', '1m', $state);
        static::assertNull($r['closedBar'], 'snapshot 只播种不触发 onBar');
        static::assertSame(1697443200000, $r['state']['lastBarOpenTime'], 'snapshot 播种 lastBarOpenTime');
        $state = $r['state'];

        // (2) update 帧 ts 与 lastBarOpenTime 相等（同 bar 快照更新）：不触发，刷新缓存
        $r = $method->invoke($adapter, $barALast, 'update', 'BTCUSDT', '1m', $state);
        static::assertNull($r['closedBar'], '同 bar update 不触发 onBar');
        static::assertSame(1697443200000, $r['state']['lastBarOpenTime']);
        static::assertSame('65050', $r['state']['lastBar']->close, '缓存刷新为最新完整 bar 数据');
        $state = $r['state'];

        // (3) update 帧 ts 大于 lastBarOpenTime（bar 切换）：用缓存的上一 bar 完整数据触发恰好一次
        $r = $method->invoke($adapter, $barB, 'update', 'BTCUSDT', '1m', $state);
        static::assertNotNull($r['closedBar'], 'bar 切换触发 onBar');
        static::assertSame('65050', $r['closedBar']->close, '用缓存的上一 bar（刷新后）完整数据');
        static::assertSame(1697443200000, $r['closedBar']->openTime, 'closedBar 是上一 bar 而非新 bar');
        static::assertSame(1697443260000, $r['state']['lastBarOpenTime'], 'lastBarOpenTime 推进到新 bar');
        $state = $r['state'];

        // (4) 幂等：新 bar 同 ts 重复 update 不再触发（每 bar 一次语义，BarAggregator 零改动）
        $r = $method->invoke($adapter, $barB, 'update', 'BTCUSDT', '1m', $state);
        static::assertNull($r['closedBar'], '新 bar 同 ts 重复 update 不再触发');
    }

    // ------------------------------------------------------------------
    // T019 凭据三段守卫（US3，FR-006/FR-007 前置失败不占限流额度）
    // ------------------------------------------------------------------

    public function test_credentials_three_part_guard_throws_before_http(): void
    {
        foreach (['api_key', 'api_secret', 'passphrase'] as $missing) {
            config(['exchange.exchanges.bitget.weight_limit_per_minute' => 0]);
            $credentials = ['api_key' => 'k', 'api_secret' => 's', 'passphrase' => 'p'];
            $credentials[$missing] = '';

            $adapter = new BitgetAdapter(new RateLimiter(), new ExchangeHealthChecker(), $credentials);

            Http::fake();

            try {
                $adapter->getBalance();
                static::fail("预期缺失 {$missing} 抛 ExchangeApiException");
            } catch (ExchangeApiException $e) {
                static::assertFalse($e->isRetryable(), '凭据缺失为 clientError 硬失败');
            }
        }

        // 前置守卫失败：未发起任何 HTTP（签名器在限流前抛出，constitution V）
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // T021 mapOrderStatus 状态机（data-model.md State Transitions）
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function orderStatusProvider(): array
    {
        return [
            'new → submitted' => ['new', 'submitted'],
            'init → submitted' => ['init', 'submitted'],
            'partially_filled → partial' => ['partially_filled', 'partial'],
            'filled → filled' => ['filled', 'filled'],
            'cancelled → canceled' => ['cancelled', 'canceled'],
            'failed → failed' => ['failed', 'failed'],
            '未知 → failed（保守）' => ['something_else', 'failed'],
        ];
    }

    #[DataProvider('orderStatusProvider')]
    public function test_map_order_status_maps_to_platform_enum(string $bitgetStatus, string $expected): void
    {
        $method = new ReflectionMethod($this->adapter(), 'mapOrderStatus');
        $method->setAccessible(true);

        static::assertSame($expected, $method->invoke($this->adapter(), $bitgetStatus));
    }

    // ------------------------------------------------------------------
    // T022 placeOrder（R-009：市价买 size=报价币 USDT 金额直传，无附加参数）
    // ------------------------------------------------------------------

    public function test_place_order_market_buy_uses_quote_size_without_extra_params(): void
    {
        Http::fake([
            '*/api/v2/spot/trade/place-order' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [
                    'orderId' => '1635000000000123',
                    'clientOid' => '1_1_1697443200_ab12',
                ],
            ]),
        ]);

        $order = $this->signedAdapter()->placeOrder(
            'BTCUSDT',
            'buy',
            'market',
            '100', // 市价买 size = 报价币 USDT 金额直传（R-009）
            null,
            null,
            ['clientOrderId' => '1_1_1697443200_ab12'],
        );

        static::assertSame('1635000000000123', $order->exchangeOrderId);
        static::assertSame('1_1_1697443200_ab12', $order->clientOrderId);
        static::assertSame('BTCUSDT', $order->symbol);
        static::assertSame('buy', $order->side);
        static::assertSame('market', $order->type);
        static::assertSame('100', $order->quantity);
        static::assertNull($order->price);
        static::assertSame('submitted', $order->status, 'place-order data 无 status，受理即 submitted');

        // 发送 body 校验：四件套签名头 + size 直传、无 price/force/marketUnit 附加参数
        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            return $request->hasHeader('ACCESS-SIGN')
                && $body['symbol'] === 'BTCUSDT'
                && $body['size'] === '100'
                && ! isset($body['price'])
                && ! isset($body['force'])
                && ! isset($body['marketUnit']);
        });
    }

    public function test_place_order_limit_includes_price_and_force(): void
    {
        Http::fake([
            '*/api/v2/spot/trade/place-order' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => ['orderId' => '1635000000000124', 'clientOid' => 'oid-2'],
            ]),
        ]);

        $order = $this->signedAdapter()->placeOrder(
            'BTCUSDT',
            'sell',
            'limit',
            '0.01',
            '65100.5',
            null,
            ['clientOrderId' => 'oid-2'],
        );

        static::assertSame('65100.5', $order->price);
        static::assertSame('sell', $order->side);
        static::assertSame('limit', $order->type);
        static::assertSame('oid-2', $order->clientOrderId);

        // 限价单：price + force=gtc + clientOid 上限候选 64 字符不截断
        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            return $body['price'] === '65100.5'
                && ($body['force'] ?? null) === 'gtc'
                && $body['clientOid'] === 'oid-2';
        });
    }

    // ------------------------------------------------------------------
    // T023 双查幂等（R-005：orderInfo 主查 → history-orders 兜底 → null）
    // ------------------------------------------------------------------

    public function test_get_order_by_client_order_id_falls_back_to_history(): void
    {
        // 主查 orderInfo 候选 43001（订单不存在）→ 兜底 history-orders 命中
        Http::fake([
            '*/api/v2/spot/trade/orderInfo*' => Http::response([
                'code' => '43001',
                'msg' => 'The order does not exist',
                'data' => null,
            ]),
            '*/api/v2/spot/trade/history-orders*' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [[
                    'orderId' => '1635000000000123',
                    'clientOid' => '1_1_1697443200_ab12',
                    'symbol' => 'BTCUSDT',
                    'side' => 'buy',
                    'orderType' => 'market',
                    'size' => '100',
                    'price' => '',
                    'status' => 'filled',
                    'baseVolume' => '0.00153800',
                    'priceAvg' => '65010.5',
                    'fee' => '0.0000015',
                    'cTime' => '1697443200000',
                    'uTime' => '1697443201000',
                ]],
            ]),
        ]);

        $order = $this->signedAdapter()->getOrderByClientOrderId('BTCUSDT', '1_1_1697443200_ab12');

        static::assertNotNull($order, '主查 43001 → 兜底 history-orders 命中');
        static::assertSame('1635000000000123', $order->exchangeOrderId);
        static::assertSame('1_1_1697443200_ab12', $order->clientOrderId);
        static::assertSame('filled', $order->status);
        static::assertSame('0.001538', $order->filledQty, 'baseVolume trimZeros');
        static::assertSame('65010.5', $order->avgFillPrice);
        static::assertSame(1697443200, $order->placedAt, 'cTime/1000 秒级');
        static::assertSame(1697443201, $order->filledAt, 'status=filled 时 uTime/1000');

        Http::assertSentCount(2, 'orderInfo + history-orders 各一次');
    }

    public function test_get_order_by_client_order_id_returns_null_when_both_empty(): void
    {
        // 两端皆空 data → 返 null（FR-005 + constitution IV：真不存在返回 null）
        Http::fake([
            '*/api/v2/spot/trade/orderInfo*' => Http::response(['code' => '00000', 'msg' => 'success', 'data' => []]),
            '*/api/v2/spot/trade/history-orders*' => Http::response(['code' => '00000', 'msg' => 'success', 'data' => []]),
        ]);

        $order = $this->signedAdapter()->getOrderByClientOrderId('BTCUSDT', 'nonexistent');

        static::assertNull($order);
        Http::assertSentCount(2);
    }

    // ------------------------------------------------------------------
    // T024 cancelOrder 特判（候选 43001 → false 不抛，US3 场景3）
    // ------------------------------------------------------------------

    public function test_cancel_order_returns_false_on_order_not_found(): void
    {
        Http::fake([
            '*/api/v2/spot/trade/cancel-order' => Http::response([
                'code' => '43001',
                'msg' => 'The order does not exist',
                'data' => null,
            ]),
        ]);

        $result = $this->signedAdapter()->cancelOrder('BTCUSDT', '1635', 'client-oid-1');

        static::assertFalse($result, '候选 43001（已成交/已撤）→ 返 false 不抛');
    }

    public function test_cancel_order_reraises_other_client_errors(): void
    {
        Http::fake([
            '*/api/v2/spot/trade/cancel-order' => Http::response([
                'code' => '40009',
                'msg' => 'Signature verification failed',
                'data' => null,
            ]),
        ]);

        $this->expectException(ExchangeApiException::class);

        $this->signedAdapter()->cancelOrder('BTCUSDT', '1635', null);
    }

    // ------------------------------------------------------------------
    // T025 时钟守卫（候选 40013 → clientError + 校准提示，与 Bybit 10002 对齐）
    // ------------------------------------------------------------------

    public function test_timestamp_exceeded_classified_client_error_with_clock_hint(): void
    {
        Http::fake([
            '*/api/v2/spot/account/assets*' => Http::response([
                'code' => '40013',
                'msg' => 'request time expired',
                'data' => null,
            ]),
        ]);

        try {
            $this->signedAdapter()->getBalance();
            static::fail('预期 40013 抛 ExchangeApiException');
        } catch (ExchangeApiException $e) {
            static::assertFalse($e->isRetryable(), '40013 clientError 硬失败（不重试）');
            static::assertStringContainsString('时钟', $e->getMessage(), '附带时钟校准提示');
        }
    }

    // ------------------------------------------------------------------
    // T026 getBalance 镜像 BinanceSpotAdapter（R-010 现货余额近似）
    // ------------------------------------------------------------------

    public function test_get_balance_maps_spot_assets(): void
    {
        Http::fake([
            '*/api/v2/spot/account/assets*' => Http::response([
                'code' => '00000',
                'msg' => 'success',
                'data' => [
                    ['coin' => 'USDT', 'available' => '900.5', 'frozen' => '100'],
                    ['coin' => 'BTC', 'available' => '0.5', 'frozen' => '0.1'],
                    ['coin' => 'ETH', 'available' => '0', 'frozen' => '0'], // 零余额不入明细
                ],
            ]),
        ]);

        $balance = $this->signedAdapter()->getBalance();

        // R-010：available=USDT.available 直传；totalEquity=bcmath bcadd(available, frozen)
        static::assertSame('900.5', $balance->available);
        static::assertSame('1000.5', $balance->totalEquity, 'bcadd(available, frozen, 8)');
        static::assertSame('0', $balance->totalMargin);
        static::assertSame('0', $balance->unrealizedPnl);
        static::assertSame(['USDT', 'BTC'], array_keys($balance->assets), '零余额资产不入明细');
        static::assertSame('1000.5', $balance->assets['USDT']['total']);
        static::assertSame('0.6', $balance->assets['BTC']['total'], 'bcmath 加总 trimZeros');
    }

    // ------------------------------------------------------------------
    // T032 config 结构性断言（US4，决定 A：无 testnet 键 / FR-013）
    // ------------------------------------------------------------------

    public function test_bitget_config_has_no_testnet_keys(): void
    {
        $config = config('exchange.exchanges.bitget');
        static::assertIsArray($config);

        // FR-013 / 决定 A：Bitget 现货无公开模拟盘——config MUST NOT 引入 testnet 键
        foreach (['testnet', 'testnet_rest_url', 'testnet_ws_url'] as $forbidden) {
            static::assertArrayNotHasKey($forbidden, $config, "bitget 段 MUST NOT 含 {$forbidden}");
        }

        // .env.example 无 EXCHANGE_BITGET_TESTNET 变量（T002 决定 A）
        $envExample = file_get_contents(base_path('.env.example'));
        static::assertNotFalse($envExample);
        static::assertStringNotContainsString('EXCHANGE_BITGET_TESTNET', (string) $envExample);
    }

    // ------------------------------------------------------------------
    // T033 生产端点与限流结构（与前三家风格对齐）
    // ------------------------------------------------------------------

    public function test_production_urls_and_rate_limits_resolved(): void
    {
        $config = config('exchange.exchanges.bitget');

        static::assertSame('https://api.bitget.com', $config['rest_url']);
        static::assertSame('wss://ws.bitget.com/v2/ws/public', $config['ws_url']);

        // rate_limits 三类（R-007：public 20/1s per IP 已核实；trade/account 10/1s per UID 候选待核）
        static::assertSame(['limit' => 20, 'window' => 1], $config['rate_limits']['public']);
        static::assertSame(['limit' => 10, 'window' => 1], $config['rate_limits']['trade']);
        static::assertSame(['limit' => 10, 'window' => 1], $config['rate_limits']['account']);
    }
}
