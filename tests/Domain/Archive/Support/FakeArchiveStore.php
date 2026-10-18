<?php

declare(strict_types=1);

namespace Tests\Domain\Archive\Support;

use App\Domain\Archive\Contracts\ArchiveStore;
use App\Domain\Archive\Stores\RowNormalizer;
use Closure;

/**
 * ArchiveStore 内存实现（spec 004 T010-T012 测试替身）。
 *
 * 行为忠实模拟 CH 语义：insertRows 追加、deleteByBatch 立即可见、
 * hashSum = 对规范化串求 MD5 前 16 位转无符号累加 mod 2^64（与 ClickHouseStore 同构）。
 *
 * 注入点：forceHashSum（verify 二级失败）、onInsert（copy 中途并发写模拟）。
 */
final class FakeArchiveStore implements ArchiveStore
{
    /** @var array<string, list<array<string, string|null>>> 表名 => 行集 */
    public array $tables = [];

    /** verify 二级强制返回值（哈希和失配注入） */
    public ?string $forceHashSum = null;

    /** insertRows 前钩子（模拟 copy 期间主库并发写入） */
    public ?Closure $onInsert = null;

    /** @var list<array{0: string, 1: int}> deleteByBatch 调用日志（幂等守卫断言用） */
    public array $deleteByBatchCalls = [];

    public function ping(): bool
    {
        return true;
    }

    public function createSchema(): array
    {
        return ['CREATE DATABASE IF NOT EXISTS __fake__', 'CREATE TABLE IF NOT EXISTS ch_kline_bars (__fake__)'];
    }

    public function insertRows(string $chTable, array $rows): void
    {
        if ($this->onInsert instanceof Closure) {
            ($this->onInsert)();
        }
        foreach ($rows as $row) {
            $this->tables[$chTable][] = $row;
        }
    }

    public function countByBatch(string $chTable, int $batchId): int
    {
        return count(array_filter(
            $this->tables[$chTable] ?? [],
            fn (array $row): bool => ($row['batch_id'] ?? null) === (string) $batchId,
        ));
    }

    public function deleteByBatch(string $chTable, int $batchId): void
    {
        $this->deleteByBatchCalls[] = [$chTable, $batchId];
        $this->tables[$chTable] = array_values(array_filter(
            $this->tables[$chTable] ?? [],
            fn (array $row): bool => ($row['batch_id'] ?? null) !== (string) $batchId,
        ));
    }

    public function fetchRows(
        string $chTable,
        array $filters,
        string $timeColumn,
        ?string $timeFromUtc,
        ?string $timeToUtc,
        array $orderBy,
        int $limit,
        int $offset = 0,
    ): array {
        $rows = array_values(array_filter(
            $this->tables[$chTable] ?? [],
            function (array $row) use ($filters, $timeColumn, $timeFromUtc, $timeToUtc): bool {
                foreach ($filters as $column => $value) {
                    if (($row[$column] ?? null) !== $value) {
                        return false;
                    }
                }
                $time = $row[$timeColumn] ?? null;
                if ($timeFromUtc !== null && ($time === null || $time < $timeFromUtc)) {
                    return false;
                }
                if ($timeToUtc !== null && ($time === null || $time >= $timeToUtc)) {
                    return false;
                }

                return true;
            },
        ));

        foreach ($orderBy as $column => $direction) {
            usort($rows, fn (array $a, array $b): int => $direction === 'DESC'
                ? strnatcmp((string) ($b[$column] ?? ''), (string) ($a[$column] ?? ''))
                : strnatcmp((string) ($a[$column] ?? ''), (string) ($b[$column] ?? '')));
        }

        return array_slice($rows, $offset, $limit);
    }

    public function hashSum(string $chTable, int $batchId, array $columns): string
    {
        if ($this->forceHashSum !== null) {
            return $this->forceHashSum;
        }

        $accumulator = '0';
        foreach ($this->tables[$chTable] ?? [] as $row) {
            if (($row['batch_id'] ?? null) !== (string) $batchId) {
                continue;
            }
            $parts = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $parts[] = $value === null ? '' : (string) $value;
            }
            $accumulator = RowNormalizer::addHash($accumulator, RowNormalizer::rowHash(implode('|', $parts)));
        }

        return RowNormalizer::mod2p64($accumulator);
    }
}
