<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\Exceptions\ExchangeApiException;
use App\Domain\Exchange\Signing\BitgetRequestSigner;
use App\Domain\Exchange\Signing\RequestSigner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as WsClient;
use Throwable;

/**
 * Bitget 现货适配器（V2 API）。
 *
 * REST: https://api.bitget.com（无公开 testnet/模拟盘，澄清决定 A / FR-013）
 * WS:   wss://ws.bitget.com/v2/ws/public（公共流无需 login）
 *
 * 特点：V2 统一现货接口；三段凭据（api_key/api_secret/passphrase，同 OKX 构，FR-006）；
 * symbol 即平台格式 BTCUSDT 无需归一化（FR-011）；签名 prehash=
 * timestamp(ms 串)+METHOD+requestPath(+?queryString)+body → base64（Signing/BitgetRequestSigner，R-002）；
 * WS 心跳为文本帧 ping/pong（非 JSON op 帧，R-006）；kline 流无官方 bar-close 标志
 * （bar 切换检测判定，本 spec 最大技术风险点，R-006）。
 *
 * US1 实装：getTicker / getKline / symbolFilters / alignToStep / subscribeLiquidationStream（占位）。
 * US2 实装：subscribeKlineStream / unsubscribeStream / runKlineWsLoop / mapWsKlinePayload（bar 切换检测）。
 * US3 实装：getBalance / placeOrder / cancelOrder / getOrder / getOrderByClientOrderId（双查 R-005）
 *           + requestSigner() 策略（Signing/BitgetRequestSigner）。
 */
class BitgetAdapter extends AbstractExchangeAdapter
{
    private int $subCounter = 0;

    /** @var array<string,array{cid:int,channel:string,symbol:string}> subscriptionId → 协程信息 */
    private array $subscriptions = [];

