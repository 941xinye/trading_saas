<?php

declare(strict_types=1);

namespace App\Domain\Archive\Services;

use App\Domain\Archive\Contracts\ArchiveStore;
use App\Domain\Archive\Dto\ArchiveRunOptions;
use App\Domain\Archive\Dto\ArchiveRunReport;
use App\Domain\Archive\Dto\BatchOutcome;
use App\Domain\Archive\Contracts\ArchiveType;
use App\Domain\Archive\Stores\RowNormalizer;
use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * 归档管线状态机（spec 004 T013 / contracts/archive-pipeline-contract.md §2）。
 *
 * 建批（复用未完成批次 / uniq 防重 / 安全上界）→ copy（先清半途行 + chunk 限速流式）
 * → verify 三级（行数 / 哈希和 / 抽样逐位）→ apply（仅 passed：事务内再核对 + 分块删除 +
 * 水位推进同事务）。dry-run 默认跳过 apply。
 *
 * 幂等锚（constitution IV）：重跑先 deleteByBatch 清半途 CH 行；failed 批次复位 pending
 * 重处理；失败批次不推进 copied_watermark；空切片不建批不推进（防回填丢失）。
 * 租户隔离（R-008/U1）：tenant 类型 copy 读取显式 withoutGlobalScope(TenantScope)
 * ——系统级搬迁专用路径，不借道超管上下文语义。
 */
final class ArchivePipeline
{
    private const EPOCH = '1970-01-01 00:00:00';

    public function __construct(private readonly ArchiveStore $store)
    {
    }

    public function run(ArchiveType $type, ArchiveRunOptions $options): ArchiveRunReport
    {
        $now = $options->nowUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $config = config('archive.types.' . $type->dataType()->value);
        $windowHours = $options->batchWindowHours ?? (int) $config['batch_window_hours'];
        $chunkSize = $options->chunkSize ?? (int) $config['chunk_size'];
        $throttleMs = $options->throttleMs ?? (int) $config['throttle_ms'];
        $sampleRows = (int) config('archive.verify_sample_rows', 5);

        // 安全上界（红线 3）：切片上界恒 ≤ now - retention；--to 越界截断并警告
        $safeTo = $now->modify('-' . (int) $config['retention_days'] . ' days');
        $warnings = [];
        $to = $options->toUtc !== null ? new \DateTimeImmutable($options->toUtc, new \DateTimeZone('UTC')) : $safeTo;
        if ($options->toUtc !== null && $to > $safeTo) {
            $to = $safeTo;
            $warnings[] = sprintf('--to %s 越过安全上界 %s，已截断', $options->toUtc, $safeTo->format('Y-m-d H:i:s'));
        }

        $dataType = $type->dataType()->value;
        $this->ensureWatermark($dataType, $now);
        $copiedWatermark = (string) DB::table('archive_watermarks')->where('data_type', $dataType)->value('copied_watermark');

        // 起始点：--from 优先；否则 copied_watermark；首跑（水位 epoch）从最老行截断到窗口
        if ($options->fromUtc !== null) {
            $cursor = new \DateTimeImmutable($options->fromUtc, new \DateTimeZone('UTC'));
        } elseif ($copiedWatermark > self::EPOCH) {
            $cursor = new \DateTimeImmutable($copiedWatermark, new \DateTimeZone('UTC'));
        } else {
            $minTime = $this->sliceQuery($type, self::EPOCH, $to->format('Y-m-d H:i:s'), $now)->min($type->timeColumn());
            if ($minTime === null) {
                return new ArchiveRunReport($dataType, self::EPOCH, $to->format('Y-m-d H:i:s'), [], $warnings);
            }
            $cursor = $this->alignToWindow(new \DateTimeImmutable((string) $minTime, new \DateTimeZone('UTC')), $windowHours);
        }

        $batches = [];
        $processed = 0;

        // 存量 sweep：账目中 passed 未 applied 的批次（此前 dry-run/中断遗留）。
        // passed 已推进 copied_watermark，窗口循环不可达，--apply 语义（对 passed 批次执行删除）
        // 必须经由账目直接定位；dry-run 下以 blocked 形态进报告（可删范围）。
        $pendingApply = DB::table('archive_batches')
            ->where('data_type', $dataType)
            ->where('verify_status', 'passed')
            ->whereNull('applied_at')
            ->orderBy('id')
            ->get();
        foreach ($pendingApply as $batch) {
            $batches[] = $options->apply
                ? $this->applyBatch($type, $batch, $now, $chunkSize, $throttleMs)
                : new BatchOutcome(
                    batchId: (int) $batch->id, dataType: $dataType,
                    timeFrom: (string) $batch->time_from, timeTo: (string) $batch->time_to,
                    sourceRows: (int) $batch->source_rows, archivedRows: (int) $batch->archived_rows,
                    verifyStatus: 'passed', action: 'blocked', message: 'passed 待 --apply 确认删除',
                );
        }

        $fromUtc = $cursor->format('Y-m-d H:i:s');
        while ($cursor < $to) {
            $windowEnd = $cursor->modify('+' . $windowHours . ' hours');
            if ($windowEnd > $to) {
                $windowEnd = $to;
            }
            $fromS = $cursor->format('Y-m-d H:i:s');
            $toS = $windowEnd->format('Y-m-d H:i:s');
            $cursor = $windowEnd;

            // 空切片：跳过建批且不推进 copied_watermark（防回填数据丢失）
            if ($this->sliceQuery($type, $fromS, $toS, $now)->count() === 0) {
                continue;
            }
            if ($options->maxBatches !== null && $processed >= $options->maxBatches) {
                break;
            }
            $processed++;

            $outcome = $this->processBatch($type, $fromS, $toS, $now, $options, $chunkSize, $throttleMs, $sampleRows);
            $batches[] = $outcome;

            // 序贯安全：当前批次失败即停止后续批次（续跑由账目状态恢复）
            if ($outcome->action === 'failed') {
                break;
            }
        }

        return new ArchiveRunReport($dataType, $fromUtc, $to->format('Y-m-d H:i:s'), $batches, $warnings);
    }

