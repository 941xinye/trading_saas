<?php

declare(strict_types=1);

namespace Tests\Integration\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Signal\Contracts\Signal;
use App\Domain\Signal\SignalPipeline;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 信号价差守卫测试（P5.5 步骤 3，迁自 poc:verify-ai [5/7] 共 2 项断言）。
 *
 * 守卫为私有纯判定方法，用 ReflectionMethod 直测；不触达 Redis/HTTP，属 default 组。
 */
class PriceDeviationGuardTest extends TestCase
{
    public function test_price_guard_rejects_beyond_one_percent_and_allows_within(): void
    {
        $pipeline = app(SignalPipeline::class);
        $method = new ReflectionMethod($pipeline, 'priceDeviationExceeds');
        $bar = new ExchangeKline(
            exchangeCode: 'binance',
            symbol: 'BTCUSDT',
            interval: '5m',
            openTime: 0,
            closeTime: 299_999,
            open: '100',
            high: '100',
            low: '100',
            close: '100',
            volume: '1',
            quoteVolume: '1',
            tradesCount: 1,
        );

        $drift = new Signal('BTCUSDT', '5m', 'long', '102', '105', '100.5', 80, 'x', 'ai', null);
        $ok = new Signal('BTCUSDT', '5m', 'long', '100.2', '105', '99.8', 80, 'x', 'ai', null);

        static::assertTrue($method->invoke($pipeline, $drift, $bar));
        static::assertFalse($method->invoke($pipeline, $ok, $bar));
    }
}
