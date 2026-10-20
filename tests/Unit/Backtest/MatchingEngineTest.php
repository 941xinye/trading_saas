<?php

declare(strict_types=1);

namespace Tests\Unit\Backtest;

use App\Domain\Backtest\Dto\MatchingAssumptions;
use App\Domain\Backtest\Services\MatchingEngine;
use App\Domain\Exchange\Contracts\ExchangeKline;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Domain\Backtest\Services\MatchingEngine
 */
final class MatchingEngineTest extends TestCase
{
    private MatchingEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new MatchingEngine();
    }

    public function test_buy_price_with_slippage(): void
    {
        $trade = $this->engine->fill(
            ['buy', '1.5', null],
            $this->bar('100'),
            $this->assumptions(slippageBps: 10),
        );

        // 100 * (1 + 10/10000) = 100.1
        static::assertSame('buy', $trade->side);
        static::assertSame(0, bccomp($trade->price, '100.1', 8));
        static::assertSame(0, bccomp($trade->qty, '1.5', 8));
    }

    public function test_sell_price_with_slippage(): void
    {
        $trade = $this->engine->fill(
            ['sell', '2.0', null],
            $this->bar('200'),
            $this->assumptions(slippageBps: 5),
        );

        // 200 * (1 - 5/10000) = 199.9
        static::assertSame('sell', $trade->side);
        static::assertSame(0, bccomp($trade->price, '199.9', 8));
    }

    public function test_fee_calculation(): void
    {
        $trade = $this->engine->fill(
            ['buy', '1.0', null],
            $this->bar('100'),
            $this->assumptions(feeRate: '0.001'),
        );

        // fee = 100 * 1.0 * 0.001 = 0.1
        static::assertSame(0, bccomp($trade->fee, '0.1', 8));
    }

    public function test_zero_qty_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->fill(['buy', '0', null], $this->bar('100'), $this->assumptions());
    }

    public function test_negative_qty_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->fill(['buy', '-1', null], $this->bar('100'), $this->assumptions());
    }

    public function test_invalid_side_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->engine->fill(['long', '1', null], $this->bar('100'), $this->assumptions());
    }

    public function test_qty_rounded_to_8_decimals(): void
    {
        $trade = $this->engine->fill(
            ['buy', '1.123456789', null],
            $this->bar('100'),
            $this->assumptions(),
        );

        static::assertSame(0, bccomp($trade->qty, '1.12345678', 8));
    }

    public function test_associative_array_decision(): void
    {
        $trade = $this->engine->fill(
            ['side' => 'buy', 'qty' => '2', 'price' => null],
            $this->bar('50'),
            $this->assumptions(slippageBps: 0),
        );

        static::assertSame(0, bccomp($trade->price, '50', 8));
        static::assertSame(0, bccomp($trade->qty, '2', 8));
    }

    private function bar(string $close): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '1m',
            openTime: 1_700_000_000_000,
            closeTime: 1_700_000_059_999,
            open: $close,
            high: $close,
            low: $close,
            close: $close,
            volume: '1',
            quoteVolume: '1',
            tradesCount: 1,
        );
    }

    private function assumptions(?string $feeRate = null, ?int $slippageBps = null): MatchingAssumptions
    {
        return new MatchingAssumptions(
            feeRate: $feeRate ?? '0',
            slippageBps: $slippageBps ?? 0,
            initialCapital: '1000',
            narrative: '',
        );
    }
}
