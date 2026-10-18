<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use Illuminate\Support\Facades\Redis;

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
 * US2/US3 接口方法（getBalance / placeOrder / cancelOrder / getOrder /
 * subscribeKlineStream / unsubscribeStream）暂以 LogicException 占位，待对应 phase 落地。
 */
class OkxAdapter extends AbstractExchangeAdapter
{
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
        throw new \LogicException('OKX WS kline stream not implemented until US2 (spec Phase 4).');
    }

    public function unsubscribeStream(string $subscriptionId): void
    {
        throw new \LogicException('OKX WS unsubscribe not implemented until US2 (spec Phase 4).');
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
}