    protected function exchangeCode(): string
    {
        return 'bitget';
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
    // 业务错误码抽象点（FR-009，R-008 候选分类表：码段全部候选待核，数据驱动结构先行）
    // ------------------------------------------------------------------

    /**
     * Bitget V2 业务错误结构：HTTP 200 + code != '00000'（"00000" 成功，已核实）。
     *
     * @param  array<string,mixed>  $responseBody
     */
    protected function extractErrorCode(array $responseBody): ?string
    {
        $code = (string) ($responseBody['code'] ?? '');

        return $code !== '' && $code !== '00000' ? $code : null;
    }

    /**
     * Bitget V2 code 二分类（R-008 候选分类表，实现期对照官方错误码页逐条核对）：
     * 认证/参数/时钟/订单不存在 → client 硬失败；限流/服务器/未知 → server 保守可重试。
     */
    protected function translateErrorCode(string $rawCode, int $httpStatus): string
    {
        return match ($rawCode) {
            '40001', // 参数非法
            '40009', // 签名不匹配
            '40011', // passphrase 错误
            '40012', // API key 无效
            '40013', // timestamp 超窗（时钟硬失败 + 校准提示，与 Bybit 10002 语义对齐）
            '40014', // 权限不足
            '40018', // IP 白名单不匹配
            '43001', // 订单不存在（cancelOrder 特判返 false / 双查兜底依赖）
            => 'client',
            '40725' => 'server', // 触发限流（可重试）
            default => 'server', // 未知码保守可重试（与前三家未知码策略一致）
        };
    }

    /**
     * 业务错误消息（候选 40013 附加时钟校准提示，与 Bybit 10002 语义对齐）。
     *
     * @param  array<string,mixed>  $responseBody
     */
    protected function businessErrorMessage(string $rawCode, array $responseBody): string
    {
        $message = "code={$rawCode} msg=".(string) ($responseBody['msg'] ?? '');

        if ($rawCode === '40013') {
            $message .= '（code 40013：本机与 Bitget 服务器时钟漂移超过容忍窗口，'
                .'请校准系统时间/NTP 同步后重试）';
        }

        return $message;
    }

    // ------------------------------------------------------------------
    // 映射基础设施（T003，US1~US3 公共前置）
    // ------------------------------------------------------------------

    /**
     * 平台 interval → Bitget V2 REST granularity（R-004 双形态之一）。
     *
     * 1m/5m/15m/30m/1h/4h/1d → 1min/5min/15min/30min/1h/4h/1day。
     * 3m/2h/6h/12h/1w 候选不支持，越界抛 ExchangeApiException（clientError）。
     */
    private function toBitgetGranularity(string $interval): string
    {
        return match ($interval) {
            '1m' => '1min',
            '5m' => '5min',
            '15m' => '15min',
            '30m' => '30min',
            '1h' => '1h',
            '4h' => '4h',
            '1d' => '1day',
            default => throw ExchangeApiException::clientError(
                'bitget',
                "interval {$interval}",
                400,
                "Unsupported Bitget spot interval: {$interval}",
            ),
        };
    }

    /**
     * 平台 interval → Bitget V2 WS candle 频道名（R-004 双形态之二）。
     *
     * 1m/5m/15m/30m/1h/4h/1d → candle1m/candle5m/candle15m/candle30m/candle1h/candle4h/candle1day。
     * 注意与 REST 形态不同（1m 系为 m 后缀非 min），越界抛 ExchangeApiException。
     */
    private function toBitgetCandleChannel(string $interval): string
    {
        return match ($interval) {
            '1m' => 'candle1m',
            '5m' => 'candle5m',
            '15m' => 'candle15m',
            '30m' => 'candle30m',
            '1h' => 'candle1h',
            '4h' => 'candle4h',
            '1d' => 'candle1day',
            default => throw ExchangeApiException::clientError(
                'bitget',
                "interval {$interval}",
                400,
                "Unsupported Bitget spot candle channel: {$interval}",
            ),
        };
    }

    /**
     * Bitget 订单状态 → 平台 status 枚举（data-model.md State Transitions，原始值集候选待核）。
     *
     * new/init→submitted、partially_filled→partial、filled→filled、cancelled→canceled、
     * failed 等其他→failed。输出落在 ExchangeOrder.status 七值枚举内。
     */
    protected function mapOrderStatus(string $bitgetStatus): string
    {
        return match ($bitgetStatus) {
            'new', 'init' => 'submitted',
            'partially_filled' => 'partial',
            'filled' => 'filled',
            'cancelled' => 'canceled',
            default => 'failed', // failed/rejected 等异常状态
        };
    }

    /**
     * Bitget 订单响应 → ExchangeOrder DTO（data-model.md ExchangeOrder 表，字段名候选待核）。
     *
     * 字段映射：orderId→exchangeOrderId、clientOid→clientOrderId（回退 opts）、
     * lower(side/orderType)、size→quantity、baseVolume→filledQty、priceAvg→avgFillPrice、
     * fee、cTime/1000→placedAt、uTime/1000→filledAt（status=filled 时）。
     *
     * @param  array<string,mixed>  $data  place-order data / orderInfo / history-orders data[i]
     * @param  array<string,mixed>  $opts
     */
    protected function mapOrderResponse(
        array $data,
        string $symbol,
        string $side,
        string $type,
        string $quantity,
        ?string $price,
        ?string $stopPrice,
        array $opts,
    ): ExchangeOrder {
        $status = $this->mapOrderStatus((string) ($data['status'] ?? ''));

        $cTimeMs = isset($data['cTime']) && (string) $data['cTime'] !== ''
            ? (int) $data['cTime']
            : null;
        $uTimeMs = isset($data['uTime']) && (string) $data['uTime'] !== ''
            ? (int) $data['uTime']
            : null;

        $avgFillPrice = (string) ($data['priceAvg'] ?? '');

        return new ExchangeOrder(
            exchangeOrderId: (string) ($data['orderId'] ?? '0'),
            clientOrderId: (string) ($data['clientOid'] ?? ($opts['clientOrderId'] ?? '')),
            symbol: (string) ($data['symbol'] ?? $symbol),
            side: strtolower((string) ($data['side'] ?? $side)),
            type: strtolower((string) ($data['orderType'] ?? $type)),
            quantity: (string) ($data['size'] ?? $quantity),
            price: isset($data['price']) && (string) $data['price'] !== '' ? (string) $data['price'] : $price,
            stopPrice: $stopPrice,
            leverage: null, // 现货无杠杆
            reduceOnly: false,
            status: $status,
            filledQty: $this->trimZeros((string) ($data['baseVolume'] ?? '0')),
            avgFillPrice: $avgFillPrice !== '' ? $this->trimZeros($avgFillPrice) : null,
            fee: $this->trimZeros((string) ($data['fee'] ?? '0')),
            placedAt: $cTimeMs !== null ? intdiv($cTimeMs, 1000) : null,
            filledAt: $status === 'filled' && $uTimeMs !== null ? intdiv($uTimeMs, 1000) : null,
        );
    }

    // ------------------------------------------------------------------
    // 行情（US1）
    // ------------------------------------------------------------------

    /**
     * Bitget GET /api/v2/spot/market/tickers → ExchangeTicker。
     *
     * symbol 直传不转换（FR-011：Bitget 现货 symbol 即平台格式 BTCUSDT）；
     * lastPr/bidPr/askPr/baseVolume/change24h 直传（字段名候选待核）。
     */
    public function getTicker(string $symbol): ExchangeTicker
    {
        $data = $this->httpGet('/api/v2/spot/market/tickers', [
            'symbol' => $symbol,
        ], weight: 1);

        $row = (array) ($data['data'][0] ?? []);

        return new ExchangeTicker(
            symbol: (string) ($row['symbol'] ?? $symbol),
            lastPrice: (string) ($row['lastPr'] ?? '0'),
            bidPrice: (string) ($row['bidPr'] ?? '0'),
            askPrice: (string) ($row['askPr'] ?? '0'),
            volume24h: (string) ($row['baseVolume'] ?? '0'),
            changePct24h: (string) ($row['change24h'] ?? '0'),
            timestamp: isset($row['ts']) ? (int) $row['ts'] : $this->currentTimestampMs(),
        );
    }

    /**
     * Bitget GET /api/v2/spot/market/candles → ExchangeKline[]（按 openTime 升序）。
     *
     * data 元素为数组 [ts, open, high, low, close, baseVolume, quoteVolume]，
     * 排序方向候选待核——不盲信原始方向，统一 usort 按 openTime 升序输出（R-011）；
     * closeTime = openTime + intervalMs（bcmath 推算）；tradesCount = 0（R-011）；
     * limit 上限候选 1000。
     *
     * @return ExchangeKline[]
     */
    public function getKline(string $symbol, string $interval, int $limit = 500): array
    {
        $data = $this->httpGet('/api/v2/spot/market/candles', [
            'symbol' => $symbol,
            'granularity' => $this->toBitgetGranularity($interval),
            'limit' => min($limit, 1000),
        ], weight: 1);

        $intervalMs = $this->intervalMs($interval);
        $rows = (array) ($data['data'] ?? []);

        $klines = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $openTime = (int) ($row[0] ?? 0);
            $klines[] = new ExchangeKline(
                exchangeCode: 'bitget',
                symbol: $symbol,
                interval: $interval,
                openTime: $openTime,
                closeTime: (int) bcadd((string) $openTime, (string) $intervalMs, 0),
                open: (string) ($row[1] ?? '0'),
                high: (string) ($row[2] ?? '0'),
                low: (string) ($row[3] ?? '0'),
                close: (string) ($row[4] ?? '0'),
                volume: (string) ($row[5] ?? '0'),
                quoteVolume: (string) ($row[6] ?? '0'),
                tradesCount: 0,
            );
        }

        // R-011：排序方向候选待核——排序后升序输出，不盲信原始方向
        usort($klines, static fn (ExchangeKline $a, ExchangeKline $b): int => $a->openTime <=> $b->openTime);

        return $klines;
    }

