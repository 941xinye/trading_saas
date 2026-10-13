<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\Exceptions\ExchangeApiException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as WsClient;
use Throwable;

/**
 * 币安现货适配器。
 *
 * REST: https://api.binance.com（testnet: https://testnet.binance.vision）
 * WS:   wss://stream.binance.com:9443/stream（testnet: wss://stream.testnet.binance.vision:9443/stream）
 *
 * 现货场景无持仓概念，getPosition() 永远返回 null（父类默认实现）。
 *
 * WS 断线重连（架构 §9.2）：指数退避 1s→2s→4s…上限 60s；recv 30s 超时主动重连；
 * 重连后由 StrategyRunner 侧通过 REST getKline 补拉缺失 K 线（Adapter 只负责通知断开）。
 *
 * 订单幂等：placeOrder 必须携带 opts['clientOrderId']（newClientOrderId 透传）。
 */
class BinanceSpotAdapter extends AbstractExchangeAdapter
{
    private int $subCounter = 0;

    /** @var array<string,array{cid:int,streams:string}> subscriptionId → 协程信息 */
    private array $subscriptions = [];

    protected function exchangeCode(): string
    {
        return 'binance';
    }

    protected function market(): string
    {
        return 'spot';
    }

    public function withCredentials(array $credentials): static
    {
        return new static($this->rateLimiter, $this->healthChecker, $credentials);
    }

    // ------------------------------------------------------------------
    // 行情（公共接口，无需签名）
    // ------------------------------------------------------------------

    public function getTicker(string $symbol): ExchangeTicker
    {
        $data = $this->httpGet('/api/v3/ticker/24hr', ['symbol' => $symbol], weight: 2);

        return new ExchangeTicker(
            symbol: (string) ($data['symbol'] ?? $symbol),
            lastPrice: (string) ($data['lastPrice'] ?? '0'),
            bidPrice: (string) ($data['bidPrice'] ?? '0'),
            askPrice: (string) ($data['askPrice'] ?? '0'),
            volume24h: (string) ($data['volume'] ?? '0'),
            changePct24h: (string) ($data['priceChangePercent'] ?? '0'),
            timestamp: (int) ($data['closeTime'] ?? $this->currentTimestampMs()),
        );
    }

    public function getKline(string $symbol, string $interval, int $limit = 500): array
    {
        $rows = $this->httpGet('/api/v3/klines', [
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => min($limit, 1000),
        ], weight: 2);

        $klines = [];
        foreach ((array) $rows as $row) {
            $klines[] = $this->mapRestKline($symbol, $interval, $row);
        }

        return $klines; // Binance 返回按 open_time 升序
    }

    /**
     * 交易对精度过滤器（LOT_SIZE / PRICE_FILTER / NOTIONAL）。
     *
     * Redis 缓存 24h，key: market:symbols:binance:{symbol}:filters
     *
     * @return array{step_size:string, tick_size:string, min_notional:string}
     */
    public function symbolFilters(string $symbol): array
    {
        $key = "market:symbols:binance:{$symbol}:filters";
        $cached = Redis::connection('default')->get($key);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $data = $this->httpGet('/api/v3/exchangeInfo', ['symbol' => $symbol], weight: 20);
        $filters = collect($data['symbols'][0]['filters'] ?? [])->keyBy('filterType');

        $result = [
            'step_size' => (string) ($filters->get('LOT_SIZE')['stepSize'] ?? '0.00000001'),
            'tick_size' => (string) ($filters->get('PRICE_FILTER')['tickSize'] ?? '0.01'),
            'min_notional' => (string) ($filters->get('NOTIONAL')['minNotional']
                ?? $filters->get('MIN_NOTIONAL')['minNotional'] ?? '5'),
        ];

        Redis::connection('default')->setex($key, 86400, json_encode($result));

        return $result;
    }

    /**
     * 按精度步长向下取整（LOT_SIZE 对齐）。
     */
    public function alignToStep(string $value, string $step): string
    {
        if (bccomp($step, '0', 12) === 0) {
            return $value;
        }

        $quotient = bcdiv($value, $step, 0);

        return $this->trimZeros(bcmul($quotient, $step, 8));
    }

    // ------------------------------------------------------------------
    // 账户与交易（签名接口）
    // ------------------------------------------------------------------

