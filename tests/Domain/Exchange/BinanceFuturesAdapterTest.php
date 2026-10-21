<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange;

use App\Domain\Exchange\Adapters\BinanceFuturesAdapter;
use App\Domain\Exchange\ExchangeHealthChecker;
use App\Domain\Exchange\RateLimiter;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Support\RedisCleanup;
use Tests\TestCase;

/**
 * Binance 合约适配器离线断言（spec 005 WS2）。
 *
 * 全程 Http::fake 无外网；RateLimiter 短路放行；symbolFilters/leverageBracket 走 Redis 缓存。
 */
class BinanceFuturesAdapterTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private const FILTERS_KEY = 'market:symbols:binance_futures:BTCUSDT:filters';
    private const BRACKET_KEY = 'futures:leverage_bracket:BTCUSDT';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupRedis([self::FILTERS_KEY, self::BRACKET_KEY]);
    }

    protected function tearDown(): void
    {
        $this->cleanupRedis([self::FILTERS_KEY, self::BRACKET_KEY]);

        parent::tearDown();
    }

    private function adapter(): BinanceFuturesAdapter
    {
        // RateLimiter 短路放行（Binance 生产配置 weight_limit_per_minute=1200 会触 Redis）
        config(['exchange.exchanges.binance.weight_limit_per_minute' => 0]);

        return new BinanceFuturesAdapter(new RateLimiter(), new ExchangeHealthChecker(), [
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
        ]);
    }

    // ------------------------------------------------------------------
    // 行情
    // ------------------------------------------------------------------

    public function test_get_ticker_returns_last_price(): void
    {
        Http::fake([
            '*/fapi/v1/ticker/price*' => Http::response(['symbol' => 'BTCUSDT', 'price' => '61000.50']),
        ]);

        $ticker = $this->adapter()->getTicker('BTCUSDT');

        $this->assertSame('BTCUSDT', $ticker->symbol);
        $this->assertSame('61000.50', $ticker->lastPrice);
    }

    public function test_get_kline_returns_ascending_klines(): void
    {
        Http::fake([
            '*/fapi/v1/klines*' => Http::response([
                [1000, '60000', '60100', '59900', '60050', '10', 1999, '600000', 10],
                [2000, '60050', '60200', '60000', '60150', '20', 2999, '1200000', 20],
            ]),
        ]);

        $klines = $this->adapter()->getKline('BTCUSDT', '1m', 2);

        $this->assertCount(2, $klines);
        $this->assertSame(1000, $klines[0]->openTime);
        $this->assertSame('60150', $klines[1]->close);
    }

    // ------------------------------------------------------------------
    // 账户与持仓
    // ------------------------------------------------------------------

    public function test_get_balance_maps_wallet_and_available(): void
    {
        Http::fake([
            '*/fapi/v2/account*' => Http::response([
                'totalWalletBalance' => '1000.00',
                'availableBalance' => '800.00',
                'totalMarginBalance' => '900.00',
                'totalUnrealizedProfit' => '50.00',
                'assets' => [
                    ['asset' => 'USDT', 'walletBalance' => '1000.00', 'availableBalance' => '800.00'],
                ],
            ]),
        ]);

        $balance = $this->adapter()->getBalance();

        $this->assertSame('1000.00', $balance->totalEquity);
        $this->assertSame('800.00', $balance->available);
        $this->assertSame('50.00', $balance->unrealizedPnl);
    }

    public function test_get_position_returns_long_position(): void
    {
        Http::fake([
            '*/fapi/v2/positionRisk*' => Http::response([
                [
                    'symbol' => 'BTCUSDT',
                    'positionAmt' => '0.010',
                    'entryPrice' => '61000.00',
                    'markPrice' => '61500.00',
                    'liquidationPrice' => '55000.00',
                    'leverage' => '10',
                    'unRealizedProfit' => '5.00',
                    'isolatedMargin' => '61.00',
                ],
            ]),
        ]);

        $pos = $this->adapter()->getPosition('BTCUSDT');

        $this->assertNotNull($pos);
        $this->assertSame('long', $pos->side);
        $this->assertSame('0.010', $pos->size);
        $this->assertSame(10, $pos->leverage);
        $this->assertSame('55000.00', $pos->liquidationPrice);
    }

    public function test_get_position_returns_short_for_negative_amount(): void
    {
        Http::fake([
            '*/fapi/v2/positionRisk*' => Http::response([
                [
                    'symbol' => 'BTCUSDT',
                    'positionAmt' => '-0.020',
                    'entryPrice' => '60000.00',
                    'markPrice' => '59500.00',
                    'liquidationPrice' => '65000.00',
                    'leverage' => '5',
                    'unRealizedProfit' => '10.00',
                    'isolatedMargin' => '240.00',
                ],
            ]),
        ]);

        $pos = $this->adapter()->getPosition('BTCUSDT');

        $this->assertNotNull($pos);
        $this->assertSame('short', $pos->side);
        $this->assertSame('0.020', $pos->size);
    }

    public function test_get_position_returns_null_when_no_position(): void
    {
        Http::fake([
            '*/fapi/v2/positionRisk*' => Http::response([
                ['symbol' => 'BTCUSDT', 'positionAmt' => '0.000', 'entryPrice' => '0', 'leverage' => '10'],
            ]),
        ]);

        $this->assertNull($this->adapter()->getPosition('BTCUSDT'));
    }

    // ------------------------------------------------------------------
    // 交易
    // ------------------------------------------------------------------

    public function test_place_order_supports_leverage_and_reduce_only(): void
    {
        Http::fake([
            '*/fapi/v1/order' => Http::response([
                'orderId' => 12345,
                'clientOrderId' => 'cid-001',
                'symbol' => 'BTCUSDT',
                'side' => 'BUY',
                'type' => 'MARKET',
                'origQty' => '0.010',
                'executedQty' => '0.010',
                'avgPrice' => '61000.00',
                'status' => 'FILLED',
                'updateTime' => 1700000000000,
            ]),
        ]);

        $order = $this->adapter()->placeOrder(
            'BTCUSDT', 'buy', 'market', '0.010', null, null,
            ['clientOrderId' => 'cid-001', 'leverage' => 10, 'reduceOnly' => true],
        );

        $this->assertSame('12345', $order->exchangeOrderId);
        $this->assertSame('filled', $order->status);
        $this->assertSame(10, $order->leverage);
        $this->assertTrue($order->reduceOnly);
        $this->assertSame('0.01', $order->filledQty);
    }

    public function test_cancel_order_returns_true_on_success(): void
    {
        Http::fake([
            '*/fapi/v1/order' => Http::response(['symbol' => 'BTCUSDT', 'orderId' => 12345, 'status' => 'CANCELED']),
        ]);

        $this->assertTrue($this->adapter()->cancelOrder('BTCUSDT', '12345'));
    }

    public function test_get_order_by_client_order_id_returns_null_when_not_found(): void
    {
        Http::fake([
            '*/fapi/v1/order*' => Http::response(['code' => -2013, 'msg' => 'Order does not exist.'], 400),
        ]);

        $this->assertNull($this->adapter()->getOrderByClientOrderId('BTCUSDT', 'nonexistent'));
    }

    // ------------------------------------------------------------------
    // 精度与合约设置
    // ------------------------------------------------------------------

    #[Group('redis')]
    public function test_symbol_filters_caches_in_redis(): void
    {
        $this->requireRedis();

        Http::fake([
            '*/fapi/v1/exchangeInfo*' => Http::response([
                'symbols' => [[
                    'symbol' => 'BTCUSDT',
                    'filters' => [
                        ['filterType' => 'LOT_SIZE', 'stepSize' => '0.001'],
                        ['filterType' => 'PRICE_FILTER', 'tickSize' => '0.01'],
                        ['filterType' => 'MIN_NOTIONAL', 'notional' => '5'],
                    ],
                ]],
            ]),
        ]);

        $filters = $this->adapter()->symbolFilters('BTCUSDT');

        $this->assertSame('0.001', $filters['step_size']);
        $this->assertSame('0.01', $filters['tick_size']);
        $this->assertSame('5', $filters['min_notional']);

        // 第二次走缓存，不再发 HTTP
        Http::fake();
        $cached = $this->adapter()->symbolFilters('BTCUSDT');
        $this->assertSame($filters, $cached);
    }

    public function test_align_to_step_floors_to_step(): void
    {
        $adapter = $this->adapter();

        $this->assertSame('0.001', $adapter->alignToStep('0.0015', '0.001'));
        $this->assertSame('0', $adapter->alignToStep('0.0005', '0.001'));
        $this->assertSame('0.01', $adapter->alignToStep('0.010', '0.001'));
    }

    #[Group('redis')]
    public function test_get_leverage_bracket_caches_mmr(): void
    {
        $this->requireRedis();

        Http::fake([
            '*/fapi/v1/leverageBracket*' => Http::response([[
                'symbol' => 'BTCUSDT',
                'brackets' => [
                    ['bracket' => 1, 'initialMarginRate' => '0.10', 'maintMarginRate' => '0.0040', 'cap' => 50000],
                    ['bracket' => 2, 'initialMarginRate' => '0.20', 'maintMarginRate' => '0.0050', 'cap' => 250000],
                ],
            ]]),
        ]);

        $brackets = $this->adapter()->getLeverageBracket('BTCUSDT');

        $this->assertCount(2, $brackets);
        $this->assertSame('0.0040', $brackets[0]['maintMarginRate']);
    }

    // ------------------------------------------------------------------
    // URL 切换
    // ------------------------------------------------------------------

    public function test_rest_url_uses_futures_mainnet_by_default(): void
    {
        config(['exchange.exchanges.binance.testnet' => false]);

        $method = new \ReflectionMethod($this->adapter(), 'restBaseUrl');
        $method->setAccessible(true);

        $this->assertSame('https://fapi.binance.com', $method->invoke($this->adapter()));
    }

    public function test_rest_url_uses_futures_testnet_when_enabled(): void
    {
        config(['exchange.exchanges.binance.testnet' => true]);

        $method = new \ReflectionMethod($this->adapter(), 'restBaseUrl');
        $method->setAccessible(true);

        $this->assertSame('https://testnet.binancefuture.com', $method->invoke($this->adapter()));
    }
}