    /**
     * Bitget 交易对精度过滤器（pricePrecision/quantityPrecision/minTradeUSDT）。
     *
     * Bitget V2 返回小数位而非步长——由位数反推步长（quantityPrecision=6 → 0.000001）；
     * Redis 缓存 24h，key: market:symbols:bitget:{symbol}:filters
     * 范式镜像 OkxAdapter::symbolFilters；constitution VII 用 phpredis 签名。
     *
     * @return array{step_size:string, tick_size:string, min_notional:string}
     */
    public function symbolFilters(string $symbol): array
    {
        $key = "market:symbols:bitget:{$symbol}:filters";
        $cached = Redis::connection('default')->get($key);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $data = $this->httpGet('/api/v2/spot/public/symbols', [
            'symbol' => $symbol,
        ], weight: 1);

        $row = (array) ($data['data'][0] ?? []);

        // 小数位 → 步长：quantityPrecision=6 → '0.000001'（0 位 → '1'）
        $quantityPrecision = max(0, (int) ($row['quantityPrecision'] ?? 8));
        $pricePrecision = max(0, (int) ($row['pricePrecision'] ?? 2));
        $stepSize = $quantityPrecision > 0 ? '0.'.str_repeat('0', $quantityPrecision - 1).'1' : '1';
        $tickSize = $pricePrecision > 0 ? '0.'.str_repeat('0', $pricePrecision - 1).'1' : '1';

        $result = [
            'step_size' => $stepSize,
            'tick_size' => $tickSize,
            'min_notional' => (string) ($row['minTradeUSDT'] ?? '5'),
        ];

        Redis::connection('default')->setex($key, 86400, json_encode($result));

        return $result;
    }

