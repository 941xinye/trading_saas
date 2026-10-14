<?php

declare(strict_types=1);

namespace Tests\Unit\Signal;

use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Signal\IndicatorCalculator;
use PHPUnit\Framework\TestCase;

/**
 * IndicatorCalculator 纯函数样板测试（P5.5 步骤 0）。
 *
 * 属于 default 组（无 @group 注解）：不 bootstrap Laravel、不连 DB/Redis、不发网络请求，
 * 显式传入 indicator config 以避免 compute() 内部走 config() 读配置。
 */
class IndicatorCalculatorTest extends TestCase
{
    private IndicatorCalculator $calc;

    /** @var array<string,mixed> 与 config/ai.php fallback.indicators 对齐 */
    private const CONFIG = [
        'ma_periods' => [7, 25, 99],
        'rsi_period' => 14,
        'macd' => ['fast' => 12, 'slow' => 26, 'signal' => 9],
        'bollinger' => ['period' => 20, 'std_dev' => 2],
        'atr_period' => 14,
    ];

    protected function setUp(): void
    {
        $this->calc = new IndicatorCalculator;
    }

    public function test_insufficient_bars_yields_nulls_but_keeps_close(): void
    {
        // 仅 10 根：MA7 可得，MA25/99、RSI(需15)、MACD(慢26)、BOLL(20)、ATR(需15) 全部 null
        $r = $this->calc->compute($this->barsFromCloses(array_fill(0, 10, '100')), self::CONFIG);

        static::assertSame(100.0, $r['close']);
        static::assertSame(100.0, $r['ma'][7]['v']);
        static::assertNull($r['ma'][25]['v']);
        static::assertNull($r['ma'][99]['v']);
        static::assertNull($r['ma'][99]['prev']);
        static::assertNull($r['rsi']);
        static::assertNull($r['macd']['dif']);
        static::assertNull($r['macd']['hist']);
        static::assertNull($r['boll']['upper']);
        static::assertNull($r['atr']);
    }

    public function test_constant_series_is_neutral_everywhere(): void
    {
        // 120 根全等 100：所有均线=100；无涨跌 → RSI=50；BOLL 三轨重合；TR 全 0 → ATR=0；MACD 柱=0
        $r = $this->calc->compute($this->barsFromCloses(array_fill(0, 120, '100')), self::CONFIG);

        foreach ([7, 25, 99] as $p) {
            static::assertSame(100.0, $r['ma'][$p]['v']);
            static::assertSame(100.0, $r['ma'][$p]['prev']);
        }
        static::assertSame(50.0, $r['rsi']);
        static::assertSame(0.0, $r['macd']['dif']);
        static::assertSame(0.0, $r['macd']['dea']);
        static::assertSame(0.0, $r['macd']['hist']);
        static::assertSame(0.0, $r['macd']['hist_prev']);
        static::assertSame(100.0, $r['boll']['upper']);
        static::assertSame(100.0, $r['boll']['middle']);
        static::assertSame(100.0, $r['boll']['lower']);
        static::assertSame(0.0, $r['atr']);
    }

    public function test_strict_uptrend_gives_rsi_one_hundred(): void
    {
        // 连续上涨（无下跌根）→ avgLoss=0 且 avgGain>0 → RSI=100
        $closes = [];
        for ($i = 1; $i <= 120; $i++) {
            $closes[] = (string) $i;
        }

        $r = $this->calc->compute($this->barsFromCloses($closes), self::CONFIG);

        static::assertSame(100.0, $r['rsi']);
        static::assertGreaterThan(0.0, $r['atr']); // high=close+1/low=close-1 每根都有 TR
    }

    public function test_simple_average_uses_last_n_closes(): void
    {
        // 前 119 根=100，最后一根=107 → 最新 MA7=(6*100+107)/7=101；前一根 MA7=100
        $closes = array_fill(0, 119, '100');
        $closes[] = '107';

        $r = $this->calc->compute($this->barsFromCloses($closes), self::CONFIG);

        static::assertSame(101.0, $r['ma'][7]['v']);
        static::assertSame(100.0, $r['ma'][7]['prev']);
    }

    public function test_bollinger_bands_round_to_four_decimals(): void
    {
        // 末 20 根：19 根 100 + 1 根 102 → mean=100.1，总体方差 0.19，std≈0.4358899
        // upper≈100.9718（2 倍标准差），输出统一 round 到 4 位
        $closes = array_fill(0, 100, '100');
        $closes = array_slice($closes, 0, 99);
        $closes = array_merge($closes, array_fill(0, 19, '100'), ['102']);

        $r = $this->calc->compute($this->barsFromCloses($closes), self::CONFIG);

        static::assertEqualsWithDelta(100.1, $r['boll']['middle'], 0.00001);
        static::assertEqualsWithDelta(100.9718, $r['boll']['upper'], 0.00001);
        static::assertEqualsWithDelta(99.2282, $r['boll']['lower'], 0.00001);
    }

    /**
     * poc:verify-ai [2/7] 的 5 项指标齐全性断言离线版（真实 REST 拉取仍保留在 poc 命令作 smoke）：
     * 120 根波动序列上 MA7/25/99 有值、RSI∈[0,100]、BOLL 三轨有序、ATR>0、MACD dif/hist 有值。
     */
    public function test_full_indicator_shape_on_volatile_series(): void
    {
        // 100 平 + 10 跌 + 10 涨（与 SignalPipelineFallbackTest 的确定性多头形态一致）
        $closes = array_fill(0, 100, '100');
        for ($i = 1; $i <= 10; $i++) {
            $closes[] = (string) (100.0 - $i * 0.2);
        }
        $price = 98.0;
        for ($i = 0; $i < 10; $i++) {
            $price += 0.2;
            $closes[] = (string) $price;
        }

        $r = $this->calc->compute($this->barsFromCloses($closes), self::CONFIG);

        static::assertTrue(is_numeric($r['ma'][7]['v']) && is_numeric($r['ma'][25]['v']) && is_numeric($r['ma'][99]['v']));
        static::assertTrue(is_numeric($r['rsi']) && $r['rsi'] >= 0 && $r['rsi'] <= 100);
        static::assertTrue($r['boll']['upper'] >= $r['boll']['middle'] && $r['boll']['middle'] >= $r['boll']['lower']);
        static::assertTrue(is_numeric($r['atr']) && $r['atr'] > 0);
        static::assertTrue(is_numeric($r['macd']['dif']) && is_numeric($r['macd']['hist']));
    }

    /**
     * @param  string[]  $closes  升序收盘价
     * @return ExchangeKline[]
     */
    private function barsFromCloses(array $closes): array
    {
        $bars = [];
        foreach (array_values($closes) as $i => $close) {
            $bars[] = new ExchangeKline(
                exchangeCode: 'binance',
                symbol: 'BTCUSDT',
                interval: '1m',
                openTime: $i * 60000,
                closeTime: ($i + 1) * 60000 - 1,
                open: $close,
                high: $close,
                low: $close,
                close: $close,
                volume: '1',
                quoteVolume: $close,
                tradesCount: 1,
            );
        }

        return $bars;
    }
}
