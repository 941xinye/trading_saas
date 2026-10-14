<?php

declare(strict_types=1);

namespace Tests\Unit\Reconciliation;

use App\Domain\Reconciliation\ReconciliationService;
use PHPUnit\Framework\TestCase;

/**
 * 账实对账纯比对逻辑（P5.5 步骤 1 验收 #1-#4、#12）。
 *
 * 不启动 Laravel、无外部依赖；金额全部 bcmath 字符串。
 */
class ToleranceCompareTest extends TestCase
{
    private const TOL = ['qty' => '0.000001', 'quote' => '5'];

    private const CRITICAL = '50';

    private function classify(string $diffQty, string $price = '50000'): string
    {
        return ReconciliationService::classifyDimension($diffQty, $price, self::TOL, self::CRITICAL);
    }

    public function test_exact_match_is_ok_with_zero_diff(): void
    {
        static::assertSame('ok', $this->classify('0'));
    }

    public function test_exchange_has_more_coins_small_value_is_drift_not_pause(): void
    {
        // 实际比账本多 0.0002 BTC，等值 10 USDT：超出 dust 容差但低于严重阈值，方向为多币 → drift
        static::assertSame('drift', $this->classify('0.0002'));
    }

    public function test_exchange_missing_coins_is_critical(): void
    {
        // 实际比账本少 0.0002 BTC（账本有、交易所无 → 资金安全方向）→ critical
        static::assertSame('critical', $this->classify('-0.0002'));
    }

    public function test_dust_within_qty_tolerance_is_ok(): void
    {
        static::assertSame('ok', $this->classify('-0.0000008'));
        static::assertSame('ok', $this->classify('0.000001'));
    }

    public function test_dust_within_quote_value_tolerance_is_ok(): void
    {
        // 0.00005 BTC > 1e-6 数量容差，但按 50000 计价仅 2.5 USDT（≤ 5）→ ok
        static::assertSame('ok', $this->classify('0.00005'));
    }

    public function test_value_at_or_above_critical_quote_is_critical_even_when_exchange_has_more(): void
    {
        // 多币 0.002 BTC * 50000 = 100 USDT ≥ 50 → 无条件 critical
        static::assertSame('critical', $this->classify('0.002'));
    }

    public function test_extreme_decimal_difference_uses_bcmath_not_float(): void
    {
        // 1e-10 级差值在 bcmath scale=12 下精确判定为 dust（浮点 0.0000000001 运算有精度风险）
        static::assertSame('ok', $this->classify('-0.0000000001'));
        // 刚超 1e-6 且拿不到报价（price=0，只按数量判）：负方向 → critical
        static::assertSame(
            'critical',
            ReconciliationService::classifyDimension('-0.00000100001', '0', self::TOL, self::CRITICAL),
        );
    }

    public function test_worst_picks_most_severe_status(): void
    {
        static::assertSame('ok', ReconciliationService::worst(['ok', 'ok']));
        static::assertSame('drift', ReconciliationService::worst(['ok', 'drift']));
        static::assertSame('critical', ReconciliationService::worst(['ok', 'drift', 'critical']));
        static::assertSame('unknown', ReconciliationService::worst(['ok', 'unknown']));
    }

    public function test_period_key_buckets_utc_time(): void
    {
        // 2026-10-15 08:07:00 UTC 的 5m 桶 → 0805
        $ts = gmmktime(8, 7, 0, 10, 15, 2026);
        static::assertSame('20261015T0805', ReconciliationService::periodKey($ts, '5m'));
        // 桶末（08:09:59）仍属同一桶
        static::assertSame('20261015T0805', ReconciliationService::periodKey(gmmktime(8, 9, 59, 10, 15, 2026), '5m'));
        // 1h 桶
        static::assertSame('20261015T0800', ReconciliationService::periodKey(gmmktime(8, 40, 0, 10, 15, 2026), '1h'));
        // 非法 period 回退 5m
        static::assertSame('20261015T0805', ReconciliationService::periodKey($ts, '9x'));
    }

    public function test_split_symbol_uses_longest_quote_suffix(): void
    {
        $suffixes = ['USDT', 'USDC', 'BTC', 'ETH', 'BNB'];
        static::assertSame(['BTC', 'USDT'], ReconciliationService::splitSymbol('BTCUSDT', $suffixes));
        static::assertSame(['ETH', 'BTC'], ReconciliationService::splitSymbol('ETHBTC', $suffixes));
        static::assertSame(['XYZ', 'USDC'], ReconciliationService::splitSymbol('XYZUSDC', $suffixes));
        // 无法识别后缀时默认 quote=USDT，base 原样返回
        static::assertSame(['DOGEFOO', 'USDT'], ReconciliationService::splitSymbol('DOGEFOO', $suffixes));
    }
}
