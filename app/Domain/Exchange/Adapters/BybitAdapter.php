<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\Exceptions\ExchangeApiException;
use App\Domain\Exchange\Signing\BybitRequestSigner;
use App\Domain\Exchange\Signing\RequestSigner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as WsClient;
use Throwable;

/**
 * Bybit 现货适配器（V5 API）。
 *
 * REST: https://api.bybit.com（testnet: https://api-testnet.bybit.com，独立域名整体切换，无 header 标识）
 * WS:   wss://stream.bybit.com/v5/public/spot（testnet: wss://stream-testnet.bybit.com/v5/public/spot）
 *
 * 特点：V5 统一现货/合约；两段凭据（api_key/api_secret，无 passphrase，FR-007）；
 * symbol 即平台格式 BTCUSDT 无需归一化（FR-012）；签名 prehash=
 * timestamp+api_key+recv_window+payload → hex（Signing/BybitRequestSigner，R-002）。
 *
 * US1 实装：getTicker / getKline / symbolFilters / alignToStep / subscribeLiquidationStream（占位）。
 * US2 实装：subscribeKlineStream / unsubscribeStream / runKlineWsLoop / mapWsKlinePayload。
 * US3 实装：getBalance / placeOrder / cancelOrder / getOrder / getOrderByClientOrderId（双端点 R-005）。
 */
class BybitAdapter extends AbstractExchangeAdapter
{
    private int $subCounter = 0;

    /** @var array<string,array{cid:int,topic:string,symbol:string}> subscriptionId → 协程信息 */
    private array $subscriptions = [];

    protected function exchangeCode(): string
    {
        return 'bybit';
    }

    protected function market(): string
    {
        return 'spot';
    }

    public function withCredentials(array $credentials): static
    {
        return new static($this->rateLimiter, $this->healthChecker, $credentials);
    }

    /**
     * 签名策略（FR-008）：Bybit V5 prehash=timestamp+api_key+recv_window+payload → hex，
     * X-BAPI-* 五件套（Signing/BybitRequestSigner）。
     */
    protected function requestSigner(): RequestSigner
    {
        return new BybitRequestSigner();
    }

    // ------------------------------------------------------------------
    // 行情（US1）
    // ------------------------------------------------------------------

    /**
     * Bybit GET /v5/market/tickers?category=spot → ExchangeTicker。
     *
     * symbol 直传不转换（FR-012：Bybit 现货 symbol 即平台格式 BTCUSDT）；
     * price24hPcnt 直传为 changePct24h（v5 返回小数比率，无需计算，data-model.md）。
     */
    public function getTicker(string $symbol): ExchangeTicker
    {
        $data = $this->httpGet('/v5/market/tickers', [
            'category' => 'spot',
            'symbol' => $symbol,
        ], weight: 1);

        $row = (array) (($data['result']['list'][0] ?? []));

        return new ExchangeTicker(
            symbol: (string) ($row['symbol'] ?? $symbol),
            lastPrice: (string) ($row['lastPrice'] ?? '0'),
            bidPrice: (string) ($row['bid1Price'] ?? '0'),
            askPrice: (string) ($row['ask1Price'] ?? '0'),
            volume24h: (string) ($row['volume24h'] ?? '0'),
            changePct24h: (string) ($row['price24hPcnt'] ?? '0'),
            timestamp: (int) ($data['time'] ?? $this->currentTimestampMs()),
        );
    }

