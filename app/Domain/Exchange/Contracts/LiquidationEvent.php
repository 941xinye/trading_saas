<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Contracts;

/**
 * 大户爆仓事件 DTO。
 *
 * 字段对齐 Binance 合约 `!forceOrder@arr` 流的 `o` 字段（剥离外层 e/E 包裹），
 * 各 Adapter 在 subscribeLiquidationStream 回调前用 fromBinancePayload() 归一化。
 *
 * 时间戳毫秒级（Binance/OKX 原生粒度）。
 * 数量/价格 bcmath 字符串（保留精度），value_usd 为 price × qty 估算。
 *
 * side 保留交易所原值（Binance: BUY/SELL 大写）。语义上 SELL = 多头被强平，
 * BUY = 空头被强平；UI 显示需加注释避免误读。
 */
readonly class LiquidationEvent
{
    public function __construct(
        public string $exchangeCode,
        public string $symbol,
        public string $side,
        public string $price,
        public string $qty,
        public string $valueUsd,
        public int $tsMs,
        public ?string $exchangeEventId = null,
    ) {
    }

    /**
     * 从 Binance forceOrder 事件载荷构造（payload 即 `o` 字段内容）。
     *
     * 载荷结构：
     *   {s: BTCUSDT, S: SELL, o: LIMIT, f: IOC, q: 0.011, p: 9910.00,
     *    ap: 9910.00, X: FILLED, l: 0.011, z: 0.011, T: 1568809890598}
     *
     * 价格优先用 ap（平均成交价），缺失时回退 p（限价）；数量用 z（累计成交）。
     *
     * @param array<string,mixed> $o Binance `o` 字段
     * @param int $eventTs 事件外层 E 字段（毫秒），用于 o.T 缺失回退
     */
    public static function fromBinancePayload(array $o, int $eventTs = 0): self
    {
        $price = (string) ($o['ap'] ?? $o['p'] ?? '0');
        if ($price === '' || $price === '0') {
            $price = (string) ($o['p'] ?? '0');
        }
        $qty = (string) ($o['z'] ?? $o['q'] ?? '0');
        $ts = (int) ($o['T'] ?? $eventTs);

        // value_usd = price * qty（bcmath，8 位精度）
        $valueUsd = bcmul($price, $qty, 8);
        if ($valueUsd === null) {
            $valueUsd = '0';
        }

        return new self(
            exchangeCode: 'binance',
            symbol: (string) ($o['s'] ?? ''),
            side: strtoupper((string) ($o['S'] ?? '')),
            price: $price,
            qty: $qty,
            valueUsd: $valueUsd,
            tsMs: $ts,
            exchangeEventId: null,
        );
    }
}
