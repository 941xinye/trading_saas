<?php

declare(strict_types=1);

namespace Tests\Domain\Archive;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\ArchiveType;
use App\Domain\Archive\Stores\RowNormalizer;
use App\Domain\Archive\Types\KlineBarArchiveType;
use App\Domain\Archive\Types\SignalArchiveType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RowNormalizer 单测（spec 004 T008 / R-006）。
 *
 * 哈希方案 = R-006 降级方案（MD5 十六进制前 16 位转无符号整数求和）：
 * PHP md5() 与 CH MD5() 均为原生实现，零自研算法；本机无 CH 可达，
 * cityHash64 纯 PHP 自研无法在本地与 CH 侧对拍验证，故采用降级方案（决策落 docs/pitfalls.md）。
 *
 * 黄金向量锚点 = 公开已知的 MD5 测试向量（md5('')、md5('abc')），
 * 十六进制→十进制用测试内独立 bcmath 参考实现交叉验证（与被测实现异构）。
 */
final class RowNormalizerTest extends TestCase
{
    /**
     * 独立参考实现：十六进制串 → 无符号十进制字符串（bcmath 逐字符，异构于被测代码）。
     */
    private static function refHexToDec(string $hex): string
    {
        $value = '0';
        foreach (str_split(strtolower($hex)) as $char) {
            $value = bcadd(bcmul($value, '16'), (string) hexdec($char));
        }

        return $value;
    }

    public static function md5GoldenVectorProvider(): array
    {
        $klineCanonical = '1|binance|BTCUSDT|5m|2026-01-01 00:00:00|100.00000000';

        return [
            // [规范化串, 公开已知 md5（黄金向量锚点）]
            'empty string (RFC 1321)' => ['', 'd41d8cd98f00b204e9800998ecf8427e'],
            'abc (RFC 1321)' => ['abc', '900150983cd24fb0d6963f7d28e17f72'],
            'kline-ish row' => [$klineCanonical, md5($klineCanonical)],
        ];
    }

    #[DataProvider('md5GoldenVectorProvider')]
    public function test_row_hash_uses_md5_prefix16_decimal(string $canonical, string $knownMd5): void
    {
        $expected = self::refHexToDec(substr($knownMd5, 0, 16));

        $this->assertSame($expected, RowNormalizer::rowHash($canonical));
    }

    public function test_row_hash_stays_exact_beyond_php_int_max(): void
    {
        // md5('') 前缀 d41d8cd98f00b204 > PHP_INT_MAX（2^63-1），必须全字符串精确不能走浮点
        $hash = RowNormalizer::rowHash('');

        $this->assertGreaterThan('9223372036854775807', $hash);
        $this->assertSame(self::refHexToDec('d41d8cd98f00b204'), $hash);
    }

    public function test_hash_sum_accumulates_and_wraps_mod_2p64(): void
    {
        // CH 侧 sum(UInt64) 溢出回绕 mod 2^64，PHP 侧 bcmath 精确累加后必须同模归约
        $h1 = RowNormalizer::rowHash('');
        $h2 = RowNormalizer::rowHash('abc');

        $sum = RowNormalizer::mod2p64(RowNormalizer::addHash(RowNormalizer::addHash('0', $h1), $h2));

        $expected = RowNormalizer::mod2p64(bcadd(self::refHexToDec('d41d8cd98f00b204'), self::refHexToDec('900150983cd24fb0')));
        $this->assertSame($expected, $sum);

        // 模归约边界：2^64 → 0，2^64+1 → 1
        $this->assertSame('0', RowNormalizer::mod2p64('18446744073709551616'));
        $this->assertSame('1', RowNormalizer::mod2p64('18446744073709551617'));
    }