    /**
     * Bybit GET /v5/market/kline → ExchangeKline[]（按 openTime 升序）。
     *
     * v5 result.list 倒序（最新在前）→ array_reverse 反转为升序（R-012）；
     * 元素 [startTime, open, high, low, close, volume, turnover]；
     * closeTime = openTime + intervalMs（v5 不返 closeTime，bcmath 推算）；
     * quoteVolume = turnover；tradesCount = 0（v5 不返）；limit 上限 1000（R-003）。
     *
     * @return ExchangeKline[]
     */
    public function getKline(string $symbol, string $interval, int $limit = 500): array
    {
        $data = $this->httpGet('/v5/market/kline', [
            'category' => 'spot',
            'symbol' => $symbol,
            'interval' => $this->toBybitInterval($interval),
            'limit' => min($limit, 1000),
        ], weight: 1);

        $intervalMs = $this->intervalMs($interval);
        $rows = (array) ($data['result']['list'] ?? []);

        $klines = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $openTime = (int) ($row[0] ?? 0);
            $klines[] = new ExchangeKline(
                exchangeCode: 'bybit',
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

        return array_reverse($klines);
    }

    /**
     * Bybit 交易对精度过滤器（priceFilter.tickSize / lotSizeFilter.basePrecision / minOrderAmt）。
     *
     * Redis 缓存 24h，key: market:symbols:bybit:{symbol}:filters
     * 范式镜像 OkxAdapter::symbolFilters；constitution VII 用 phpredis 签名。
     *
     * @return array{step_size:string, tick_size:string, min_notional:string}
     */
    public function symbolFilters(string $symbol): array
    {
        $key = "market:symbols:bybit:{$symbol}:filters";
        $cached = Redis::connection('default')->get($key);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $data = $this->httpGet('/v5/market/instruments-info', [
            'category' => 'spot',
            'symbol' => $symbol,
        ], weight: 1);

        $row = (array) (($data['result']['list'][0] ?? []));
        $lotSizeFilter = (array) ($row['lotSizeFilter'] ?? []);
        $priceFilter = (array) ($row['priceFilter'] ?? []);

        $result = [
            'step_size' => (string) ($lotSizeFilter['basePrecision'] ?? '0.00000001'),
            'tick_size' => (string) ($priceFilter['tickSize'] ?? '0.01'),
            'min_notional' => (string) ($lotSizeFilter['minOrderAmt'] ?? '0'),
        ];

        Redis::connection('default')->setex($key, 86400, json_encode($result));

        return $result;
    }

    /**
     * 按精度步长向下取整（basePrecision 对齐）。
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
        throw new \LogicException('Liquidation stream is futures-only. Use BybitFuturesAdapter (TBD).');
    }

    /**
     * Bybit 公共请求无需特殊头（签名五件套由 BybitRequestSigner 产出，
     * 不走基类 X-MBX-APIKEY 通道）。
     *
     * @return array<string,string>
     */
    protected function httpHeaders(): array
    {
        return [];
    }

    // ------------------------------------------------------------------
    // 业务错误码抽象点（FR-009，R-008 分类表）
    // ------------------------------------------------------------------

    /**
     * Bybit 业务错误结构：HTTP 200 + retCode != 0（contracts/bybit-api-contract.md）。
     *
     * @param  array<string,mixed>  $responseBody
     */
    protected function extractErrorCode(array $responseBody): ?string
    {
        $retCode = (string) ($responseBody['retCode'] ?? '');

        return $retCode !== '' && $retCode !== '0' ? $retCode : null;
    }

    /**
     * Bybit retCode 二分类（R-008）：
     * 认证/参数/余额/时钟硬失败 → client；限流/服务器繁忙 → server；未知保守 server 可重试。
     */
    protected function translateErrorCode(string $rawCode, int $httpStatus): string
    {
        return match ($rawCode) {
            '10002', // timestamp/recv_window 超窗（时钟硬失败 + 校准提示，clarify Q2）
            '10003', '10004', '10005', '10010', // API key 无效/签名不匹配/权限不足/IP 白名单
            '110001', '110004', // 参数非法 / 余额不足
            '170213', // 订单不存在（cancelOrder 特判返 false）
            => 'client',
            '10006', '10016' => 'server', // 触发限流 / 服务器繁忙（可重试）
            default => 'server', // 未知码保守可重试（与 001 未知码策略一致）
        };
    }

    /**
     * 业务错误消息（10002 附加时钟校准提示，clarify Q2/T027）。
     *
     * @param  array<string,mixed>  $responseBody
     */
    protected function businessErrorMessage(string $rawCode, array $responseBody): string
    {
        $message = "retCode={$rawCode} retMsg=".(string) ($responseBody['retMsg'] ?? '');

        if ($rawCode === '10002') {
            $message .= '（retCode 10002：本机与 Bybit 服务器时钟漂移超过 recv_window，'
                .'请校准系统时间/NTP 同步后重试）';
        }

        return $message;
    }

    // ------------------------------------------------------------------
    // 映射基础设施（T005）
    // ------------------------------------------------------------------

    /**
     * 平台 interval → Bybit V5 kline interval（R-004）。
     *
     * 1m/3m/5m/15m/30m/1h/2h/4h/6h/12h/1d/1w → 1/3/5/15/30/60/120/240/360/720/D/W。
     * 越界抛 ExchangeApiException（clientError，data-model.md Validation Rules）。
     */
    private function toBybitInterval(string $interval): string
    {
        return match ($interval) {
            '1m' => '1',
            '3m' => '3',
            '5m' => '5',
            '15m' => '15',
            '30m' => '30',
            '1h' => '60',
            '2h' => '120',
            '4h' => '240',
            '6h' => '360',
            '12h' => '720',
            '1d' => 'D',
            '1w' => 'W',
            default => throw ExchangeApiException::clientError(
                'bybit',
                "interval {$interval}",
                400,
                "Unsupported Bybit spot interval: {$interval}",
            ),
        };
    }

    /**
     * Bybit orderStatus → 平台 status 枚举（data-model.md State Transitions）。
     *
     * New→submitted、PartiallyFilled→partial、Filled→filled、Cancelled→canceled、
     * Rejected 等其他→failed。输出落在 ExchangeOrder.status 七值枚举内。
     */
    protected function mapOrderStatus(string $bybitStatus): string
    {
        return match ($bybitStatus) {
            'New' => 'submitted',
            'PartiallyFilled' => 'partial',
            'Filled' => 'filled',
            'Cancelled' => 'canceled',
            default => 'failed', // Rejected/Deactivated 等异常状态
        };
    }

    /**
     * Bybit 订单响应 → ExchangeOrder DTO（data-model.md ExchangeOrder 表）。
     *
     * 字段映射：orderId→exchangeOrderId、orderLinkId→clientOrderId、
     * lower(side/orderType)、cumExecQty→filledQty、avgPrice→avgFillPrice（v5 直返无需计算）、
     * cumExecFee→fee、createdTime/1000→placedAt、updatedTime/1000→filledAt（status=Filled 时）。
     *
     * @param  array<string,mixed>  $data  result.list[0] 或 order/create result
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
        $status = $this->mapOrderStatus((string) ($data['orderStatus'] ?? ''));

        $createdMs = isset($data['createdTime']) && (string) $data['createdTime'] !== ''
            ? (int) $data['createdTime']
            : null;
        $updatedMs = isset($data['updatedTime']) && (string) $data['updatedTime'] !== ''
            ? (int) $data['updatedTime']
            : null;

        $avgPrice = (string) ($data['avgPrice'] ?? '');

        return new ExchangeOrder(
            exchangeOrderId: (string) ($data['orderId'] ?? '0'),
            clientOrderId: (string) ($data['orderLinkId'] ?? ($opts['clientOrderId'] ?? '')),
            symbol: (string) ($data['symbol'] ?? $symbol),
            side: strtolower((string) ($data['side'] ?? $side)),
            type: strtolower((string) ($data['orderType'] ?? $type)),
            quantity: (string) ($data['qty'] ?? $quantity),
            price: isset($data['price']) && (string) $data['price'] !== '' ? (string) $data['price'] : null,
            stopPrice: $stopPrice,
            leverage: null, // 现货无杠杆
            reduceOnly: false,
            status: $status,
            filledQty: $this->trimZeros((string) ($data['cumExecQty'] ?? '0')),
            avgFillPrice: $avgPrice !== '' ? $this->trimZeros($avgPrice) : null,
            fee: $this->trimZeros((string) ($data['cumExecFee'] ?? '0')),
            placedAt: $createdMs !== null ? intdiv($createdMs, 1000) : null,
            filledAt: $status === 'filled' && $updatedMs !== null ? intdiv($updatedMs, 1000) : null,
        );
    }

    // ------------------------------------------------------------------
    // 精度与时间辅助（private）
    // ------------------------------------------------------------------

    /**
     * interval 字符串（1m/5m/1h/1d...）→ 毫秒（REST closeTime 推算用，R-012）。
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

    // ------------------------------------------------------------------
    // WS K 线流（US2）
    // ------------------------------------------------------------------

    public function subscribeKlineStream(
        string $symbol,
        string $interval,
        callable $onBar,
        ?callable $onTick = null,
    ): string {
        // topic: kline.{Bybit interval}.{symbol}，symbol 即平台格式直传（FR-012）
        $topic = 'kline.'.$this->toBybitInterval($interval).'.'.$symbol;
        $subscriptionId = 'bybit-spot-'.(++$this->subCounter).'-'.uniqid();

        $cid = Coroutine::create(function () use ($topic, $symbol, $interval, $subscriptionId, $onBar, $onTick): void {
            $backoff = (int) config('exchange.ws_reconnect.initial_delay', 1);
            $maxBackoff = (int) config('exchange.ws_reconnect.max_delay', 60);
            $heartbeatTimeout = (int) config('exchange.ws_reconnect.heartbeat_timeout', 30);

            while (true) {
                // constitution III 坑2：Coroutine::cancel 非 sticky——recv 失败/异常被
                // 下方 catch(Throwable) 吞掉后循环会重连，协程永远退不出去。必须在每个
                // 分支显式检查 isCanceled() 并 return（配合 unsubscribeStream）。
                if (Coroutine::isCanceled()) {
                    return;
                }

                try {
                    $this->runKlineWsLoop($topic, $symbol, $interval, $onBar, $onTick, $heartbeatTimeout);
                    $backoff = 1; // 正常退出（理论不可达）重置退避
                } catch (Coroutine\CancelException) {
                    return; // unsubscribeStream 主动取消
                } catch (Throwable $e) {
                    if (Coroutine::isCanceled()) {
                        return;
                    }

                    Log::warning('Bybit kline WS disconnected, reconnecting', [
                        'subscription' => $subscriptionId,
                        'topic' => $topic,
                        'error' => $e->getMessage(),
                        'backoff' => $backoff,
                    ]);
                }

                if (Coroutine::isCanceled()) {
                    return;
                }

                // 断线指数退避重连：1s→2s→4s…上限 60s（R-006）
                Coroutine::sleep($backoff);
                if (Coroutine::isCanceled()) {
                    return;
                }
                $backoff = min($backoff * 2, $maxBackoff);
            }
        });

        $this->subscriptions[$subscriptionId] = [
            'cid' => $cid,
            'topic' => $topic,
            'symbol' => $symbol,
        ];

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
     * 单次 WS 连接循环（断开时抛异常，由外层退避重连）。
     *
     * Bybit 公共流路径 `/v5/public/spot`（无需 login）。订阅帧
     * `{"op":"subscribe","args":["kline.{i}.{s}"]}`（args 为字符串数组，与 OKX 对象数组不同）。
     *
     * 应用层心跳（R-006）：recv 1s 短超时轮询，距上次 ping ≥20s 发 `{"op":"ping"}`、
     * 距最近任一帧 ≥heartbeatTimeout 判半开断线抛异常。心跳内联在主循环 push——
     * Swoole WsClient 跨协程并发 push 不安全，不开独立 Timer 协程，故无泄漏需清理。
     *
     * @param callable(ExchangeKline):void $onBar
     * @param callable(ExchangeTicker):void|null $onTick
     */
    private function runKlineWsLoop(
        string $topic,
        string $symbol,
        string $interval,
        callable $onBar,
        ?callable $onTick,
        int $heartbeatTimeout,
    ): void {
        $url = $this->wsBaseUrl();
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw new \RuntimeException("Invalid WS URL: {$url}");
        }

        $ssl = ($parts['scheme'] ?? 'wss') === 'wss';
        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? ($ssl ? 443 : 80));
        $path = $parts['path'] ?? '/v5/public/spot';