    /**
     * 按精度步长向下取整（quantityPrecision 步长对齐）。
     *
     * 镜像 BinanceSpotAdapter::alignToStep；constitution II 禁浮点。
     */
    public function alignToStep(string $value, string $step): string
    {
        if (bccomp($step, '0', 12) === 0) {
            return $value;
        }

        $quotient = bcdiv($value, $step, 0);

        return $this->trimZeros(bcmul($quotient, $step, 8));
    }

    /**
     * 大户爆仓流是合约接口，现货适配器不实现。
     */
    public function subscribeLiquidationStream(callable $onLiquidation): string
    {
        throw new \LogicException('Liquidation stream is futures-only.');
    }

    // ------------------------------------------------------------------
    // 账户与交易（US3）
    // ------------------------------------------------------------------

    /**
     * Bitget V2 签名策略（US3）：三段凭据（api_key/api_secret/passphrase，同 OKX 构，FR-006）。
     *
     * 基类在限流前调用签名器——凭据缺失等守卫前置抛 clientError，不占限流额度（constitution V）。
     */
    protected function requestSigner(): RequestSigner
    {
        return new BitgetRequestSigner();
    }

    /**
     * Bitget GET /api/v2/spot/account/assets → ExchangeBalance（R-010 现货余额近似）。
     *
     * 镜像 BinanceSpotAdapter::getBalance：available = USDT.available 直传；
     * totalEquity = bcmath bcadd(USDT.available, USDT.frozen)（现货无账户聚合字段）；
     * totalMargin/unrealizedPnl = '0'；assets 非零聚合 + trimZeros（零余额资产不入明细）。
     */
    public function getBalance(): ExchangeBalance
    {
        $data = $this->httpSigned('GET', '/api/v2/spot/account/assets', [], weight: 1);

        $assets = [];
        foreach ((array) ($data['data'] ?? []) as $asset) {
            $asset = (array) $asset;
            $coin = (string) ($asset['coin'] ?? '');
            if ($coin === '') {
                continue;
            }

            $available = (string) ($asset['available'] ?? '0');
            $frozen = (string) ($asset['frozen'] ?? '0');
            $total = bcadd($available, $frozen, 8);
            if (bccomp($total, '0', 8) !== 1) {
                continue; // 零余额资产不入明细（镜像 Binance 范式）
            }

            $assets[$coin] = [
                'total' => $this->trimZeros($total),
                'available' => $this->trimZeros($available),
            ];
        }

        $usdtAvail = $assets['USDT']['available'] ?? '0';
        $usdtTotal = $assets['USDT']['total'] ?? '0';

        return new ExchangeBalance(
            totalEquity: $usdtTotal, // R-010：现货无账户聚合字段，以 USDT 总余额近似（镜像 Binance）
            totalMargin: '0',
            available: $usdtAvail,
            unrealizedPnl: '0',
            assets: $assets,
        );
    }