    public static function canonicalRowProvider(): array
    {
        $kline = new KlineBarArchiveType();

        return [
            'null collapses to empty segment' => [
                $kline,
                [
                    'id' => '7',
                    'exchange_code' => 'binance',
                    'symbol' => 'BTCUSDT',
                    'interval' => '5m',
                    'open_time' => '2026-01-01 00:00:00',
                    'close_time' => '2026-01-01 00:05:00',
                    'open' => '100.00000000',
                    'high' => null,
                    'low' => '99.00000000',
                    'close' => '100.50000000',
                    'volume' => '1.00000000',
                    'quote_volume' => '100.00000000',
                    'trades_count' => '3',
                    'created_at' => '2026-01-01 00:05:01',
                    'updated_at' => '2026-01-01 00:05:01',
                ],
                '7|binance|BTCUSDT|5m|2026-01-01 00:00:00|2026-01-01 00:05:00|100.00000000||99.00000000|100.50000000|1.00000000|100.00000000|3|2026-01-01 00:05:01|2026-01-01 00:05:01',
            ],
            'column order follows canonicalColumns not input order' => [
                $kline,
                [
                    'symbol' => 'ETHUSDT',
                    'id' => '9',
                    'trades_count' => '0',
                    'interval' => '1m',
                    'exchange_code' => 'okx',
                    'open_time' => '2026-02-01 00:00:00',
                    'close_time' => '2026-02-01 00:01:00',
                    'open' => '10.00000000',
                    'high' => '11.00000000',
                    'low' => '9.00000000',
                    'close' => '10.50000000',
                    'volume' => '5.00000000',
                    'quote_volume' => '52.00000000',
                    'created_at' => '2026-02-01 00:01:00',
                    'updated_at' => '2026-02-01 00:01:00',
                ],
                '9|okx|ETHUSDT|1m|2026-02-01 00:00:00|2026-02-01 00:01:00|10.00000000|11.00000000|9.00000000|10.50000000|5.00000000|52.00000000|0|2026-02-01 00:01:00|2026-02-01 00:01:00',
            ],
        ];
    }

    #[DataProvider('canonicalRowProvider')]
    public function test_canonical_row_joins_by_type_column_order(ArchiveType $type, array $row, string $expected): void
    {
        $this->assertSame($expected, RowNormalizer::canonicalRow($type, $row));
    }

    public function test_canonical_row_ignores_audit_columns(): void
    {
        // batch_id / archived_at 是审计列，不在 canonicalColumns 等价域内（重跑会变，R-006）
        $type = new KlineBarArchiveType();
        $base = [
            'id' => '7', 'exchange_code' => 'binance', 'symbol' => 'BTCUSDT', 'interval' => '5m',
            'open_time' => '2026-01-01 00:00:00', 'close_time' => '2026-01-01 00:05:00',
            'open' => '1', 'high' => '1', 'low' => '1', 'close' => '1', 'volume' => '1',
            'quote_volume' => '1', 'trades_count' => '0',
            'created_at' => '2026-01-01 00:05:01', 'updated_at' => '2026-01-01 00:05:01',
        ];

        $withAudit = $base + ['batch_id' => '42', 'archived_at' => '2026-04-01 00:00:00'];
        $withOtherAudit = $base + ['batch_id' => '99', 'archived_at' => '2026-04-02 00:00:00'];

        $this->assertSame(
            RowNormalizer::canonicalRow($type, $withAudit),
            RowNormalizer::canonicalRow($type, $withOtherAudit),
        );
    }

    public function test_signal_canonical_columns_cover_nullable_decimal_and_payload(): void
    {
        $type = new SignalArchiveType();

        $row = [
            'id' => '3',
            'tenant_id' => '1',
            'subscription_id' => '2',
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'direction' => 'long',
            'entry_price' => '100.00000000',
            'exit_price' => '110.00000000',
            'stop_loss' => null, // Nullable(Decimal) → ''（规范化语义）
            'confidence' => '0.85',
            'source' => 'ai',
            'rationale' => '',   // String 类 null → ''
            'raw_payload' => '{"k":1}',
            'triggered_at' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];

        $expected = '3|1|2|BTCUSDT|5m|long|100.00000000|110.00000000||0.85|ai||{"k":1}|2026-01-01 00:00:00|2026-01-01 00:00:00|2026-01-01 00:00:00';
        $this->assertSame($expected, RowNormalizer::canonicalRow($type, $row));
        $this->assertSame(ArchiveDataType::Signal, $type->dataType());
        $this->assertSame('tenant', $type->visibility());
    }

    public function test_ts_formats_utc_and_passes_null(): void
    {
        $this->assertNull(RowNormalizer::ts(null));
        $this->assertSame(
            '2026-01-01 08:00:00',
            RowNormalizer::ts(new \DateTimeImmutable('2026-01-01 08:00:00', new \DateTimeZone('UTC'))),
        );
        // 非 UTC 输入必须归一到 UTC（constitution II）
        $this->assertSame(
            '2026-01-01 00:00:00',
            RowNormalizer::ts(new \DateTimeImmutable('2026-01-01 08:00:00', new \DateTimeZone('Asia/Shanghai'))),
        );
    }
}
