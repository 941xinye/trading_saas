<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as WsClient;
use Throwable;

/**
 * OKX 适配器。
 *
 * REST: https://www.okx.com（testnet 同域 + x-simulated-trading:1 header）
 * WS:   wss://ws.okx.com:8443/ws/v5/public（公共流无需 login）
 *
 * 特点：passphrase 鉴权、ISO 8601 签名 timestamp、模拟盘同域 + x-simulated-trading header。
 * instId 归一化：OKX 用 `BTC-USDT`（含 `-`），平台用 `BTCUSDT`，仅在适配器边界转换。
 *
 * US1 MVP 实装：getTicker / getKline / symbolFilters / alignToStep / subscribeLiquidationStream（占位）。
 * US2 WS 实装：subscribeKlineStream / unsubscribeStream / runKlineWsLoop / mapWsKlinePayload。
 * US3 接口方法（getBalance / placeOrder / cancelOrder / getOrder）暂以 LogicException 占位，待对应 phase 落地。
 */
class OkxAdapter extends AbstractExchangeAdapter
{
    private int $subCounter = 0;

    /** @var array<string,array{cid:int,channel:string,instId:string}> subscriptionId → 协程信息 */
    private array $subscriptions = [];

    protected function exchangeCode(): string
    {
        return 'okx';
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
    // 行情（US1）
    // ------------------------------------------------------------------

    /**
     * OKX GET /api/v5/market/ticker → ExchangeTicker。
     *
     * changePct24h = (last-open24h)/open24h*100（bcmath，constitution II 禁浮点）。
     */
    public function getTicker(string $symbol): ExchangeTicker
    {
        $data = $this->httpGet('/api/v5/market/ticker', [
            'instId' => $this->toOkxInstId($symbol),
        ], weight: 1);

        $row = (array) ($data['data'][0] ?? []);

        $last = (string) ($row['last'] ?? '0');
        $open24h = (string) ($row['open24h'] ?? '0');
        $changePct24h = bccomp($open24h, '0', 12) === 0
            ? '0'
            : bcmul(bcdiv(bcsub($last, $open24h, 12), $open24h, 12), '100', 12);

        return new ExchangeTicker(
            symbol: $this->toPlatformSymbol((string) ($row['instId'] ?? $symbol)),
            lastPrice: $last,
            bidPrice: (string) ($row['bidPx'] ?? '0'),
            askPrice: (string) ($row['askPx'] ?? '0'),
            volume24h: (string) ($row['vol24h'] ?? '0'),
            changePct24h: $changePct24h,
            timestamp: (int) ($row['ts'] ?? $this->currentTimestampMs()),
        );
    }

    /**
     * OKX GET /api/v5/market/candles → ExchangeKline[]（按 openTime 升序）。
     *
     * OKX REST 默认升序；limit 上限 300（contracts/okx-api-contract.md）。
     * closeTime 由 openTime + intervalMs 推算（OKX 不直接返 closeTime）。
     * tradesCount=0（OKX 不返回 trades count，data-model.md）。
     *
     * @return ExchangeKline[]
     */
    public function getKline(string $symbol, string $interval, int $limit = 500): array
    {
        $data = $this->httpGet('/api/v5/market/candles', [
            'instId' => $this->toOkxInstId($symbol),
            'bar' => $interval,
            'limit' => min($limit, 300),
        ], weight: 1);

        $intervalMs = $this->intervalMs($interval);
        $rows = (array) ($data['data'] ?? []);

        $klines = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $openTime = (int) ($row[0] ?? 0);
            $klines[] = new ExchangeKline(
                exchangeCode: 'okx',
                symbol: $symbol,
                interval: $interval,
                openTime: $openTime,
                closeTime: $openTime + $intervalMs,
                open: (string) ($row[1] ?? '0'),
                high: (string) ($row[2] ?? '0'),
                low: (string) ($row[3] ?? '0'),
                close: (string) ($row[4] ?? '0'),
                volume: (string) ($row[5] ?? '0'),
                quoteVolume: (string) ($row[7] ?? '0'),
                tradesCount: 0,
            );
        }

        usort($klines, static fn (ExchangeKline $a, ExchangeKline $b): int => $a->openTime <=> $b->openTime);

        return $klines;
    }