    /**
     * Bitget POST /api/v2/spot/trade/place-order → ExchangeOrder。
     *
     * R-009：市价买 size=报价币（USDT）金额直传（无 marketUnit 类附加参数）；
     * 市价卖/限价单 size=base 数量；限价加 price + force='gtc'（枚举候选待核）；
     * clientOid 上限候选 64 字符——平台模板天然满足无需截断；symbol 直传（FR-011）。
     */
    public function placeOrder(
        string $symbol,
        string $side,
        string $type,
        string $quantity,
        ?string $price = null,
        ?string $stopPrice = null,
        array $opts = [],
    ): ExchangeOrder {
        $body = [
            'symbol' => $symbol,
            'side' => strtolower($side),
            'orderType' => strtolower($type),
            'size' => $quantity,
        ];

        // 限价单：price 必传 + force（候选 gtc，实现期核对官方文档）
        if (strtolower($type) === 'limit' && $price !== null) {
            $body['price'] = (string) $price;
            $body['force'] = 'gtc';
        }

        $clientOrderId = $opts['clientOrderId'] ?? null;
        if (is_string($clientOrderId) && $clientOrderId !== '') {
            $body['clientOid'] = $clientOrderId; // 上限候选 64 字符无需截断（R-009）
        }

        $data = $this->httpSigned('POST', '/api/v2/spot/trade/place-order', $body, weight: 1);

        // place-order 响应 data 仅含 orderId/clientOid（无 status/side 等）——受理即 new → submitted
        $row = (array) ($data['data'] ?? []);
        $row['status'] = (string) ($row['status'] ?? 'new');

        return $this->mapOrderResponse(
            $row,
            $symbol,
            $side,
            $type,
            $quantity,
            $price,
            $stopPrice,
            $opts,
        );
    }