    // ------------------------------------------------------------------
    // 单批次：建批 → copy → verify → apply
    // ------------------------------------------------------------------

    private function processBatch(
        ArchiveType $type,
        string $fromS,
        string $toS,
        \DateTimeImmutable $now,
        ArchiveRunOptions $options,
        int $chunkSize,
        int $throttleMs,
        int $sampleRows,
    ): BatchOutcome {
        $dataType = $type->dataType()->value;
        $nowStr = $now->format('Y-m-d H:i:s');

        // 建批：同切片复用未完成批次行（uniq_batch_type_range 防重）
        $existing = DB::table('archive_batches')
            ->where('data_type', $dataType)
            ->where('time_from', $fromS)
            ->where('time_to', $toS)
            ->first();

        if ($existing !== null && $existing->applied_at !== null) {
            return new BatchOutcome(
                batchId: (int) $existing->id, dataType: $dataType, timeFrom: $fromS, timeTo: $toS,
                sourceRows: (int) $existing->source_rows, archivedRows: (int) $existing->archived_rows,
                verifyStatus: $existing->verify_status, action: 'skipped', message: 'already applied',
            );
        }

        if ($existing !== null && $existing->verify_status === 'passed') {
            if ($options->apply) {
                return $this->applyBatch($type, $existing, $now, $chunkSize, $throttleMs);
            }

            return new BatchOutcome(
                batchId: (int) $existing->id, dataType: $dataType, timeFrom: $fromS, timeTo: $toS,
                sourceRows: (int) $existing->source_rows, archivedRows: (int) $existing->archived_rows,
                verifyStatus: 'passed', action: 'blocked', message: 'passed 待 --apply 确认删除',
            );
        }

        if ($existing !== null && $existing->verify_status === 'failed') {
            // 重跑复位 pending，failed_reason 一并清除
            DB::table('archive_batches')->where('id', $existing->id)
                ->update(['verify_status' => 'pending', 'failed_reason' => null, 'updated_at' => $nowStr]);
        }

        $batchId = $existing !== null
            ? (int) $existing->id
            : (int) DB::table('archive_batches')->insertGetId([
                'data_type' => $dataType, 'time_from' => $fromS, 'time_to' => $toS,
                'source_rows' => 0, 'archived_rows' => 0, 'verify_status' => 'pending',
                'created_at' => $nowStr, 'updated_at' => $nowStr,
            ]);

        $base = [
            'batchId' => $batchId, 'dataType' => $dataType, 'timeFrom' => $fromS, 'timeTo' => $toS,
        ];

        try {
            // copy：幂等守卫（先清半途行）→ chunk 流式限速写
            $this->store->deleteByBatch($type->chTable(), $batchId);
            $archivedAt = $nowStr;
            $sourceRows = 0;
            $lastChunk = 0;
            $this->sliceQuery($type, $fromS, $toS, $now)->chunkById(
                $chunkSize,
                function ($rows) use ($type, $batchId, $archivedAt, $chunkSize, $throttleMs, &$sourceRows, &$lastChunk): void {
                    $chRows = [];
                    foreach ($rows as $row) {
                        $chRows[] = $type->toChRow($row, $batchId, $archivedAt);
                    }
                    $this->store->insertRows($type->chTable(), $chRows);
                    $sourceRows += count($chRows);
                    $lastChunk = count($chRows);
                    if ($lastChunk === $chunkSize) {
                        usleep($throttleMs * 1000); // 块间限速（尾块不休眠）
                    }
                },
            );
            $archivedRows = $this->store->countByBatch($type->chTable(), $batchId);

            DB::table('archive_batches')->where('id', $batchId)->update([
                'source_rows' => $sourceRows, 'archived_rows' => $archivedRows,
                'verify_status' => 'copied', 'updated_at' => $nowStr,
            ]);

            // verify 三级（R-006）
            [$ok, $detail, $reason] = $this->verify($type, $fromS, $toS, $now, $batchId, $archivedAt, $sourceRows, $archivedRows, $chunkSize, $sampleRows);
            DB::table('archive_batches')->where('id', $batchId)->update([
                'verify_status' => $ok ? 'passed' : 'failed',
                'failed_reason' => $reason,
                'updated_at' => $nowStr,
            ]);

            if (! $ok) {
                return new BatchOutcome(...$base, sourceRows: $sourceRows, archivedRows: $archivedRows,
                    verifyStatus: 'failed', action: 'failed', verifyDetail: $detail, message: $reason);
            }

            // passed → 推进 copied_watermark（只增；failed 不推进保证续跑重处理）
            $this->advanceWatermark($dataType, 'copied_watermark', $toS, $now);

            if ($options->apply) {
                $batch = DB::table('archive_batches')->where('id', $batchId)->first();

                return $this->applyBatch($type, $batch, $now, $chunkSize, $throttleMs);
            }

            return new BatchOutcome(...$base, sourceRows: $sourceRows, archivedRows: $archivedRows,
                verifyStatus: 'passed', action: 'copied', verifyDetail: $detail, message: 'dry-run');
        } catch (Throwable $e) {
            // 失败记账：批次 failed + failed_reason，主库零改动（R-009）；异常上抛由命令层定退出码
            DB::table('archive_batches')->where('id', $batchId)->update([
                'verify_status' => 'failed', 'failed_reason' => $e->getMessage(), 'updated_at' => $nowStr,
            ]);

            throw $e;
        }
    }

