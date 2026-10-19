<?php

declare(strict_types=1);

namespace App\Domain\Archive\Contracts;

/**
 * 历史层存储契约（spec 004 / R-002：CH 实现可替换、测试可 fake）。
 *
 * 值形态（R-004 / constitution II）：
 *   - 写入（JSONEachRow）：Decimal/时间戳传字符串，可空列传 JSON null；
 *   - 读取：全部 SELECT 恒带 output_format_json_quote_decimals=1 与
 *     output_format_json_quote_64bit_integers=1，行形态 array<string,string|null>；
 *   - 所有 HTTP 异常（连接/超时/4xx/5xx）统一包装 ArchiveStorageUnavailableException（R-009）。
 */
interface ArchiveStore
{
    /**
     * 可用性探测（集成测试 skip 守卫 / 状态命令用）。
     */
    public function ping(): bool;

    /**
     * 幂等建立库与四张冷表（DDL 唯一事实源 = contracts/clickhouse-schema-contract.md）。
     *
     * @return list<string> 本次实际执行的语句（命令输出用）
     */
    public function createSchema(): array;

    /**
     * 批量写入（INSERT ... FORMAT JSONEachRow，NDJSON body）。
     *
     * @param list<array<string, string|null>> $rows 列名 => 字符串/null
     */
    public function insertRows(string $chTable, array $rows): void;

    /**
     * 批次行数（verify 第一级 / 幂等断言）。
     */
    public function countByBatch(string $chTable, int $batchId): int;

    /**
     * 批次轻量删除（重跑幂等守卫：先删该批半途行再重插，R-003；对后续查询立即可见）。
     */
    public function deleteByBatch(string $chTable, int $batchId): void;

    /**
     * 读取行（verify 抽样 / HistoricalQuery 冷侧）。
     *
     * @param array<string, string|int|null> $filters    等值过滤（null → IS NULL）；标识符经契约列白名单调用方保证
     * @param string                         $timeColumn 时间轴列（R-005）
     * @param array<string, string>          $orderBy    列 => 'ASC'|'DESC'
     * @param ?array{time: string, id: string} $keysetAfter keyset 游标下推（US2 分页）：
     *                                                     仅返回 (时间列, id) 严格大于该值的行
     *                                                     （time > t OR (time = t AND id > i)）
     * @return list<array<string, string|null>>
     */
    public function fetchRows(
        string $chTable,
        array $filters,
        string $timeColumn,
        ?string $timeFromUtc,
        ?string $timeToUtc,
        array $orderBy,
        int $limit,
        int $offset = 0,
        ?array $keysetAfter = null,
    ): array;

    /**
     * 批次行哈希和（verify 第二级，R-006）：CH 侧对规范化串求 MD5 前 16 位转无符号再 sum
     * （UInt64 溢出回绕，与主库侧 RowNormalizer::mod2p64 归约同构）。
     *
     * @param list<string> $columns 规范化列序（= ArchiveType::canonicalColumns()）
     */
    public function hashSum(string $chTable, int $batchId, array $columns): string;
}
