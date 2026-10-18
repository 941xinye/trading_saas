<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange;

use App\Domain\Exchange\Adapters\BinanceFuturesAdapter;
use App\Domain\Exchange\Adapters\BinanceSpotAdapter;
use App\Domain\Exchange\Adapters\BitgetAdapter;
use App\Domain\Exchange\Adapters\BybitAdapter;
use App\Domain\Exchange\Adapters\OkxAdapter;
use App\Domain\Exchange\ExchangeHealthChecker;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Exchange\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * ExchangeManager 三家路由显式断言（T042，FR-010，001 analyze C1 收尾）。
 *
 * 全程离线：adapter() 仅构造实例不发起网络；RateLimiter 短路放行
 * （weight_limit_per_minute=0 时 limitFor 直接 true 不触 Redis，同 BybitAdapterTest 工厂范式）。
 */
#[Group('offline')]
class ExchangeManagerRouteTest extends TestCase
{
    private ExchangeManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['binance', 'okx', 'bybit', 'bitget'] as $code) {
            config(["exchange.exchanges.{$code}.weight_limit_per_minute" => 0]);
        }

        $this->manager = new ExchangeManager(new RateLimiter(), new ExchangeHealthChecker());
    }

    public function test_routes_binance_spot_to_binance_spot_adapter(): void
    {
        $adapter = $this->manager->adapter('binance', 'spot', ['api_key' => 'k', 'api_secret' => 's']);

        static::assertInstanceOf(BinanceSpotAdapter::class, $adapter);
    }

    public function test_routes_okx_spot_to_okx_adapter(): void
    {
        static::assertInstanceOf(OkxAdapter::class, $this->manager->adapter('okx', 'spot'));
    }

    public function test_routes_bybit_spot_to_bybit_adapter(): void
    {
        static::assertInstanceOf(BybitAdapter::class, $this->manager->adapter('bybit', 'spot'));
    }

    public function test_routes_bitget_spot_to_bitget_adapter(): void
    {
        static::assertInstanceOf(BitgetAdapter::class, $this->manager->adapter('bitget', 'spot'));
    }

    public function test_routes_binance_futures_to_futures_adapter(): void
    {
        static::assertInstanceOf(BinanceFuturesAdapter::class, $this->manager->adapter('binance', 'futures'));
    }

    public function test_unsupported_exchange_code_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported exchange code');

        $this->manager->adapter('huobi', 'spot');
    }
}