    /**
     * verify 三级（R-006）：行数 / 行哈希和（可交换，bcmath）/ 抽样全字段规范化串逐位。
     *
     * @return array{0: bool, 1: string, 2: ?string} [通过?, 明细串, 失败原因]
     */
    private function verify(
        ArchiveType $type,
        string $fromS,
        string $toS,
        \DateTimeImmutable $now,
        int $batchId,
        string $archivedAt,
        int $sourceRows,
        int $archivedRows,
        int $chunkSize,
        int $sampleRows,
    ): array {
        $detail = [];
        $reason = null;

        // 第一级：行数
        if ($archivedRows === $sourceRows) {
            $detail[] = 'rows=OK';
        } else {
            return [false, 'rows=MISMATCH', sprintf('行数失配：CH=%d 主库=%d', $archivedRows, $sourceRows)];
        }

        // 第二级：哈希和（主库 bcmath 流式求和 mod 2^64 vs CH sum(UInt64) 溢出回绕）
        $phpSum = '0';
        $this->sliceQuery($type, $fromS, $toS, $now)->chunkById(
            $chunkSize,
            function ($rows) use ($type, $batchId, $archivedAt, &$phpSum): void {
                foreach ($rows as $row) {
                    $canonical = RowNormalizer::canonicalRow($type, $type->toChRow($row, $batchId, $archivedAt));
                    $phpSum = RowNormalizer::addHash($phpSum, RowNormalizer::rowHash($canonical));
                }
            },
        );
        $phpSum = RowNormalizer::mod2p64($phpSum);
        $chSum = $this->store->hashSum($type->chTable(), $batchId, $type->canonicalColumns());
        if ($phpSum === $chSum) {
            $detail[] = 'hash_sum=OK';
        } else {
            return [false, implode(' ', $detail) . ' hash_sum=MISMATCH', sprintf('哈希和失配：CH=%s 主库=%s', $chSum, $phpSum)];
        }

        // 第三级：抽样全字段比对
        $chSample = $this->store->fetchRows(
            $type->chTable(), ['batch_id' => (string) $batchId], $type->timeColumn(),
            null, null, ['id' => 'ASC'], $sampleRows,
        );
        $checked = 0;
        foreach ($chSample as $chRow) {
            $checked++;
            $mainRow = $this->sliceQuery($type, $fromS, $toS, $now)->where('id', $chRow['id'])->first();
            if ($mainRow === null
                || RowNormalizer::canonicalRow($type, $type->toChRow($mainRow, $batchId, $archivedAt)) !== RowNormalizer::canonicalRow($type, $chRow)) {
                return [false, implode(' ', $detail) . sprintf(' sample=%d/%d=MISMATCH', $checked, count($chSample)),
                    sprintf('抽样比对失配：id=%s', (string) $chRow['id'])];
            }
        }
        $detail[] = sprintf('sample=%d/%d=OK', $checked, count($chSample));

        return [true, implode(' ', $detail), null];
    }