    public function getBalance(): ExchangeBalance
    {
        $data = $this->httpSigned('GET', '/api/v3/account', weight: 20);

        $assets = [];
        foreach ((array) ($data['balances'] ?? []) as $b) {
            $total = bcadd((string) ($b['free'] ?? '0'), (string) ($b['locked'] ?? '0'), 8);
            if (bccomp($total, '0', 8) === 1) {
                $assets[(string) $b['asset']] = [
                    'total' => $this->trimZeros($total),
                    'available' => $this->trimZeros((string) ($b['free'] ?? '0')),
                ];
            }
        }

        $usdt = $assets['USDT']['total'] ?? '0';

        return new ExchangeBalance(
            totalEquity: $usdt,
            totalMargin: '0',
            available: $assets['USDT']['available'] ?? '0',
            unrealizedPnl: '0',
            assets: $assets,
        );
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
        $params = [
            'symbol' => $symbol,
            'side' => strtoupper($side),
            'type' => strtoupper($type),
        ];

        $clientOrderId = $opts['clientOrderId'] ?? null;
        if (is_string($clientOrderId) && $clientOrderId !== '') {
            $params['newClientOrderId'] = $clientOrderId;
        }

        // 现货市价买单支持 quoteOrderQty（按计价金额买入，规避 base 数量精度问题）
        if ($type === 'market' && $side === 'buy' && ! empty($opts['quoteOrderQty'])) {
            $params['quoteOrderQty'] = $quantity;
        } else {
            $params['quantity'] = $quantity;
        }

        if ($type === 'limit') {
            $params['timeInForce'] = 'GTC';
            $params['price'] = (string) $price;
        }

        $data = $this->httpSigned('POST', '/api/v3/order', $params, weight: 1);

        return $this->mapOrderResponse($data, $symbol, $side, $type, $quantity, $price, $stopPrice, $opts);
    }

    public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool
    {
        $params = ['symbol' => $symbol];
        if ($clientOrderId !== null) {
            $params['origClientOrderId'] = $clientOrderId;
        } else {
            $params['orderId'] = $orderId;
        }

        try {
            $this->httpSigned('DELETE', '/api/v3/order', $params, weight: 1);

            return true;
        } catch (ExchangeApiException $e) {
            // -2011 Unknown order（已成交/已撤销）视为撤销失败，不重试
            if (str_contains($e->getMessage(), '-2011')) {
                return false;
            }

            throw $e;
        }
    }

    public function getOrder(string $symbol, string $orderId): ExchangeOrder
    {
        $data = $this->httpSigned('GET', '/api/v3/order', [
            'symbol' => $symbol,
            'orderId' => $orderId,
        ], weight: 2);

        return $this->mapOrderResponse(
            $data,
            $symbol,
            (string) ($data['side'] ?? 'buy'),
            strtolower((string) ($data['type'] ?? 'market')),
            (string) ($data['origQty'] ?? '0'),
            $data['price'] !== '0.00000000' ? (string) $data['price'] : null,
            null,
            [],
        );
    }

    /**
     * 按 clientOrderId 查询订单（幂等校验用）。
     */
    public function getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder
    {
        try {
            $data = $this->httpSigned('GET', '/api/v3/order', [
                'symbol' => $symbol,
                'origClientOrderId' => $clientOrderId,
            ], weight: 2);
        } catch (ExchangeApiException $e) {
            if (str_contains($e->getMessage(), '-2013')) { // Order does not exist
                return null;
            }

            throw $e;
        }

        return $this->mapOrderResponse(
            $data,
            $symbol,
            (string) ($data['side'] ?? 'buy'),
            strtolower((string) ($data['type'] ?? 'market')),
            (string) ($data['origQty'] ?? '0'),
            isset($data['price']) && $data['price'] !== '0.00000000' ? (string) $data['price'] : null,
            null,
            [],
        );
    }

    // ------------------------------------------------------------------
    // WebSocket
    // ------------------------------------------------------------------

