<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Exchange\Adapters\BinanceFuturesAdapter;
use App\Domain\Exchange\Adapters\BinanceSpotAdapter;
use App\Domain\Exchange\Adapters\BitgetAdapter;
use App\Domain\Exchange\Adapters\BybitAdapter;
use App\Domain\Exchange\Adapters\OkxAdapter;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use RuntimeException;

/**
 * 交易所适配器工厂。
 *
 * 按 exchange_code + market 路由到对应 Adapter 实例。
 * DI 注入，每次调用返回新实例（不 static 缓存，Octane 长驻禁用）。
 *
 * 用法：
 *   $adapter = $manager->adapter('binance', 'spot');
 *   $ticker = $adapter->getTicker('BTCUSDT');
 */
class ExchangeManager
{
    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly ExchangeHealthChecker $healthChecker,
    ) {
    }

    /**
     * 解析适配器实例。
     *
     * @param string               $exchangeCode binance|okx|bybit|bitget
     * @param string               $market       spot|futures（Binance 区分现货/合约，其他交易所合一）
     * @param array<string,string> $credentials  ['api_key' => .., 'api_secret' => ..]（StrategyRunner 从 exchange_accounts 解密后传入）
     */
    public function adapter(string $exchangeCode, string $market = 'spot', array $credentials = []): ExchangeAdapter
    {
        return match ($exchangeCode) {
            'binance' => $market === 'futures'
                ? (new BinanceFuturesAdapter($this->rateLimiter, $this->healthChecker, $credentials))
                : (new BinanceSpotAdapter($this->rateLimiter, $this->healthChecker, $credentials)),
            'okx' => new OkxAdapter($this->rateLimiter, $this->healthChecker, $credentials),
            'bybit' => new BybitAdapter($this->rateLimiter, $this->healthChecker, $credentials),
            'bitget' => new BitgetAdapter($this->rateLimiter, $this->healthChecker, $credentials),
            default => throw new RuntimeException("Unsupported exchange code: {$exchangeCode}"),
        };
    }
}
