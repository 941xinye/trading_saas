<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use RuntimeException;

/**
 * 测试用交易所适配器假件（P5.5 步骤 1 起）。
 *
 * 只承载对账/恢复测试需要的行为：可编程余额、ticker、按 cid 的订单映射、
 * 余额/查单异常注入；其余方法被调用即抛异常（测试不得意外触网）。
 */
class FakeExchangeAdapter implements ExchangeAdapter
{
    public ?ExchangeBalance $balance = null;

    public string $tickerPrice = '0';

    public bool $throwOnBalance = false;

    public bool $throwOnTicker = false;

    public bool $throwOnGetOrder = false;

    public bool $throwOnGetPositions = false;

    /** @var array<string,ExchangeOrder|null> clientOrderId → 订单（null=查无此单） */
    public array $ordersByCid = [];

    /** @var ExchangePosition[] 注入的持仓腿（缺省空 = 无持仓，spec 010） */
    public array $positions = [];

    public function getTicker(string $symbol): ExchangeTicker
    {
        if ($this->throwOnTicker) {
            throw new RuntimeException('fake ticker failure');
        }

        return new ExchangeTicker($symbol, $this->tickerPrice, '0', '0', '0', '0', 1_760_000_000_000);
    }

    public function getKline(string $symbol, string $interval, int $limit = 500): array
    {
        throw new RuntimeException('FakeExchangeAdapter::getKline not supported in tests');
    }

    public function getBalance(): ExchangeBalance
    {
        if ($this->throwOnBalance) {
            throw new RuntimeException('fake balance failure');
        }

        return $this->balance ?? new ExchangeBalance('0', '0', '0', '0', []);
    }

    public function getPosition(string $symbol): ?ExchangePosition
    {
        return null;
    }

    public function getPositions(string $symbol): array
    {
        if ($this->throwOnGetPositions) {
            throw new RuntimeException('fake getPositions failure');
        }

        return $this->positions;
    }

    /**
     * 注入持仓腿（spec 010 测试辅助）。
     *
     * @param  ExchangePosition[]  $legs
     */
    public function setPositions(array $legs): self
    {
        $this->positions = $legs;

        return $this;
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
        throw new RuntimeException('FakeExchangeAdapter::placeOrder not supported in tests');
    }

    public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool
    {
        throw new RuntimeException('FakeExchangeAdapter::cancelOrder not supported in tests');
    }

    public function getOrder(string $symbol, string $orderId): ExchangeOrder
    {
        throw new RuntimeException('FakeExchangeAdapter::getOrder not supported in tests');
    }

    public function getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder
    {
        if ($this->throwOnGetOrder) {
            throw new RuntimeException('fake getOrder failure');
        }

        return $this->ordersByCid[$clientOrderId] ?? null;
    }

    public function subscribeKlineStream(
        string $symbol,
        string $interval,
        callable $onBar,
        ?callable $onTick = null,
    ): string {
        throw new RuntimeException('FakeExchangeAdapter streams not supported in tests');
    }

    public function unsubscribeStream(string $subscriptionId): void
    {
        throw new RuntimeException('FakeExchangeAdapter streams not supported in tests');
    }

    public function subscribeLiquidationStream(callable $onLiquidation): string
    {
        throw new RuntimeException('FakeExchangeAdapter liquidation stream not supported in tests');
    }

    /**
     * 便捷构造余额 DTO。
     *
     * @param  array<string,array<string,string>>  $assets
     */
    public static function makeBalance(
        string $usdtTotal,
        string $usdtAvailable,
        array $assets,
    ): ExchangeBalance {
        return new ExchangeBalance($usdtTotal, '0', $usdtAvailable, '0', $assets);
    }
}
