<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Contracts;

/**
 * 交易所适配器接口契约。
 *
 * 抽象 Binance/OKX/Bybit/Bitget 等交易所的 REST + WebSocket 行为，
 * 上层（Strategy/Signal/Risk）只依赖此接口，不感知具体实现。
 *
 * 实现侧（AbstractExchangeAdapter）需内置：
 *  - HMAC 签名生成
 *  - Redis 令牌桶限流（按 exchange:endpoint 维度）
 *  - HTTP/Swoole 协程化（进程 B 内）
 *  - 可降级异常 vs 硬失败异常分类（硬失败不计入熔断计数）
 */
interface ExchangeAdapter
{
    /**
     * 拉取最新 ticker。
     *
     * @param  string  $symbol  交易对，如 BTCUSDT
     */
    public function getTicker(string $symbol): ExchangeTicker;

    /**
     * 拉取历史 K 线。
     *
     * @param  string  $symbol  交易对
     * @param  string  $interval  周期，如 1m/5m/15m/1h/4h/1d
     * @param  int  $limit  返回条数，默认 500
     * @return ExchangeKline[] 按 open_time 升序
     */
    public function getKline(string $symbol, string $interval, int $limit = 500): array;

    /**
     * 拉取账户余额快照。
     */
    public function getBalance(): ExchangeBalance;

    /**
     * 拉取指定交易对持仓（合约场景）。现货返回 null。
     */
    public function getPosition(string $symbol): ?ExchangePosition;

    /**
     * 下单。
     *
     * @param  string  $symbol  交易对
     * @param  string  $side  buy|sell
     * @param  string  $type  market|limit|stop|stop_market|take_profit
     * @param  string  $quantity  数量（字符串保留精度）
     * @param  string|null  $price  限单价，market 单为 null
     * @param  string|null  $stopPrice  止损/止盈触发价
     * @param  array  $opts  附加选项：leverage / reduceOnly / clientOrderId 等
     */
    public function placeOrder(
        string $symbol,
        string $side,
        string $type,
        string $quantity,
        ?string $price = null,
        ?string $stopPrice = null,
        array $opts = [],
    ): ExchangeOrder;

    /**
     * 撤单。返回是否撤销成功。
     *
     * @param  string  $orderId  交易所订单 ID
     * @param  string|null  $clientOrderId  幂等键，部分交易所要求
     */
    public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool;

    /**
     * 查询订单状态。
     */
    public function getOrder(string $symbol, string $orderId): ExchangeOrder;

    /**
     * 按 clientOrderId 幂等查询订单（崩溃恢复核实用）。
     *
     * @return ExchangeOrder|null 查无此单返回 null（交易所侧未受理）
     */
    public function getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder;

    /**
     * 被动订阅 K 线 stream，bar 关闭时回调。
     *
     * @param  callable(ExchangeKline):void  $onBar  bar close 回调
     * @param  callable(ExchangeTicker):void|null  $onTick  实时 tick 回调（可选，高频）
     * @return string subscriptionId，用于 unsubscribeStream
     */
    public function subscribeKlineStream(
        string $symbol,
        string $interval,
        callable $onBar,
        ?callable $onTick = null,
    ): string;

    /**
     * 取消 stream 订阅。
     */
    public function unsubscribeStream(string $subscriptionId): void;

    /**
     * 订阅大户爆仓流（如 Binance forceOrder@arr）。
     *
     * @param  callable(LiquidationEvent):void  $onLiquidation  强平事件回调（Adapter 已归一化为 DTO）
     */
    public function subscribeLiquidationStream(callable $onLiquidation): string;
}
