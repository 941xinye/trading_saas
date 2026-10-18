<?php

declare(strict_types=1);

namespace App\Domain\Archive\Dto;

/**
 * 单批次处理结果（spec 004 / contracts/archive-pipeline-contract.md §2/§4）。
 *
 * action 语义：copied（dry-run 完成 copy+verify）| deleted（apply 删除完成）|
 * failed（copy/verify/apply 失败，message=failed_reason）| blocked（verify gate 拒绝/等待 --apply）|
 * skipped（已 applied，重跑跳过）。
 */
final class BatchOutcome
{
    public function __construct(
        public readonly int $batchId = 0,
        public readonly string $dataType = '',
        public readonly string $timeFrom = '',
        public readonly string $timeTo = '',
        public readonly int $sourceRows = 0,
        public readonly int $archivedRows = 0,
        public readonly string $verifyStatus = '',
        public readonly string $action = '',
        public readonly string $verifyDetail = '',
        public readonly ?string $message = null,
    ) {}
}