        $client = new WsClient($host, $port, $ssl);
        $client->set([
            'timeout' => 1, // 1s 短超时轮询，心跳与取消检查得以周期执行
            'keep_alive' => true,
        ]);

        if (! $client->upgrade($path)) {
            throw new \RuntimeException("WS upgrade failed: {$host}:{$port}{$path} err={$client->errCode}");
        }

        // 连接成功后立即发送订阅帧（Bybit 公共流无需 login）
        $client->push(json_encode([
            'op' => 'subscribe',
            'args' => [$topic],
        ], JSON_UNESCAPED_SLASHES));

        $lastPingAt = microtime(true);
        $lastAliveAt = microtime(true);

        while (true) {
            if (Coroutine::isCanceled()) {
                return;
            }

            $frame = $client->recv(1.0);
            if ($frame === false) {
                // 短超时（连接存活）或断开：先按 20s 周期补发 ping，超时未判活则走退避重连
                $now = microtime(true);
                if ($now - $lastPingAt >= 20) {
                    $client->push(json_encode(['op' => 'ping']));
                    $lastPingAt = $now;
                }
                if ($now - $lastAliveAt >= $heartbeatTimeout) {
                    throw new \RuntimeException(
                        "WS heartbeat timeout ({$heartbeatTimeout}s) or disconnect: err={$client->errCode}"
                    );
                }

                continue;
            }

            $lastAliveAt = microtime(true); // 任意帧（含 pong）证明链路存活

            if ($frame->opcode !== WEBSOCKET_OPCODE_TEXT || $frame->data === null || $frame->data === '') {
                continue;
            }

            $payload = json_decode((string) $frame->data, true);
            if (! is_array($payload)) {
                continue;
            }

            // {"op":"pong"} 心跳应答 / {"op":"subscribe",...} 订阅确认等非数据推送，跳过
            $data = $payload['data'] ?? null;
            if (! is_array($data)) {
                continue;
            }

            foreach ($data as $row) {
                $this->dispatchKlineRow((array) $row, $symbol, $interval, $onBar, $onTick);
            }
        }
    }

    /**
     * confirm 分支派发（纯函数，T016 单测钩子）。
     *
     * confirm=true → onBar（bar 关闭，每 bar 一次，R-006"每 bar 一次"语义）；
     * confirm=false → 不触发 onBar（bar 内 ~250ms 快照更新）；有 onTick 时
     * 用 close 构造 ticker 调 onTick（C1 对齐 001 OKX 范式）。
     *
     * @param array<string,mixed> $row
     */
    private function dispatchKlineRow(
        array $row,
        string $symbol,
        string $interval,
        callable $onBar,
        ?callable $onTick,
    ): void {
        $confirm = filter_var($row['confirm'] ?? false, FILTER_VALIDATE_BOOL);
        $kline = $this->mapWsKlinePayload($row, $symbol, $interval);

        if ($confirm) {
            $onBar($kline);
        } elseif ($onTick !== null) {
            // K 线未闭 tick：用 close 近似 lastPrice（镜像 BinanceSpotAdapter/OkxAdapter 范式）
            $onTick(new ExchangeTicker(
                symbol: $kline->symbol,
                lastPrice: $kline->close,
                bidPrice: '0',
                askPrice: '0',
                volume24h: $kline->quoteVolume,
                changePct24h: '0',
                timestamp: $kline->openTime,
            ));
        }
    }

    /**
     * Bybit WS 推送 data[i] 行 → ExchangeKline。
     *
     * 推送为对象格式（与 REST 数组格式不同，contracts/bybit-api-contract.md）：
     * start→openTime、end→closeTime（WS 直传，无需推算）、OHLC/volume 直传、
     * turnover→quoteVolume、tradesCount=0（v5 不返）、confirm 不入 DTO 仅分支判断。
     *
     * @param  array<string,mixed>  $row
     */
    private function mapWsKlinePayload(array $row, string $symbol, string $interval): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'bybit',
            symbol: $symbol, // 直传（FR-012）
            interval: $interval,
            openTime: (int) ($row['start'] ?? 0),
            closeTime: (int) ($row['end'] ?? 0),
            open: (string) ($row['open'] ?? '0'),
            high: (string) ($row['high'] ?? '0'),
            low: (string) ($row['low'] ?? '0'),
            close: (string) ($row['close'] ?? '0'),
            volume: (string) ($row['volume'] ?? '0'),
            quoteVolume: (string) ($row['turnover'] ?? '0'),
            tradesCount: 0,
        );
    }

    // ------------------------------------------------------------------
    // 账户与交易（US3 签名接口）
    // ------------------------------------------------------------------

    /**
     * Bybit GET /v5/account/wallet-balance?accountType=UNIFIED → ExchangeBalance。
     *
     * available = bcsub(walletBalance, locked, 8)（R-010：不用 availableToWithdraw，
     * UNIFIED 账户含理财占用的 locked 部分）；assets 聚合 {coin: {total: walletBalance, available}}。
     */
    public function getBalance(): ExchangeBalance
    {
        $data = $this->httpSigned('GET', '/v5/account/wallet-balance', [
            'accountType' => 'UNIFIED',
        ], weight: 1);

        $row = (array) (($data['result']['list'][0] ?? []));

        $assets = [];
        foreach ((array) ($row['coin'] ?? []) as $coin) {
            $coin = (array) $coin;
            $ccy = (string) ($coin['coin'] ?? '');
            if ($ccy === '') {
                continue;
            }
            $wallet = (string) ($coin['walletBalance'] ?? '0');
            $locked = (string) ($coin['locked'] ?? '0');
            $total = $this->trimZeros($wallet);
            if (bccomp($total, '0', 8) !== 1) {
                continue; // 零余额资产不入明细（镜像 OkxAdapter 范式）
            }
            $assets[$ccy] = [
                'total' => $total,
                'available' => $this->trimZeros(bcsub($wallet, $locked, 8)),
            ];
        }

        $usdtTotal = $assets['USDT']['total'] ?? '0';
        $usdtAvail = $assets['USDT']['available'] ?? '0';

        return new ExchangeBalance(
            totalEquity: (string) ($row['totalEquity'] ?? $usdtTotal),
            totalMargin: (string) ($row['totalMarginBalance'] ?? '0'),
            available: $usdtAvail,
            unrealizedPnl: (string) ($row['totalPerpUPL'] ?? '0'),
            assets: $assets,
        );
    }

    /**
     * Bybit POST /v5/order/create → ExchangeOrder。
     *
     * body: category/symbol/side:ucfirst(Buy|Sell)/orderType:ucfirst(Market|Limit)/qty；
     * 市价买加 marketUnit:'quoteCoin'（qty=USDT 金额，R-011，与 001 OKX tgtCcy=quote_ccy 对齐），
     * 市价卖不加（qty=base 数量）；限价加 price + timeInForce:'GTC'；
     * orderLinkId=opts.clientOrderId，超 36 字符压缩（R-011 v5 限制）。
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
        $side = ucfirst(strtolower($side)); // Buy|Sell
        $orderType = ucfirst(strtolower($type)); // Market|Limit

        $body = [
            'category' => 'spot',
            'symbol' => $symbol,
            'side' => $side,
            'orderType' => $orderType,
            'qty' => $quantity,
        ];

        if ($orderType === 'Market' && $side === 'Buy') {
            $body['marketUnit'] = 'quoteCoin'; // qty 按报价币金额计（R-011）
        }

        if ($orderType === 'Limit') {
            $body['timeInForce'] = 'GTC';
            $body['price'] = (string) $price;
        }

        $clientOrderId = $opts['clientOrderId'] ?? null;
        if (is_string($clientOrderId) && $clientOrderId !== '') {
            $body['orderLinkId'] = $this->fitOrderLinkId($clientOrderId);
        }

        $data = $this->httpSigned('POST', '/v5/order/create', $body, weight: 1);

        $row = (array) ($data['result'] ?? []);
        // v5 order/create result 仅含 orderId/orderLinkId（无 orderStatus）——受理即 New
        $row['orderStatus'] ??= 'New';

        return $this->mapOrderResponse($row, $symbol, $side, $type, $quantity, $price, $stopPrice, $opts);
    }

    /**
     * Bybit POST /v5/order/cancel → bool。
     *
     * body: category/symbol + (orderId | orderLinkId)。
     * retCode=170213 订单不存在（已成交/已撤）→ 返 false（R-008 特判，
     * 镜像 Binance -2011 / OKX 51404 处理），其余错误照常抛。
     */
    public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool
    {
        $body = ['category' => 'spot', 'symbol' => $symbol];
        if ($clientOrderId !== null && $clientOrderId !== '') {
            $body['orderLinkId'] = $clientOrderId;
        } else {
            $body['orderId'] = $orderId;
        }

        try {
            $this->httpSigned('POST', '/v5/order/cancel', $body, weight: 1);

            return true;
        } catch (ExchangeApiException $e) {
            if (str_contains($e->getMessage(), 'retCode=170213')) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Bybit GET /v5/order/realtime → ExchangeOrder（活跃单；终态单走双端点回查）。
     */
    public function getOrder(string $symbol, string $orderId): ExchangeOrder
    {
        $row = $this->fetchOrderRow($symbol, ['orderId' => $orderId]);
        if ($row === null) {
            throw ExchangeApiException::clientError(
                'bybit',
                'GET /v5/order/realtime|history',
                404,
                "order not found: {$orderId}",
            );
        }

        return $this->mapOrderResponse(
            $row,
            $symbol,
            strtolower((string) ($row['side'] ?? 'buy')),
            strtolower((string) ($row['orderType'] ?? 'market')),
            (string) ($row['qty'] ?? '0'),
            isset($row['price']) && (string) $row['price'] !== '' ? (string) $row['price'] : null,
            null,
            [],
        );
    }

    /**
     * Bybit GET /v5/order/realtime?orderLinkId → ?ExchangeOrder。
     *
     * spec FR-006 + constitution IV：崩溃恢复幂等查询，双端点皆空返回 null。
     */
    public function getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder
    {
        $row = $this->fetchOrderRow($symbol, ['orderLinkId' => $clientOrderId]);
        if ($row === null) {
            return null;
        }

        return $this->mapOrderResponse(
            $row,
            $symbol,
            strtolower((string) ($row['side'] ?? 'buy')),
            strtolower((string) ($row['orderType'] ?? 'market')),
            (string) ($row['qty'] ?? '0'),
            isset($row['price']) && (string) $row['price'] !== '' ? (string) $row['price'] : null,
            null,
            ['clientOrderId' => $clientOrderId],
        );
    }

    /**
     * R-005 双端点幂等查询：先查 /v5/order/realtime（活跃单 New/PartiallyFilled），
     * result.list 空则回查 /v5/order/history（终态单 Filled/Cancelled/Rejected）；
     * 两端皆空返 null（getOrderByClientOrderId→null / getOrder→clientError 由调用方分支）。
     *
     * @param  array<string,string>  $selector  ['orderId' => ..] 或 ['orderLinkId' => ..]
     * @return array<string,mixed>|null
     */
    private function fetchOrderRow(string $symbol, array $selector): ?array
    {
        foreach (['/v5/order/realtime', '/v5/order/history'] as $path) {
            $data = $this->httpSigned('GET', $path, ['category' => 'spot', 'symbol' => $symbol] + $selector, weight: 1);
            $list = (array) ($data['result']['list'] ?? []);
            if ($list !== []) {
                return (array) $list[0];
            }
        }

        return null;
    }

    /**
     * orderLinkId ≤36 字符压缩（R-011 v5 限制）。
     *
     * constitution II 模板 {tenant_id}_{instance_id}_{timestamp}_{random} 可能超限：
     * 保留尾部 32 字符（时间戳+随机段，唯一性主体）并前置 4 位全 ID 摘要防碰撞，共 36。
     */
    private function fitOrderLinkId(string $clientOrderId): string
    {
        if (strlen($clientOrderId) <= 36) {
            return $clientOrderId;
        }

        return substr(md5($clientOrderId), 0, 4).substr($clientOrderId, -32);
    }
}
