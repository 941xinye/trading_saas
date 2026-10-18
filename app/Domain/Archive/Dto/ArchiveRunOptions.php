<?php

declare(strict_types=1);

namespace App\Domain\Archive\Dto;

/**
 * archive:run 单类型执行选项（spec 004 / contracts/archive-pipeline-contract.md §1）。
 *
 * null 项回落 config('archive.types.{type}')；参数下限校验（红线 4）在命令层完成，
 * 管线信任传入值（测试可用极小 throttle 加速）。nowUtc 为注入时钟（测试确定性）。
 */
final class ArchiveRunOptions
{
    public function __construct(
        public readonly bool $apply = false,
        public readonly ?int $batchWindowHours = null,
        public readonly ?int $chunkSize = null,
        public readonly ?int $throttleMs = null,
        public readonly ?int $maxBatches = null,
        public readonly ?string $fromUtc = null,
        public readonly ?string $toUtc = null,
        public readonly ?\DateTimeImmutable $nowUtc = null,
    ) {}
}
