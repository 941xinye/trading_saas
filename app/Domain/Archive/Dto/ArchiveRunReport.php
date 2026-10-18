<?php

declare(strict_types=1);

namespace App\Domain\Archive\Dto;

/**
 * archive:run 单类型执行报告（spec 004 / contracts/archive-pipeline-contract.md §4）。
 *
 * toUtc 为生效上界（--to 越界时已截断到 now-retention，warnings 携带截断说明）。
 *
 * @property list<BatchOutcome> $batches
 * @property list<string>      $warnings
 */
final class ArchiveRunReport
{
    /**
     * @param list<BatchOutcome> $batches
     * @param list<string>       $warnings
     */
    public function __construct(
        public readonly string $dataType,
        public readonly string $fromUtc,
        public readonly string $toUtc,
        public readonly array $batches = [],
        public readonly array $warnings = [],
    ) {}

    public function passedCount(): int
    {
        return count(array_filter($this->batches, fn (BatchOutcome $b): bool => $b->verifyStatus === 'passed'));
    }

    public function failedCount(): int
    {
        return count(array_filter($this->batches, fn (BatchOutcome $b): bool => $b->action === 'failed'));
    }

    /**
     * 可删/已删范围聚合（passed 且未 skip 的批次；apply 模式下即已删行数）。
     *
     * @return array{from: ?string, to: ?string, rows: int}
     */
    public function deletableRange(): array
    {
        $from = null;
        $to = null;
        $rows = 0;
        foreach ($this->batches as $batch) {
            if ($batch->verifyStatus !== 'passed' || $batch->action === 'skipped') {
                continue;
            }
            $from = $from === null ? $batch->timeFrom : min($from, $batch->timeFrom);
            $to = $to === null ? $batch->timeTo : max($to, $batch->timeTo);
            $rows += $batch->archivedRows;
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }
}