    /**
     * Bitget POST /api/v2/spot/trade/cancel-order。
     *
     * 特判候选 43001（订单不存在/已成交/已撤）→ 返 false 不抛异常（R-008，US3 场景3，
     * 镜像 Bybit retCode=170213 特判）；其他错误原样抛出。
     */
    public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool
    {
        $body = ['symbol' => $symbol];
        if ($clientOrderId !== null && $clientOrderId !== '') {
            $body['clientOid'] = $clientOrderId;
        } else {
            $body['orderId'] = $orderId;
        }

        try {
            $this->httpSigned('POST', '/api/v2/spot/trade/cancel-order', $body, weight: 1);

            return true;
        } catch (ExchangeApiException $e) {
            // 候选 43001 订单不存在 → false 不抛（幂等语义）
            if (str_contains($e->getMessage(), 'code=43001')) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Bitget 查询订单：orderInfo 主查 → history-orders 兜底（R-005 双查幂等）。
     *
     * 两端皆未命中抛 ExchangeApiException（getOrder 强语义：订单必须存在）。
     */
    public function getOrder(string $symbol, string $orderId): ExchangeOrder
    {
        $row = $this->fetchOrderRow($symbol, ['orderId' => $orderId]);

        if ($row === null) {
            throw ExchangeApiException::clientError(
                'bitget',
                'GET /api/v2/spot/trade/orderInfo|history-orders',
                404,
                "order not found: {$orderId}",
            );
        }

        return $this->mapOrderResponse(
            $row,
            $symbol,
            strtolower((string) ($row['side'] ?? 'buy')),
            strtolower((string) ($row['orderType'] ?? 'market')),
            (string) ($row['size'] ?? '0'),
            isset($row['price']) && (string) $row['price'] !== '' ? (string) $row['price'] : null,
            null,
            [],
        );
    }

    /**
     * 按 clientOrderId 幂等查询（崩溃恢复核实用）。
     *
     * orderInfo 主查（候选 43001/空 data）→ history-orders 兜底 → 皆空返 null
     * （FR-005 + constitution IV：真不存在返回 null）。
     */
    public function getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder
    {
        $row = $this->fetchOrderRow($symbol, ['clientOid' => $clientOrderId]);

        if ($row === null) {
            return null;
        }

        return $this->mapOrderResponse(
            $row,
            $symbol,
            strtolower((string) ($row['side'] ?? 'buy')),
            strtolower((string) ($row['orderType'] ?? 'market')),
            (string) ($row['size'] ?? '0'),
            isset($row['price']) && (string) $row['price'] !== '' ? (string) $row['price'] : null,
            null,
            ['clientOrderId' => $clientOrderId],
        );
    }

    /**
     * R-005 双查实现：先 orderInfo（活跃单），候选 43001/空 data 回查 history-orders（终态单）；
     * 两端皆空返 null。
     *
     * @param  array<string,string>  $selector  ['orderId'=>..] 或 ['clientOid'=>..]
     * @return array<string,mixed>|null
     */
    private function fetchOrderRow(string $symbol, array $selector): ?array
    {
        foreach (['/api/v2/spot/trade/orderInfo', '/api/v2/spot/trade/history-orders'] as $path) {
            try {
                $data = $this->httpSigned('GET', $path, ['symbol' => $symbol] + $selector, weight: 1);
            } catch (ExchangeApiException $e) {
                // 候选 43001（订单不存在）→ 视为该端点未命中，继续兜底查询
                if (str_contains($e->getMessage(), 'code=43001')) {
                    continue;
                }

                throw $e;
            }

            $list = (array) ($data['data'] ?? []);
            if ($list !== []) {
                return (array) $list[0];
            }
        }

        return null;
    }

    public function subscribeKlineStream(
        string $symbol,
        string $interval,
        callable $onBar,
        ?callable $onTick = null,
    ): string {
        $channel = $this->toBitgetCandleChannel($interval);
        $subscriptionId = 'bitget-spot-'.(++$this->subCounter).'-'.uniqid();

        $cid = Coroutine::create(function () use ($symbol, $channel, $interval, $subscriptionId, $onBar, $onTick): void {
            $backoff = (int) config('exchange.ws_reconnect.initial_delay', 1);
            $maxBackoff = (int) config('exchange.ws_reconnect.max_delay', 60);
            $heartbeatTimeout = (int) config('exchange.ws_reconnect.heartbeat_timeout', 30);

            while (true) {
                // constitution III 坑2：cancel 非 sticky——每分支显式检查并 return
                if (Coroutine::isCanceled()) {
                    return;
                }

                try {
                    $this->runKlineWsLoop($symbol, $channel, $interval, $onBar, $onTick, $heartbeatTimeout);
                    $backoff = 1; // 正常退出（不预期）重置退避
                } catch (Coroutine\CancelException) {
                    return;
                } catch (Throwable $e) {
                    // 取消后不再重连（坑2）
                    if (Coroutine::isCanceled()) {
                        return;
                    }
                    Log::warning('Bitget kline WS disconnected, reconnecting', [
                        'subscription' => $subscriptionId,
                        'channel' => $channel,
                        'instId' => $symbol,
                        'error' => $e->getMessage(),
                        'backoff' => $backoff,
                    ]);
                }

                if (Coroutine::isCanceled()) {
                    return;
                }

                // 指数退避 1s→2s→4s…上限 60s
                Coroutine::sleep($backoff);

                if (Coroutine::isCanceled()) {
                    return;
                }

                $backoff = min($backoff * 2, $maxBackoff);
            }
        });

        $this->subscriptions[$subscriptionId] = [
            'cid' => $cid,
            'channel' => $channel,
            'symbol' => $symbol,
        ];

        return $subscriptionId;
    }

    public function unsubscribeStream(string $subscriptionId): void
    {
        $sub = $this->subscriptions[$subscriptionId] ?? null;

        if ($sub === null) {
            return; // 幂等：未知 id 静默返回（镜像前三家范式）
        }

        unset($this->subscriptions[$subscriptionId]);

        if (Coroutine::exists($sub['cid'])) {
            Coroutine::cancel($sub['cid']);
        }
    }

    // ------------------------------------------------------------------
    // WS kline 流内部（US2）
    // ------------------------------------------------------------------

    /**
     * kline WS 单连接事件循环（subscribeKlineStream 协程内调用）。
     *
     * Bitget V2 公共流（wss://ws.bitget.com/v2/ws/public，R-001 已核实）：
     *  - 订阅帧 args 为对象数组 [{"instType":"SPOT","channel":"candle1m","instId":"BTCUSDT"}]
     *  - 心跳：客户端每 25s 发文本帧字符串 ping，服务端回文本帧 pong（非 JSON op 帧，
     *    与 OKX/Bybit JSON op 帧心跳不同，R-006/analyze F5 定值 25s）
     *  - 推送帧：{action:"snapshot"|"update", arg:{...}, data:[[ts,O,H,L,C,baseVol,quoteVol]], ts}
     *
     * 每分支显式 Coroutine::isCanceled() 检查（constitution III 坑2）。
     */
    private function runKlineWsLoop(
        string $symbol,
        string $channel,
        string $interval,
        callable $onBar,
        ?callable $onTick,
        int $heartbeatTimeout,
    ): void {
        $url = (string) config('exchange.exchanges.bitget.ws_url');
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw new \RuntimeException("Invalid Bitget WS URL: {$url}");
        }

        $ssl = ($parts['scheme'] ?? 'wss') === 'wss';
        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? ($ssl ? 443 : 80));
        $path = ($parts['path'] ?? '/v2/ws/public');

        $client = new WsClient($host, $port, $ssl);
        // 1s 短超时轮询：心跳定时与取消检查得以周期执行（镜像前三家范式）
        $client->set(['timeout' => 1]);

        if (! $client->upgrade($path)) {
            throw new \RuntimeException("Bitget WS upgrade failed: {$host}:{$port}{$path} err={$client->errCode}");
        }

        // 连接成功后立即发送订阅帧（args 对象数组——与 Bybit 字符串数组不同，R-006）
        $client->push(json_encode([
            'op' => 'subscribe',
            'args' => [
                ['instType' => 'SPOT', 'channel' => $channel, 'instId' => $symbol],
            ],
        ], JSON_UNESCAPED_SLASHES));

        // bar 切换检测状态（R-006）：lastBarOpenTime + 上一 bar 缓存
        $state = ['lastBarOpenTime' => null, 'lastBar' => null];
        $lastPingAt = microtime(true);
        $lastAliveAt = microtime(true);

        while (true) {
            if (Coroutine::isCanceled()) {
                return;
            }

            $frame = $client->recv(1.0);
            if ($frame === false) {
                $now = microtime(true);

                // 文本帧 ping 心跳（R-006 定值 25s）
                if ($now - $lastPingAt >= 25) {
                    $client->push('ping');
                    $lastPingAt = $now;
                }

                // 超时未收到任何帧（含 pong）：抛异常触发上层重连
                if ($now - $lastAliveAt >= $heartbeatTimeout) {
                    throw new \RuntimeException(
                        "Bitget WS heartbeat timeout ({$heartbeatTimeout}s) or disconnect: err={$client->errCode}",
                    );
                }

                continue;
            }

            // 任意帧（含 pong）证明链路存活
            $lastAliveAt = microtime(true);

            if ($frame->opcode !== WEBSOCKET_OPCODE_TEXT || $frame->data === null || $frame->data === '') {
                continue;
            }

            $payloadRaw = (string) $frame->data;

            // 文本帧心跳：客户端 ping 的应答 pong / 服务端主动 ping 需回 pong（Bitget 特有）
            if ($payloadRaw === 'pong') {
                continue;
            }
            if ($payloadRaw === 'ping') {
                $client->push('pong');

                continue;
            }

            $payload = json_decode($payloadRaw, true);
            if (! is_array($payload)) {
                continue;
            }

            // 数据推送须含非空 data；订阅确认帧 {event:"subscribe"} 等无 data 跳过
            $data = $payload['data'] ?? null;
            if (! is_array($data) || $data === []) {
                continue;
            }

            $action = (string) ($payload['action'] ?? '');

            foreach ($data as $row) {
                if (Coroutine::isCanceled()) {
                    return;
                }

                $result = $this->processKlineFrame(
                    (array) $row,
                    $action,
                    $symbol,
                    $interval,
                    $state,
                );
                $state = $result['state'];

                if ($result['closedBar'] !== null) {
                    $onBar($result['closedBar']);
                } elseif ($result['liveBar'] !== null && $onTick !== null) {
                    // 未闭 bar 更新：用 close 近似 lastPrice（镜像前三家 onTick 范式）
                    $live = $result['liveBar'];
                    $onTick(new ExchangeTicker(
                        symbol: $live->symbol,
                        lastPrice: $live->close,
                        bidPrice: '0',
                        askPrice: '0',
                        volume24h: $live->quoteVolume,
                        changePct24h: '0',
                        timestamp: $live->openTime,
                    ));
                }
            }
        }
    }