    public function subscribeKlineStream(
        string $symbol,
        string $interval,
        callable $onBar,
        ?callable $onTick = null,
    ): string {
        $stream = strtolower($symbol) . '@kline_' . $interval;
        $subscriptionId = 'binance-spot-' . (++$this->subCounter) . '-' . uniqid();

        $cid = Coroutine::create(function () use ($stream, $subscriptionId, $onBar, $onTick): void {
            $backoff = (int) config('exchange.ws_reconnect.initial_delay', 1);
            $maxBackoff = (int) config('exchange.ws_reconnect.max_delay', 60);
            $heartbeatTimeout = (int) config('exchange.ws_reconnect.heartbeat_timeout', 30);

            while (true) {
                // 踩坑（P1 POC）：Coroutine::cancel 非 sticky——recv 失败/异常被下方
                // catch(Throwable) 吞掉后循环会重连，协程永远退不出去。必须在每个
                // 分支显式检查 isCanceled() 并 return（配合 unsubscribeStream）。
                if (Coroutine::isCanceled()) {
                    return;
                }

                try {
                    $this->runKlineWsLoop($stream, $onBar, $onTick, $heartbeatTimeout);
                    $backoff = 1; // 正常退出（理论不可达）重置退避
                } catch (Coroutine\CancelException) {
                    return; // unsubscribeStream 主动取消
                } catch (Throwable $e) {
                    if (Coroutine::isCanceled()) {
                        return;
                    }

                    Log::warning('Binance kline WS disconnected, reconnecting', [
                        'subscription' => $subscriptionId,
                        'stream' => $stream,
                        'error' => $e->getMessage(),
                        'backoff' => $backoff,
                    ]);
                }

                if (Coroutine::isCanceled()) {
                    return;
                }

                // 断线退避重连
                Coroutine::sleep($backoff);
                if (Coroutine::isCanceled()) {
                    return;
                }
                $backoff = min($backoff * 2, $maxBackoff);
            }
        });

        $this->subscriptions[$subscriptionId] = ['cid' => $cid, 'streams' => $stream];

        return $subscriptionId;
    }

    public function unsubscribeStream(string $subscriptionId): void
    {
        $sub = $this->subscriptions[$subscriptionId] ?? null;
        if ($sub === null) {
            return;
        }

        unset($this->subscriptions[$subscriptionId]);
        if (Coroutine::exists($sub['cid'])) {
            Coroutine::cancel($sub['cid']);
        }
    }

    /**
     * 大户爆仓流是合约接口（fapi !forceOrder@arr），现货适配器不实现。
     */
    public function subscribeLiquidationStream(callable $onLiquidation): string
    {
        throw new \LogicException('Liquidation stream is futures-only. Use BinanceFuturesAdapter (P5).');
    }

    /**
     * 单次 WS 连接循环（断开时抛异常，由外层退避重连）。
     *
     * @param callable(ExchangeKline):void $onBar
     * @param callable(ExchangeTicker):void|null $onTick
     */
    private function runKlineWsLoop(string $stream, callable $onBar, ?callable $onTick, int $heartbeatTimeout): void
    {
        $url = $this->wsBaseUrl();
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw new \RuntimeException("Invalid WS URL: {$url}");
        }

