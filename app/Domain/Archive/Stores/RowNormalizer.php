<?php

declare(strict_types=1);

namespace App\Domain\Archive\Stores;

use App\Domain\Archive\Contracts\ArchiveType;

/**
 * 行规范化串与可交换行哈希和（spec 004 R-006 / constitution II）。
 *
 * 等价域：规范化串 = 按 ArchiveType::canonicalColumns() 列序 `|` 拼接，null → ''；
 * 审计列（batch_id/archived_at）不在等价域（重跑会变）。
 *
 * 哈希方案 = R-006 降级方案：MD5 十六进制前 16 位 → 无符号十进制（PHP md5() / CH MD5()
 * 均原生，零自研算法）。主库侧 bcmath 精确累加后 mod 2^64 归约，与 CH 侧
 * sum(toUInt64OrZero(substr(MD5(concat(...)),1,16),16)) 的 UInt64 溢出回绕语义同构。
 *
 * 决策记录：R-006 主方案 cityHash64 纯 PHP 自研因本机无 CH 可对拍验证而弃用，
 * 结论落 docs/pitfalls.md（实现期决策点，契约三级校验语义不变）。
 */
final class RowNormalizer
{
    /** 2^64（CH sum(UInt64) 溢出回绕模数） */
    private const MOD_2P64 = '18446744073709551616';

    /**
     * 行 → 规范化串（哈希与抽样比对的统一输入形态）。
     *
     * @param array<string, string|null> $row 列名 => 字符串/null（CH 行或 toChRow 输出）
     */
    public static function canonicalRow(ArchiveType $type, array $row): string
    {
        $parts = [];
        foreach ($type->canonicalColumns() as $column) {
            $value = $row[$column] ?? null;
            $parts[] = $value === null ? '' : (string) $value;
        }

        return implode('|', $parts);
    }

    /**
     * 规范化串 → 行哈希（无符号十进制字符串，全精度不走浮点）。
     */
    public static function rowHash(string $canonical): string
    {
        return self::hexPrefixToDec(substr(md5($canonical), 0, 16));
    }

    /**
     * 哈希和累加（bcmath 字符串精确，constitution II）。
     */
    public static function addHash(string $accumulator, string $hash): string
    {
        return bcadd($accumulator, $hash);
    }

    /**
     * 求和归约到 UInt64 域（与 CH sum 溢出回绕同构，比对前必须归约）。
     */
    public static function mod2p64(string $value): string
    {
        return bcmod($value, self::MOD_2P64);
    }

    /**
     * 时间值 → UTC 'Y-m-d H:i:s'（null 直传；任何时区输入归一 UTC，constitution II）。
     */
    public static function ts(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if (is_numeric($value)) {
            // Unix 秒时间戳
            return (new \DateTimeImmutable('@' . (int) $value))->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    /**
     * 16 位十六进制 → 无符号十进制字符串（bcmath 逐字符，2^64 域内精确）。
     */
    private static function hexPrefixToDec(string $hex): string
    {
        $value = '0';
        foreach (str_split(strtolower($hex)) as $char) {
            $value = bcadd(bcmul($value, '16'), (string) hexdec($char));
        }

        return $value;
    }
}