    /**
     * OKX 交易对精度过滤器（tickSz/lotSz/minSz）。
     *
     * Redis 缓存 24h，key: market:symbols:okx:{symbol}:filters
     * 范式镜像 BinanceSpotAdapter::symbolFilters；constitution VII 用 phpredis 签名。
     *
     * @return array{step_size:string, tick_size:string, min_notional:string}
     */
    public function symbolFilters(string $symbol): array
    {
        $key = "market:symbols:okx:{$symbol}:filters";
        $cached = Redis::connection('default')->get($key);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $data = $this->httpGet('/api/v5/public/instruments', [
            'instType' => 'SPOT',
            'instId' => $this->toOkxInstId($symbol),
        ], weight: 1);

        $row = (array) ($data['data'][0] ?? []);

        $result = [
            'step_size' => (string) ($row['lotSz'] ?? '0.00000001'),
            'tick_size' => (string) ($row['tickSz'] ?? '0.01'),
            'min_notional' => (string) ($row['minSz'] ?? '0'),
        ];

        Redis::connection('default')->setex($key, 86400, json_encode($result));

        return $result;
    }

    /**
     * 按精度步长向下取整（lotSz 对齐）。
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
        throw new \LogicException('Liquidation stream is futures-only. Use OkxFuturesAdapter (TBD).');
    }

    // ------------------------------------------------------------------
    // US2/US3 占位（待 spec Phase 4/5 落地）
    // ------------------------------------------------------------------

    public function getBalance(): ExchangeBalance
    {
        throw new \LogicException('OKX getBalance not implemented until US3 (spec Phase 5).');
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
        throw new \LogicException('OKX placeOrder not implemented until US3 (spec Phase 5).');
    }

    public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool
    {
        throw new \LogicException('OKX cancelOrder not implemented until US3 (spec Phase 5).');
    }

    public function getOrder(string $symbol, string $orderId): ExchangeOrder
    {
        throw new \LogicException('OKX getOrder not implemented until US3 (spec Phase 5).');
    }

    public function subscribeKlineStream(
        string $symbol,
        string $interval,
        callable $onBar,
        ?callable $onTick = null,
    ): string {
        $okxInstId = $this->toOkxInstId($symbol);
        $channel = 'candle' . $interval;
        $subscriptionId = 'okx-spot-' . (++$this->subCounter) . '-' . uniqid();

        $cid = Coroutine::create(function () use ($okxInstId, $channel, $subscriptionId, $onBar, $onTick): void {
            $backoff = (int) config('exchange.ws_reconnect.initial_delay', 1);
            $maxBackoff = (int) config('exchange.ws_reconnect.max_delay', 60);
            $heartbeatTimeout = (int) config('exchange.ws_reconnect.heartbeat_timeout', 30);

            while (true) {
                // constitution III 坑2：Coroutine::cancel 非 sticky——recv 失败/异常被下方
                // catch(Throwable) 吞掉后循环会重连，协程永远退不出去。必须在每个分支
                // 显式检查 isCanceled() 并 return（配合 unsubscribeStream）。
                if (Coroutine::isCanceled()) {
                    return;
                }

                try {
                    $this->runKlineWsLoop($okxInstId, $channel, $onBar, $onTick, $heartbeatTimeout);
                    $backoff = 1; // 正常退出（理论不可达）重置退避
                } catch (Coroutine\CancelException) {
                    return; // unsubscribeStream 主动取消
                } catch (Throwable $e) {
                    if (Coroutine::isCanceled()) {
                        return;
                    }

                    Log::warning('OKX kline WS disconnected, reconnecting', [
                        'subscription' => $subscriptionId,
                        'channel' => $channel,
                        'instId' => $okxInstId,
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

        $this->subscriptions[$subscriptionId] = [
            'cid' => $cid,
            'channel' => $channel,
            'instId' => $okxInstId,
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
     * OKX 公共流路径 `/ws/v5/public`（无 query string，与 Binance combined stream 不同）。
     * 订阅帧 `{"op":"subscribe","args":[{"channel":"candle1m","instId":"BTC-USDT"}]}`。
     * 推送格式 `{"arg":{"channel":"candle1m","instId":"BTC-USDT"},"data":[[ts,O,H,L,C,vol,volCcy,volCcyQuote,confirm,...]]}`。
     * OKX 30s 心跳由 WsClient 自动处理；30s 无消息视为断线（由父层退避重连）。
     *
     * @param callable(ExchangeKline):void $onBar
     * @param callable(ExchangeTicker):void|null $onTick
     */
    private function runKlineWsLoop(
        string $okxInstId,
        string $channel,
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
        $path = $parts['path'] ?? '/ws/v5/public';

        $client = new WsClient($host, $port, $ssl);
        $client->set([
            'timeout' => $heartbeatTimeout,
            'keep_alive' => true,
        ]);

        if (! $client->upgrade($path)) {
            throw new \RuntimeException("WS upgrade failed: {$host}:{$port}{$path} err={$client->errCode}");
        }

        // 连接成功后立即发送订阅帧（OKX 公共流无需 login）
        $subscribeFrame = json_encode([
            'op' => 'subscribe',
            'args' => [['channel' => $channel, 'instId' => $okxInstId]],
        ], JSON_UNESCAPED_SLASHES);
        $client->push($subscribeFrame);

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

            // OKX 推送：{arg:{channel,instId}, data:[[...]]}
            $data = $payload['data'] ?? null;
            if (! is_array($data)) {
                // 订阅确认 / 错误帧等非数据推送，跳过
                continue;
            }

            $arg = (array) ($payload['arg'] ?? []);
            $payloadChannel = (string) ($arg['channel'] ?? $channel);
            $payloadInstId = (string) ($arg['instId'] ?? $okxInstId);

            foreach ($data as $row) {
                $row = (array) $row;
                $kline = $this->mapWsKlinePayload($row, $payloadInstId, $payloadChannel);
                $confirm = (int) ($row[8] ?? 0);

                if ($confirm === 1) {
                    $onBar($kline);
                } elseif ($onTick !== null) {
                    // K 线未闭 tick：用 close 近似 lastPrice（镜像 BinanceSpotAdapter 范式）
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
        }
    }

    // ------------------------------------------------------------------
    // 归一化与精度辅助（private）
    // ------------------------------------------------------------------

    /**
     * 平台 symbol（BTCUSDT）→ OKX instId（BTC-USDT）。
     *
     * 幂等：输入已是 OKX 格式（含 `-`）直传。
     * 本 spec 范围只处理 USDT quote 现货对（data-model.md Validation Rules），
     * 非 USDT quote（如 ETH-BTC）按 Assumption 直传，留实现期补全。
     */
    private function toOkxInstId(string $symbol): string
    {
        // 已是 OKX 格式（含 -）幂等直传
        if ($symbol === '' || str_contains($symbol, '-')) {
            return $symbol;
        }

        // 在 USDT 前插 - 形成 BTC-USDT 格式
        $pos = strpos($symbol, 'USDT');
        if ($pos === false || $pos === 0) {
            // 非 USDT quote 或 USDT 在开头（无 base）—按 spec 直传
            return $symbol;
        }

        return substr($symbol, 0, $pos) . '-USDT';
    }

    /**
     * OKX instId（BTC-USDT）→ 平台 symbol（BTCUSDT）。
     *
     * 幂等：输入已是平台格式（无 `-`）直传。
     */
    private function toPlatformSymbol(string $instId): string
    {
        return str_replace('-', '', $instId);
    }

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

    // ------------------------------------------------------------------
    // WS K 线映射（US2）
    // ------------------------------------------------------------------

    /**
     * OKX WS 推送 data[0] 行 → ExchangeKline。
     *
     * OKX data[0] 字段（contracts/okx-api-contract.md）：
     *  [0] ts(ms) → openTime
     *  [1] open
     *  [2] high
     *  [3] low
     *  [4] close
     *  [5] vol(base 资产成交量) → volume
     *  [6] volCcy(quote 资产成交量，单边 taker)
     *  [7] volCcyQuote(quote 资产累计成交量) → quoteVolume
     *  [8] confirm（0=未闭, 1=bar close）—不入 DTO，仅触发分支判断
     *  [9-11] 预留字段（POL/USDT 标志等）
     *
     * closeTime = openTime + intervalMs（OKX 不直返 closeTime，bcmath 推算）。
     * tradesCount=0（OKX 不返回 trades count，data-model.md）。
     * symbol=toPlatformSymbol($okxInstId)；interval=去 'candle' 前缀后平台格式。
     *
     * @param array<int|string,string> $row         OKX 推送的 data[0] 行
     * @param string                   $okxInstId   如 BTC-USDT（推送 arg.instId）
     * @param string                   $channel     OKX channel，如 candle1m
     */
    private function mapWsKlinePayload(array $row, string $okxInstId, string $channel): ExchangeKline
    {
        $interval = preg_replace('/^candle/', '', $channel) ?? '1m';
        $interval = $interval === '' ? '1m' : $interval;

        $openTime = (int) ($row[0] ?? 0);
        $intervalMs = $this->intervalMs($interval);

        return new ExchangeKline(
            exchangeCode: 'okx',
            symbol: $this->toPlatformSymbol($okxInstId),
            interval: $interval,
            openTime: $openTime,
            closeTime: $openTime + $intervalMs,
            open: (string) ($row[1] ?? '0'),
            high: (string) ($row[2] ?? '0'),
            low: (string) ($row[3] ?? '0'),
            close: (string) ($row[4] ?? '0'),
            volume: (string) ($row[5] ?? '0'),
            quoteVolume: (string) ($row[7] ?? '0'),
            tradesCount: 0,
        );
    }
}