        $ssl = ($parts['scheme'] ?? 'wss') === 'wss';
        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? ($ssl ? 443 : 80));
        $path = ($parts['path'] ?? '/stream') . '?streams=' . $stream;

        $client = new WsClient($host, $port, $ssl);
        $client->set([
            'timeout' => $heartbeatTimeout,
            'keep_alive' => true,
        ]);

        if (! $client->upgrade($path)) {
            throw new \RuntimeException("WS upgrade failed: {$host}:{$port}{$path} err={$client->errCode}");
        }

        while (true) {
            $frame = $client->recv($heartbeatTimeout);
            if ($frame === false) {
                throw new \RuntimeException("WS recv timeout/disconnect: err={$client->errCode}");
            }

            if ($frame->opcode !== WEBSOCKET_OPCODE_TEXT || $frame->data === null || $frame->data === '') {
                continue;
            }

            $payload = json_decode((string) $frame->data, true);
            if (! is_array($payload)) {
                continue;
            }

            // combined stream（/stream）返回 {stream, data}；单流（/ws）直接是事件体
            $data = $payload['data'] ?? $payload;
            if (! is_array($data) || ($data['e'] ?? '') !== 'kline') {
                continue;
            }

            $k = (array) ($data['k'] ?? []);
            if ($k === []) {
                continue;
            }

            $kline = $this->mapWsKline($data['s'] ?? '', $k);
            $isClosed = (bool) ($k['x'] ?? false);

            if ($isClosed) {
                $onBar($kline);
            } elseif ($onTick !== null) {
                // K 线未闭 tick：用 close 近似 lastPrice（网格策略仅用内存态，够用）
                $onTick(new ExchangeTicker(
                    symbol: (string) ($data['s'] ?? ''),
                    lastPrice: (string) ($k['c'] ?? '0'),
                    bidPrice: '0',
                    askPrice: '0',
                    volume24h: (string) ($k['q'] ?? '0'),
                    changePct24h: '0',
                    timestamp: (int) ($data['E'] ?? $this->currentTimestampMs()),
                ));
            }
        }
    }

    // ------------------------------------------------------------------
    // 映射辅助
    // ------------------------------------------------------------------

    /**
     * REST klines 行 → ExchangeKline。
     * 行结构：[openTime, open, high, low, close, volume, closeTime, quoteVolume, trades, ...]
     */
    private function mapRestKline(string $symbol, string $interval, mixed $row): ExchangeKline
    {
        $row = (array) $row;

        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: $symbol,
            interval: $interval,
            openTime: (int) $row[0],
            closeTime: (int) $row[6],
            open: (string) $row[1],
            high: (string) $row[2],
            low: (string) $row[3],
            close: (string) $row[4],
            volume: (string) $row[5],
            quoteVolume: (string) $row[7],
            tradesCount: (int) $row[8],
        );
    }

    /**
     * WS kline 事件 k 字段 → ExchangeKline。
     */
    private function mapWsKline(string $symbol, array $k): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: $symbol,
            interval: (string) ($k['i'] ?? ''),
            openTime: (int) ($k['t'] ?? 0),
            closeTime: (int) ($k['T'] ?? 0),
            open: (string) ($k['o'] ?? '0'),
            high: (string) ($k['h'] ?? '0'),
            low: (string) ($k['l'] ?? '0'),
            close: (string) ($k['c'] ?? '0'),
            volume: (string) ($k['v'] ?? '0'),
            quoteVolume: (string) ($k['q'] ?? '0'),
            tradesCount: (int) ($k['n'] ?? 0),
        );
    }

    /**
     * 统一订单响应 → ExchangeOrder DTO。
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $opts
     */
    private function mapOrderResponse(
        array $data,
        string $symbol,
        string $side,
        string $type,
        string $quantity,
        ?string $price,
        ?string $stopPrice,
        array $opts,
    ): ExchangeOrder {
        $status = $this->mapOrderStatus((string) ($data['status'] ?? 'NEW'));

        $filledQty = (string) ($data['executedQty'] ?? '0');
        $cummulativeQuote = (string) ($data['cummulativeQuoteQty'] ?? '0');
        $avgFillPrice = bccomp($filledQty, '0', 8) === 1
            ? $this->trimZeros(bcdiv($cummulativeQuote, $filledQty, 8))
            : null;

        $fee = '0';
        foreach ((array) ($data['fills'] ?? []) as $fill) {
            $fee = bcadd($fee, (string) ($fill['commission'] ?? '0'), 8);
        }

        $updateTimeMs = isset($data['updateTime']) ? (int) $data['updateTime'] : null;

        return new ExchangeOrder(
            exchangeOrderId: (string) ($data['orderId'] ?? '0'),
            clientOrderId: (string) ($data['clientOrderId'] ?? ($opts['clientOrderId'] ?? '')),
            symbol: $symbol,
            side: strtolower($side),
            type: strtolower($type),
            quantity: $quantity,
            price: $price,
            stopPrice: $stopPrice,
            leverage: isset($opts['leverage']) ? (int) $opts['leverage'] : null,
            reduceOnly: (bool) ($opts['reduceOnly'] ?? false),
            status: $status,
            filledQty: $this->trimZeros($filledQty),
            avgFillPrice: $avgFillPrice,
            fee: $this->trimZeros($fee),
            placedAt: isset($data['transactTime']) ? (int) (((int) $data['transactTime']) / 1000) : null,
            filledAt: $status === 'filled' && $updateTimeMs !== null ? (int) ($updateTimeMs / 1000) : null,
        );
    }

    private function mapOrderStatus(string $binanceStatus): string
    {
        return match ($binanceStatus) {
            'NEW', 'PENDING_NEW' => 'submitted',
            'PARTIALLY_FILLED' => 'partial',
            'FILLED' => 'filled',
            'CANCELED', 'PENDING_CANCEL', 'EXPIRED', 'EXPIRED_IN_MATCH' => 'canceled',
            'REJECTED' => 'rejected',
            default => 'failed',
        };
    }

    private function trimZeros(string $value): string
    {
        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
