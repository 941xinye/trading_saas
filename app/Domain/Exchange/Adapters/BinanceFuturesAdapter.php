<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\Contracts\LiquidationEvent;
use App\Domain\Exchange\Exceptions\ExchangeApiException;
use App\Domain\Exchange\Signing\BinanceRequestSigner;
use App\Domain\Exchange\Signing\RequestSigner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as WsClient;
use Throwable;

/**
 * 币安合约（U 本位永续）适配器。
 *
 * REST: https://fapi.binance.com（testnet: https://testnet.binancefuture.com）
 * WS:   wss://fstream.binance.com/ws（testnet: wss://stream.binancefuture.com/ws）
 *
 * 范围（spec 005）：
 *  - 行情：getTicker / getKline / subscribeKlineStream
 *  - 账户：getBalance / getPosition
 *  - 交易：placeOrder / cancelOrder / getOrder / getOrderByClientOrderId
 *  - 合约设置：setLeverage / setMarginType（私有，策略启动时调用）
 *  - 强平流：subscribeLiquidationStream（P5 已实现）
 *
 * 签名策略与现货相同（BinanceRequestSigner）。
 * 单向持仓模式（首期）：开仓/平仓通过 reduceOnly 区分。
 */
class BinanceFuturesAdapter extends AbstractExchangeAdapter
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
        return 'futures';
    }

    /**
     * 签名策略：与 BinanceSpotAdapter 同一策略（HMAC-SHA256）。
     */
    protected function requestSigner(): RequestSigner
    {
        return new BinanceRequestSigner();
    }

    /**
     * Binance 用标准 HTTP 状态码传错误，HTTP 200 响应体无业务错误码结构。
     */
    protected function extractErrorCode(array $responseBody): ?string
    {
        return null;
    }

    /**
     * Binance 业务错误码为负数（客户端语义）。
     * 合约特有码：-2026 reduceOnly 不兼容 / -2027 超杠杆名义上限 / -4028 无效杠杆 /
     *            -4067 持仓模式切换失败 / -4046 无需切换保证金模式。
     */
    protected function translateErrorCode(string $rawCode, int $httpStatus): string
    {
        return str_starts_with($rawCode, '-') ? 'client' : 'server';
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
        $data = $this->httpGet('/fapi/v1/ticker/price', ['symbol' => $symbol], weight: 1);

        return new ExchangeTicker(
            symbol: (string) ($data['symbol'] ?? $symbol),
            lastPrice: (string) ($data['price'] ?? '0'),
            bidPrice: '0',
            askPrice: '0',
            volume24h: '0',
            changePct24h: '0',
            timestamp: $this->currentTimestampMs(),
        );
    }

    public function getKline(string $symbol, string $interval, int $limit = 500): array
    {
        $rows = $this->httpGet('/fapi/v1/klines', [
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => min($limit, 1500),
        ], weight: 5);

        $klines = [];
        foreach ((array) $rows as $row) {
            $klines[] = $this->mapRestKline($symbol, $interval, $row);
        }

        return $klines; // Binance 返回按 open_time 升序
    }

    /**
     * 合约精度过滤器（LOT_SIZE / PRICE_FILTER / MIN_NOTIONAL）。
     *
     * Redis 缓存 24h，key: market:symbols:binance_futures:{symbol}:filters
     *
     * @return array{step_size:string, tick_size:string, min_notional:string}
     */
    public function symbolFilters(string $symbol): array
    {
        $key = "market:symbols:binance_futures:{$symbol}:filters";
        $cached = Redis::connection('default')->get($key);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $data = $this->httpGet('/fapi/v1/exchangeInfo', ['symbol' => $symbol], weight: 1);
        $filters = collect($data['symbols'][0]['filters'] ?? [])->keyBy('filterType');

        $result = [
            'step_size' => (string) ($filters->get('LOT_SIZE')['stepSize'] ?? '0.001'),
            'tick_size' => (string) ($filters->get('PRICE_FILTER')['tickSize'] ?? '0.01'),
            'min_notional' => (string) ($filters->get('MIN_NOTIONAL')['notional'] ?? '5'),
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
    // 账户与持仓（签名接口）
    // ------------------------------------------------------------------

    public function getBalance(): ExchangeBalance
    {
        $data = $this->httpSigned('GET', '/fapi/v2/account', weight: 5);

        $totalWallet = (string) ($data['totalWalletBalance'] ?? '0');
        $available = (string) ($data['availableBalance'] ?? $totalWallet);

        $assets = [];
        foreach ((array) ($data['assets'] ?? []) as $a) {
            $asset = (string) ($a['asset'] ?? '');
            $wallet = (string) ($a['walletBalance'] ?? '0');
            if ($asset !== '' && bccomp($wallet, '0', 8) === 1) {
                $assets[$asset] = [
                    'total' => $this->trimZeros($wallet),
                    'available' => $this->trimZeros((string) ($a['availableBalance'] ?? '0')),
                ];
            }
        }

        return new ExchangeBalance(
            totalEquity: $totalWallet,
            totalMargin: (string) ($data['totalMarginBalance'] ?? '0'),
            available: $available,
            unrealizedPnl: (string) ($data['totalUnrealizedProfit'] ?? '0'),
            assets: $assets,
        );
    }

    public function getPosition(string $symbol): ?ExchangePosition
    {
        $data = $this->httpSigned('GET', '/fapi/v2/positionRisk', ['symbol' => $symbol], weight: 5);

        // positionRisk 返回数组，取第一个非零持仓
        foreach ((array) $data as $row) {
            $posAmt = (string) ($row['positionAmt'] ?? '0');
            if (bccomp($posAmt, '0', 8) === 0) {
                continue;
            }

            return new ExchangePosition(
                symbol: (string) ($row['symbol'] ?? $symbol),
                side: bccomp($posAmt, '0', 8) === 1 ? 'long' : 'short',
                size: ltrim($posAmt, '-'),
                entryPrice: (string) ($row['entryPrice'] ?? '0'),
                markPrice: (string) ($row['markPrice'] ?? '0'),
                liquidationPrice: isset($row['liquidationPrice']) && $row['liquidationPrice'] !== '0'
                    ? (string) $row['liquidationPrice'] : null,
                leverage: (int) ($row['leverage'] ?? 1),
                unrealizedPnl: (string) ($row['unRealizedProfit'] ?? '0'),
                margin: (string) ($row['isolatedMargin'] ?? ($row['positionInitialMargin'] ?? '0')),
            );
        }

        return null;
    }

    // ------------------------------------------------------------------
    // 交易（签名接口）
    // ------------------------------------------------------------------

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
            'quantity' => $quantity,
        ];

        $clientOrderId = $opts['clientOrderId'] ?? null;
        if (is_string($clientOrderId) && $clientOrderId !== '') {
            $params['newClientOrderId'] = $clientOrderId;
        }

        if ($type === 'limit') {
            $params['timeInForce'] = 'GTC';
            $params['price'] = (string) $price;
        }

        // 合约特有参数
        if (! empty($opts['leverage'])) {
            $params['leverage'] = (int) $opts['leverage'];
        }
        if (! empty($opts['reduceOnly'])) {
            $params['reduceOnly'] = 'true';
        }

        $data = $this->httpSigned('POST', '/fapi/v1/order', $params, weight: 1);

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
            $this->httpSigned('DELETE', '/fapi/v1/order', $params, weight: 1);

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
        $data = $this->httpSigned('GET', '/fapi/v1/order', [
            'symbol' => $symbol,
            'orderId' => $orderId,
        ], weight: 1);

        return $this->mapOrderResponse(
            $data,
            $symbol,
            (string) ($data['side'] ?? 'buy'),
            strtolower((string) ($data['type'] ?? 'market')),
            (string) ($data['origQty'] ?? '0'),
            isset($data['price']) && $data['price'] !== '0' ? (string) $data['price'] : null,
            null,
            [],
        );
    }

    public function getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder
    {
        try {
            $data = $this->httpSigned('GET', '/fapi/v1/order', [
                'symbol' => $symbol,
                'origClientOrderId' => $clientOrderId,
            ], weight: 1);
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
            isset($data['price']) && $data['price'] !== '0' ? (string) $data['price'] : null,
            null,
            [],
        );
    }

    // ------------------------------------------------------------------
    // 合约设置（私有，策略启动时调用）
    // ------------------------------------------------------------------

    /**
     * 设置杠杆（POST /fapi/v1/leverage）。
     * 策略启动时调用一次；重复设置同杠杆为幂等。
     */
    public function setLeverage(string $symbol, int $leverage): void
    {
        try {
            $this->httpSigned('POST', '/fapi/v1/leverage', [
                'symbol' => $symbol,
                'leverage' => $leverage,
            ], weight: 1);
        } catch (ExchangeApiException $e) {
            // -4028 无效杠杆 / -4046 无需变更：记日志不阻塞
            Log::warning('Set leverage failed', [
                'symbol' => $symbol, 'leverage' => $leverage, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 设置逐仓模式（POST /fapi/v1/marginType）。
     * 首期固定 ISOLATED；已是 ISOLATED 时返回 -4046，幂等忽略。
     */
    public function setMarginType(string $symbol): void
    {
        try {
            $this->httpSigned('POST', '/fapi/v1/marginType', [
                'symbol' => $symbol,
                'marginType' => 'ISOLATED',
            ], weight: 1);
        } catch (ExchangeApiException $e) {
            if (! str_contains($e->getMessage(), '-4046')) {
                Log::warning('Set margin type failed', [
                    'symbol' => $symbol, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 获取杠杆档位与维持保证金率分档（GET /fapi/v1/leverageBracket）。
     * Redis 缓存 24h，key: futures:leverage_bracket:{symbol}。
     *
     * @return array<int,array{bracket:int,initialMarginRate:string,maintMarginRate:string,cap:int}>
     */
    public function getLeverageBracket(string $symbol): array
    {
        $key = "futures:leverage_bracket:{$symbol}";
        $cached = Redis::connection('default')->get($key);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $data = $this->httpSigned('GET', '/fapi/v1/leverageBracket', ['symbol' => $symbol], weight: 1);

        $brackets = [];
        foreach ((array) ($data[0]['brackets'] ?? []) as $b) {
            $brackets[] = [
                'bracket' => (int) ($b['bracket'] ?? 0),
                'initialMarginRate' => (string) ($b['initialMarginRate'] ?? '0'),
                'maintMarginRate' => (string) ($b['maintMarginRate'] ?? '0'),
                'cap' => (int) ($b['cap'] ?? 0),
            ];
        }

        Redis::connection('default')->setex($key, 86400, json_encode($brackets));

        return $brackets;
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
        $subscriptionId = 'binance-futures-' . (++$this->subCounter) . '-' . uniqid();

        $cid = Coroutine::create(function () use ($stream, $subscriptionId, $onBar, $onTick): void {
            $backoff = (int) config('exchange.ws_reconnect.initial_delay', 1);
            $maxBackoff = (int) config('exchange.ws_reconnect.max_delay', 60);
            $heartbeatTimeout = (int) config('exchange.ws_reconnect.heartbeat_timeout', 30);

            while (true) {
                if (Coroutine::isCanceled()) {
                    return;
                }

                try {
                    $this->runKlineWsLoop($stream, $onBar, $onTick, $heartbeatTimeout);
                    $backoff = 1;
                } catch (Coroutine\CancelException) {
                    return;
                } catch (Throwable $e) {
                    if (Coroutine::isCanceled()) {
                        return;
                    }

                    Log::warning('Binance futures kline WS disconnected, reconnecting', [
                        'subscription' => $subscriptionId,
                        'stream' => $stream,
                        'error' => $e->getMessage(),
                        'backoff' => $backoff,
                    ]);
                }

                if (Coroutine::isCanceled()) {
                    return;
                }

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

    public function subscribeLiquidationStream(callable $onLiquidation): string
    {
        $stream = '!forceOrder@arr';
        $subscriptionId = 'binance-futures-liq-' . (++$this->subCounter) . '-' . uniqid();

        $cid = Coroutine::create(function () use ($stream, $subscriptionId, $onLiquidation): void {
            $backoff = (int) config('exchange.ws_reconnect.initial_delay', 1);
            $maxBackoff = (int) config('exchange.ws_reconnect.max_delay', 60);
            $heartbeatTimeout = (int) config('exchange.ws_reconnect.heartbeat_timeout', 30);

            while (true) {
                if (Coroutine::isCanceled()) {
                    return;
                }

                try {
                    $this->runLiquidationWsLoop($stream, $onLiquidation, $heartbeatTimeout);
                    $backoff = 1;
                } catch (Coroutine\CancelException) {
                    return;
                } catch (Throwable $e) {
                    if (Coroutine::isCanceled()) {
                        return;
                    }

                    Log::warning('Binance liquidation WS disconnected, reconnecting', [
                        'subscription' => $subscriptionId,
                        'stream' => $stream,
                        'error' => $e->getMessage(),
                        'backoff' => $backoff,
                    ]);
                }

                if (Coroutine::isCanceled()) {
                    return;
                }

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
     * 单次 K 线 WS 连接循环（断开时抛异常，由外层退避重连）。
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
        // 合约 WS 使用单流模式 /ws/{stream}（与现货 combined /stream?streams= 不同）
        $path = '/ws/' . $stream;

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
            if (! is_array($payload) || ($payload['e'] ?? '') !== 'kline') {
                continue;
            }

            $k = (array) ($payload['k'] ?? []);
            if ($k === []) {
                continue;
            }

            $kline = $this->mapWsKline((string) ($payload['s'] ?? ''), $k);
            $isClosed = (bool) ($k['x'] ?? false);

            if ($isClosed) {
                $onBar($kline);
            } elseif ($onTick !== null) {
                $onTick(new ExchangeTicker(
                    symbol: (string) ($payload['s'] ?? ''),
                    lastPrice: (string) ($k['c'] ?? '0'),
                    bidPrice: '0',
                    askPrice: '0',
                    volume24h: (string) ($k['q'] ?? '0'),
                    changePct24h: '0',
                    timestamp: (int) ($payload['E'] ?? $this->currentTimestampMs()),
                ));
            }
        }
    }

    /**
     * 单次强平流 WS 连接循环。
     *
     * @param callable(LiquidationEvent):void $onLiquidation
     */
    private function runLiquidationWsLoop(string $stream, callable $onLiquidation, int $heartbeatTimeout): void
    {
        $url = $this->wsBaseUrl();
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw new \RuntimeException("Invalid WS URL: {$url}");
        }

        $ssl = ($parts['scheme'] ?? 'wss') === 'wss';
        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? ($ssl ? 443 : 80));
        $path = '/ws/' . $stream;

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
            if (! is_array($payload) || ($payload['e'] ?? '') !== 'forceOrder') {
                continue;
            }

            $o = $payload['o'] ?? null;
            if (! is_array($o)) {
                continue;
            }

            try {
                $event = LiquidationEvent::fromBinancePayload($o, (int) ($payload['E'] ?? 0));
                if ($event->symbol === '' || bccomp($event->price, '0', 8) !== 1) {
                    continue;
                }
                $onLiquidation($event);
            } catch (Throwable $e) {
                Log::warning('Liquidation callback failed', [
                    'symbol' => $o['s'] ?? '',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // 映射辅助
    // ------------------------------------------------------------------

    /**
     * REST klines 行 → ExchangeKline。
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
        $avgPrice = (string) ($data['avgPrice'] ?? '0');
        $avgFillPrice = bccomp($filledQty, '0', 8) === 1 && bccomp($avgPrice, '0', 8) === 1
            ? $this->trimZeros($avgPrice)
            : null;

        $fee = '0';
        // 合约订单响应无 fills 字段（成交明细在 user data stream），用 cumQuote 反推不可靠，fee 暂记 0
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
            placedAt: isset($data['updateTime']) ? (int) (((int) $data['updateTime']) / 1000) : null,
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
