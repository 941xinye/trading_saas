<?php

declare(strict_types=1);

namespace Tests\Unit\Liquidation;

use App\Domain\Exchange\Contracts\LiquidationEvent;
use PHPUnit\Framework\TestCase;

/**
 * Binance 合约 forceOrder WS 帧 → LiquidationEvent DTO 的纯解析逻辑
 * （迁移自 poc:verify-liquidation 断言 #1、#2）。
 *
 * 不启动 Laravel、无外部依赖；输入全部为合成 JSON/数组，金额用 bcmath 字符串比对。
 * WS 帧的收发/重连在 BinanceFuturesAdapter 私有协程循环内（Swoole 耦合），
 * 此处只覆盖可离线验证的纯内核：json_decode 后的 e/o 分帧 + fromBinancePayload()。
 */
class LiquidationEventTest extends TestCase
{
    public function test_from_binance_payload_maps_o_fields(): void
    {
        // 旧断言 #1：o.s/o.S/o.ap/o.z/o.T 映射 + exchangeCode 固定 binance
        $e = LiquidationEvent::fromBinancePayload([
            's' => 'BTCUSDT',
            'S' => 'SELL',
            'ap' => '60000.50',
            'z' => '0.5',
            'T' => 1700000000000,
        ], 1700000000500);

        static::assertSame('binance', $e->exchangeCode);
        static::assertSame('BTCUSDT', $e->symbol);
        static::assertSame('SELL', $e->side);
        static::assertSame('60000.50', $e->price);
        static::assertSame('0.5', $e->qty);
        static::assertSame(1700000000000, $e->tsMs);
        static::assertNull($e->exchangeEventId);
    }

    public function test_value_usd_is_price_multiplied_by_qty(): void
    {
        // 旧断言 #2：value_usd = price × qty（8 位精度）
        $e = LiquidationEvent::fromBinancePayload([
            's' => 'BTCUSDT', 'S' => 'SELL', 'ap' => '60000.50', 'z' => '0.5', 'T' => 1700000000000,
        ]);

        static::assertSame(0, bccomp($e->valueUsd, '30000.25000000', 8));
    }

    public function test_synthetic_ws_text_frame_json_parses_into_event(): void
    {
        // 模拟 WS 文本帧到达后的纯解析链路（适配器内 json_decode + e==forceOrder 分帧 + DTO 归一化）
        $rawFrame = '{"e":"forceOrder","E":1700000000500,"o":{"s":"BTCUSDT","S":"SELL","o":"LIMIT","f":"IOC","q":"0.500","p":"60000.00","ap":"60000.50","X":"FILLED","l":"0.500","z":"0.500","T":1700000000000}}';

        $payload = json_decode($rawFrame, true);
        static::assertIsArray($payload);
        static::assertSame('forceOrder', $payload['e']);
        static::assertIsArray($payload['o']);

        $e = LiquidationEvent::fromBinancePayload($payload['o'], (int) $payload['E']);

        static::assertSame('BTCUSDT', $e->symbol);
        static::assertSame('SELL', $e->side);
        static::assertSame('60000.50', $e->price);
        static::assertSame('0.500', $e->qty);
        static::assertSame(1700000000000, $e->tsMs);
        static::assertSame(0, bccomp($e->valueUsd, '30000.25000000', 8));
    }

    public function test_average_price_falls_back_to_limit_price_when_ap_missing_or_zero(): void
    {
        $missing = LiquidationEvent::fromBinancePayload(['s' => 'ETHUSDT', 'S' => 'BUY', 'p' => '3000', 'z' => '1']);
        static::assertSame('3000', $missing->price);

        $zero = LiquidationEvent::fromBinancePayload(['s' => 'ETHUSDT', 'S' => 'BUY', 'ap' => '0', 'p' => '3000', 'z' => '1']);
        static::assertSame('3000', $zero->price);
    }

    public function test_filled_qty_falls_back_from_z_to_q(): void
    {
        $e = LiquidationEvent::fromBinancePayload(['s' => 'ETHUSDT', 'S' => 'BUY', 'ap' => '3000', 'q' => '2.5']);

        static::assertSame('2.5', $e->qty);
        static::assertSame(0, bccomp($e->valueUsd, '7500.00000000', 8));
    }

    public function test_trade_time_falls_back_to_outer_event_time_when_o_t_missing(): void
    {
        $e = LiquidationEvent::fromBinancePayload(['s' => 'BTCUSDT', 'S' => 'SELL', 'ap' => '60000', 'z' => '0.1'], 1700000000500);

        static::assertSame(1700000000500, $e->tsMs);
    }

    public function test_side_is_normalized_to_upper_case(): void
    {
        $e = LiquidationEvent::fromBinancePayload(['s' => 'BTCUSDT', 'S' => 'sell', 'ap' => '60000', 'z' => '0.1']);

        static::assertSame('SELL', $e->side);
    }
}