    /**
     * WS 推送行 → ExchangeKline（data-model.md ExchangeKline 表）。
     *
     * data[0] 元素 [ts, open, high, low, close, baseVolume, quoteVolume]；
     * closeTime = openTime + intervalMs（bcmath 推算）；tradesCount = 0。
     *
     * @param  array<int,mixed>  $row
     */
    private function mapWsKlinePayload(array $row, string $symbol, string $interval): ExchangeKline
    {
        $openTime = (int) ($row[0] ?? 0);
        $intervalMs = $this->intervalMs($interval);

        return new ExchangeKline(
            exchangeCode: 'bitget',
            symbol: $symbol, // 直传（FR-011）
            interval: $interval,
            openTime: $openTime,
            closeTime: (int) bcadd((string) $openTime, (string) $intervalMs, 0),
            open: (string) ($row[1] ?? '0'),
            high: (string) ($row[2] ?? '0'),
            low: (string) ($row[3] ?? '0'),
            close: (string) ($row[4] ?? '0'),
            volume: (string) ($row[5] ?? '0'),
            quoteVolume: (string) ($row[6] ?? '0'),
            tradesCount: 0,
        );
    }

    /**
     * R-006 bar 切换检测（纯函数，T014 单测钩子；state 由调用方持有并回写）。
     *
     * Bitget kline 流无官方 bar-close 标志（与 OKX confirm / Bybit confirm 字段不同），
     * 用 bar 切换检测判定上一 bar 关闭：
     *  - 首帧/重订阅 action=snapshot：只播种 lastBarOpenTime 并缓存 bar 数据，不触发 onBar
     *  - update 帧 ts == lastBarOpenTime（同 bar 快照更新）：刷新缓存 bar 数据，不触发
     *  - update 帧 ts > lastBarOpenTime（bar 切换）：返回缓存的上一 bar 完整数据触发
     *    onBar 恰好一次，并推进 lastBarOpenTime、缓存新 bar
     *  - update 帧 ts < lastBarOpenTime（乱序/补发）：忽略，状态不变
     *
     * @param  array{lastBarOpenTime:int|null, lastBar:ExchangeKline|null}  $state
     * @param  array<int,mixed>  $row
     * @return array{state:array{lastBarOpenTime:int|null, lastBar:ExchangeKline|null}, closedBar:ExchangeKline|null, liveBar:ExchangeKline|null}
     */
    private function processKlineFrame(
        array $row,
        string $action,
        string $symbol,
        string $interval,
        array $state,
    ): array {
        $kline = $this->mapWsKlinePayload($row, $symbol, $interval);

        // 首帧/重订阅快照（或未播种的异常时序）：只播种不回调
        if ($action === 'snapshot' || $state['lastBarOpenTime'] === null) {
            return [
                'state' => ['lastBarOpenTime' => $kline->openTime, 'lastBar' => $kline],
                'closedBar' => null,
                'liveBar' => null,
            ];
        }

        if ($kline->openTime === $state['lastBarOpenTime']) {
            // 同 bar 快照更新：刷新缓存（保证关闭时用最新完整数据），不触发 onBar
            return [
                'state' => ['lastBarOpenTime' => $state['lastBarOpenTime'], 'lastBar' => $kline],
                'closedBar' => null,
                'liveBar' => $kline, // 未闭 bar tick（onTick 存在时由调用方触发）
            ];
        }

        if ($kline->openTime > $state['lastBarOpenTime']) {
            // bar 切换：上一 bar 已关闭——用缓存完整数据触发 onBar 恰好一次，并推进状态
            return [
                'state' => ['lastBarOpenTime' => $kline->openTime, 'lastBar' => $kline],
                'closedBar' => $state['lastBar'] ?? $kline,
                'liveBar' => null,
            ];
        }

        // 乱序/补发（ts < lastBarOpenTime）：忽略，状态不变
        return ['state' => $state, 'closedBar' => null, 'liveBar' => null];
    }

    // ------------------------------------------------------------------
    // 精度与时间辅助（private）
    // ------------------------------------------------------------------

    /**
     * interval 字符串（1m/5m/1h/1d...）→ 毫秒（closeTime 推算用）。
     *
     * 时间戳为整数毫秒（非金额），int 算术在 PHP int64 范围内安全。
     */
    private function intervalMs(string $interval): int
    {
        if (preg_match('/^(\d+)([smhdwMy])$/', $interval, $m)) {
            $unit = match ($m[2]) {
                's' => 1_000,
                'm' => 60_000,
                'h' => 3_600_000,
                'd' => 86_400_000,
                'w' => 604_800_000,
                'M' => 2_592_000_000,
                'y' => 31_536_000_000,
                default => 60_000,
            };

            return (int) $m[1] * $unit;
        }

        return 60_000;
    }

    /**
     * 去除尾随零与孤立小数点。
     */
    private function trimZeros(string $value): string
    {
        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
