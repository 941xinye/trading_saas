<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\Exceptions\ExchangeApiException;
use Illuminate\Support\Facades\Redis;

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
            price: isset($data['price']) && (string) $data['price'] !== '' ? (string) $data['price'] : null,
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
    // US2 WS kline 流 / US3 账户与交易：占位（对应 story 实装）
    // ------------------------------------------------------------------

    public function getBalance(): ExchangeBalance
    {
        throw new \LogicException('Bitget getBalance is implemented in US3.');
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
        throw new \LogicException('Bitget placeOrder is implemented in US3.');
    }

    public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool
    {
        throw new \LogicException('Bitget cancelOrder is implemented in US3.');
    }

    public function getOrder(string $symbol, string $orderId): ExchangeOrder
    {
        throw new \LogicException('Bitget getOrder is implemented in US3.');
    }

    public function subscribeKlineStream(
        string $symbol,
        string $interval,
        callable $onBar,
        ?callable $onTick = null,
    ): string {
        throw new \LogicException('Bitget subscribeKlineStream is implemented in US2.');
    }

    public function unsubscribeStream(string $subscriptionId): void
    {
        throw new \LogicException('Bitget unsubscribeStream is implemented in US2.');
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