    /**
     * US5 独立复检（T026）：对既有批次（copied/passed 未 applied）重跑三级校验，**不写账**。
     * 调用方（archive:verify）负责 failed 降级记账。
     *
     * @return array{0: bool, 1: string, 2: ?string} [passed, detail, reason]
     */
    public function reverifyBatch(ArchiveType $type, object $batch, \DateTimeImmutable $nowUtc): array
    {
        $dataType = $type->dataType()->value;

        return $this->verify(
            $type,
            (string) $batch->time_from,
            (string) $batch->time_to,
            $nowUtc,
            (int) $batch->id,
            // 账目表不存 archived_at；canonicalColumns 已排除审计列，复检比对与其取值无关
            (string) ($batch->created_at ?? '1970-01-01 00:00:00'),
            (int) $batch->source_rows,
            (int) $batch->archived_rows,
            max(1, (int) config("archive.types.{$dataType}.chunk_size", 5000)),
            max(1, (int) config('archive.verify_sample_rows', 5)),
        );
    }

    /**
     * apply（仅 passed 批次）：事务内 { 切片行数再核对 → 分块限速删除 → applied_watermark 推进 → applied_at }
     * （R-010：删除与水位同事务；abort 整体回滚无半删）。
     */
    private function applyBatch(ArchiveType $type, object $batch, \DateTimeImmutable $now, int $chunkSize, int $throttleMs): BatchOutcome
    {
        $dataType = $type->dataType()->value;
        $base = [
            'batchId' => (int) $batch->id, 'dataType' => $dataType,
            'timeFrom' => (string) $batch->time_from, 'timeTo' => (string) $batch->time_to,
        ];

        // 红线 1：verify gate——未过校验的批次任何模式下不得删除（FR-005）
        if ($batch->verify_status !== 'passed' || (int) $batch->source_rows !== (int) $batch->archived_rows) {
            return new BatchOutcome(...$base, sourceRows: (int) $batch->source_rows, archivedRows: (int) $batch->archived_rows,
                verifyStatus: $batch->verify_status, action: 'blocked',
                message: 'verify gate 拒绝：批次未通过校验');
        }

        $nowStr = $now->format('Y-m-d H:i:s');
        try {
            DB::transaction(function () use ($type, $batch, $now, $chunkSize, $throttleMs, $nowStr): void {
                // 红线 2：删除事务内主库切片行数必须仍等于 archived_rows
                $recount = $this->sliceQuery($type, (string) $batch->time_from, (string) $batch->time_to, $now)->count();
                if ($recount !== (int) $batch->archived_rows) {
                    throw new RuntimeException(sprintf('apply 再核对失配：主库=%d archived_rows=%d', $recount, (int) $batch->archived_rows));
                }

                // 分块限速删除（SQLite 无 DELETE LIMIT：先取 id 再 whereIn，constitution II 旁路）
                $table = $this->sliceQuery($type, (string) $batch->time_from, (string) $batch->time_to, $now)->getModel()->getTable();
                do {
                    $ids = $this->sliceQuery($type, (string) $batch->time_from, (string) $batch->time_to, $now)
                        ->orderBy('id')->limit($chunkSize)->pluck('id')->all();
                    if ($ids === []) {
                        break;
                    }
                    DB::table($table)->whereIn('id', $ids)->delete();
                    usleep($throttleMs * 1000);
                } while (count($ids) === $chunkSize);

                // 水位只增推进（同事务，R-010）
                $this->advanceWatermark($type->dataType()->value, 'applied_watermark', (string) $batch->time_to, $now);
                DB::table('archive_batches')->where('id', $batch->id)
                    ->update(['applied_at' => $nowStr, 'updated_at' => $nowStr]);
            });

            return new BatchOutcome(...$base, sourceRows: (int) $batch->source_rows, archivedRows: (int) $batch->archived_rows,
                verifyStatus: 'passed', action: 'deleted');
        } catch (Throwable $e) {
            // 事务已回滚；批次降级 failed 使其退出删除候选（需重新 verify）
            DB::table('archive_batches')->where('id', $batch->id)->update([
                'verify_status' => 'failed', 'failed_reason' => $e->getMessage(), 'updated_at' => $nowStr,
            ]);

            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // 切片查询与水位
    // ------------------------------------------------------------------

    /**
     * 切片查询基底：时间片谓词 + 闭合保险 + tenant 类型显式绕过租户作用域（R-005/R-008）。
     */
    private function sliceQuery(ArchiveType $type, string $fromUtc, string $toUtc, \DateTimeImmutable $nowUtc): Builder
    {
        $query = $type->applySliceGuards($type->hotCopyQuery($fromUtc, $toUtc), $nowUtc);
        if ($type->visibility() === 'tenant') {
            $query->withoutGlobalScope(TenantScope::class);
        }

        return $query;
    }

    private function ensureWatermark(string $dataType, \DateTimeImmutable $now): void
    {
        $exists = DB::table('archive_watermarks')->where('data_type', $dataType)->exists();
        if (! $exists) {
            DB::table('archive_watermarks')->insert([
                'data_type' => $dataType,
                'copied_watermark' => self::EPOCH,
                'applied_watermark' => self::EPOCH,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * 水位只增不减（data-model §2）；applied 推进发生在 apply 事务内。
     */
    private function advanceWatermark(string $dataType, string $column, string $value, \DateTimeImmutable $now): void
    {
        $current = DB::table('archive_watermarks')->where('data_type', $dataType)->value($column);
        if ($current !== null && (string) $current >= $value) {
            return;
        }
        DB::table('archive_watermarks')->where('data_type', $dataType)->update([
            $column => $value, 'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 对齐到窗口网格（Unix epoch 起算的整窗口边界，确定性建批前提）。
     */
    private function alignToWindow(\DateTimeImmutable $time, int $windowHours): \DateTimeImmutable
    {
        $windowSeconds = $windowHours * 3600;
        $aligned = intdiv($time->getTimestamp(), $windowSeconds) * $windowSeconds;

        return (new \DateTimeImmutable('@' . $aligned))->setTimezone(new \DateTimeZone('UTC'));
    }
}
