<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\ExchangeHealthChecker;
use App\Domain\Exchange\RateLimiter;

/**
 * 交易所适配器抽象基类。
 *
 * 子类（BinanceSpotAdapter / OkxAdapter 等）实现具体 HTTP/WS 行为，
 * 父类注入 RateLimiter / ExchangeHealthChecker 公共能力。
 *
 * 内置能力（P1 POC 实现）：
 *  - HMAC 签名生成（按交易所差异重写 sign() 模板方法）
 *  - 限流前置检查（RateLimiter.tryAcquire）
 *  - 健康检查埋点（record + isHealthy）
 *  - 可降级异常 vs 硬失败异常分类（硬失败不计入熔断计数）
 *  - HTTP/Swoole 协程化（进程 B 内）
 *
 * 不使用 static 缓存（Octane 长驻）。
 */
abstract class AbstractExchangeAdapter implements ExchangeAdapter
{
    public function __construct(
        protected readonly RateLimiter $rateLimiter,
        protected readonly ExchangeHealthChecker $healthChecker,
    ) {
    }

    /**
     * 当前适配器对应的交易所代码（binance|okx|bybit|bitget）。
     */
    abstract protected function exchangeCode(): string;

    public function getTicker(string $symbol): ExchangeTicker
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function getKline(string $symbol, string $interval, int $limit = 500): array
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function getBalance(): ExchangeBalance
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function getPosition(string $symbol): ?ExchangePosition
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function placeOrder(
        string $symbol,
        string $side,
        string $type,
        string $quantity,
        ?string $price = null,
        ?string $stopPrice = null,
        array $opts = [],
    ): ExchangeOrder {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function getOrder(string $symbol, string $orderId): ExchangeOrder
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function subscribeKlineStream(
        string $symbol,
        string $interval,
        callable $onBar,
        ?callable $onTick = null,
    ): string {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function unsubscribeStream(string $subscriptionId): void
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }

    public function subscribeLiquidationStream(callable $onLiquidation): string
    {
        throw new \RuntimeException('Not implemented in skeleton. Wait for P1 POC.');
    }
}
