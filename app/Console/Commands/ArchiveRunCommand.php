<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Dto\ArchiveRunOptions;
use App\Domain\Archive\Dto\BatchOutcome;
use App\Domain\Archive\Services\ArchivePipeline;
use Illuminate\Console\Command;
use Throwable;

/**
 * archive:run 归档管线命令（spec 004 T014 / contracts/archive-pipeline-contract.md §1/§4）。
 *
 * dry-run 默认（copy+verify 真实执行，不删主库）；--apply 显式二次确认删除。
 * 退出码：0 成功 / 1 运行期失败（failed_reason 已记账）/ 2 用法参数错误（含红线 4 下限）。
 */
final class ArchiveRunCommand extends Command
{
    protected $signature = 'archive:run
        {type? : 数据类型 kline_bar|signal|strategy_order|liquidation_event，省略 = 全部启用类型}
        {--apply : 对 verify passed 批次执行主库删除（默认 dry-run）}
        {--batch-window-hours= : 批次时间片窗口小时数（默认取 config）}
        {--chunk-size= : 主库分块读取行数（默认取 config）}
        {--throttle-ms= : 块间限速毫秒（默认取 config）}
        {--max-batches= : 本次最多处理批次数（首跑存量限速）}
        {--from= : 切片起点（UTC，Y-m-d 或 Y-m-d H:i:s）}
        {--to= : 切片终点（UTC，越安全上界截断并警告）}';

    protected $description = '归档冷数据到 ClickHouse（建批→copy→verify→[--apply] 删除），默认 dry-run';

    public function handle(ArchivePipeline $pipeline): int
    {
        // ---- 类型解析（用法错误 → 2）----
        $typesConfig = config('archive.types');
        $typeArg = $this->argument('type');
        if ($typeArg !== null) {
            $dataType = ArchiveDataType::tryFrom((string) $typeArg);
            if ($dataType === null) {
                $this->error(sprintf('未知数据类型：%s（可选 kline_bar|signal|strategy_order|liquidation_event）', $typeArg));

                return self::INVALID;
            }
            if (! ($typesConfig[$dataType->value]['enabled'] ?? false)) {
                $this->error(sprintf('数据类型未启用：%s（ARCHIVE_%s_ENABLED=false）', $dataType->value, strtoupper($dataType->value)));

                return self::INVALID;
            }
            $types = [$dataType];
        } else {
            $types = [];
            foreach (array_keys($typesConfig) as $value) {
                $dataType = ArchiveDataType::tryFrom((string) $value);
                if ($dataType !== null && ($typesConfig[$value]['enabled'] ?? false)) {
                    $types[] = $dataType;
                }
            }
        }

        // ---- 参数校验（红线 4：越下限 → 2）----
        $chunkSize = $this->option('chunk-size') !== null ? (int) $this->option('chunk-size') : null;
        $throttleMs = $this->option('throttle-ms') !== null ? (int) $this->option('throttle-ms') : null;
        $windowHours = $this->option('batch-window-hours') !== null ? (int) $this->option('batch-window-hours') : null;
        $maxBatches = $this->option('max-batches') !== null ? (int) $this->option('max-batches') : null;

        if ($chunkSize !== null && $chunkSize < (int) config('archive.min_chunk_size')) {
            $this->error(sprintf('--chunk-size=%d 低于下限 %d（防误配打满主库 IO）', $chunkSize, (int) config('archive.min_chunk_size')));

            return self::INVALID;
        }
        if ($throttleMs !== null && $throttleMs < (int) config('archive.min_throttle_ms')) {
            $this->error(sprintf('--throttle-ms=%d 低于下限 %d（防误配打满主库 IO）', $throttleMs, (int) config('archive.min_throttle_ms')));

            return self::INVALID;
        }
        if ($windowHours !== null && $windowHours <= 0) {
            $this->error('--batch-window-hours 必须为正整数');

            return self::INVALID;
        }
        if ($maxBatches !== null && $maxBatches <= 0) {
            $this->error('--max-batches 必须为正整数');

            return self::INVALID;
        }

        $fromUtc = $this->option('from') !== null ? $this->parseUtc((string) $this->option('from')) : null;
        $toUtc = $this->option('to') !== null ? $this->parseUtc((string) $this->option('to')) : null;
        if ($this->option('from') !== null && $fromUtc === null) {
            $this->error(sprintf('--from 格式非法：%s（Y-m-d 或 Y-m-d H:i:s）', $this->option('from')));

            return self::INVALID;
        }
        if ($this->option('to') !== null && $toUtc === null) {
            $this->error(sprintf('--to 格式非法：%s（Y-m-d 或 Y-m-d H:i:s）', $this->option('to')));

            return self::INVALID;
        }

        // ---- 执行 ----
        $exitCode = self::SUCCESS;
        foreach ($types as $dataType) {
            $options = new ArchiveRunOptions(
                apply: (bool) $this->option('apply'),
                batchWindowHours: $windowHours,
                chunkSize: $chunkSize,
                throttleMs: $throttleMs,
                maxBatches: $maxBatches,
                fromUtc: $fromUtc,
                toUtc: $toUtc,
            );

            try {
                $typeClass = $dataType->typeClass();
                $report = $pipeline->run(new $typeClass, $options);
            } catch (Throwable $e) {
                $this->error(sprintf('[%s] 运行期失败：%s（失败批次已记账，主库零污染，恢复后重跑即可续传）', $dataType->value, $e->getMessage()));

                return self::FAILURE;
            }

            foreach ($report->warnings as $warning) {
                $this->warn(sprintf('[%s] %s', $dataType->value, $warning));
            }

            foreach ($report->batches as $batch) {
                $this->renderBatch($batch);
            }

            $deletable = $report->deletableRange();
            $rangeText = $deletable['from'] !== null
                ? sprintf('[%s, %s) 共 %d 行', $deletable['from'], $deletable['to'], $deletable['rows'])
                : '无';
            $suffix = $this->option('apply')
                ? sprintf('已删除 %d 行', $deletable['rows'])
                : sprintf('可删范围 %s（--apply 未加，未删除）', $rangeText);
            $this->line(sprintf(
                '汇总[%s]：%d 批处理 / %d passed / %d failed / %s',
                $dataType->value,
                count($report->batches),
                $report->passedCount(),
                $report->failedCount(),
                $suffix,
            ));

            if ($report->failedCount() > 0) {
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    private function renderBatch(BatchOutcome $batch): void
    {
        $this->line(sprintf('[batch %d] %s %s ~ %s', $batch->batchId, $batch->dataType, $batch->timeFrom, $batch->timeTo));
        $this->line(sprintf('  source_rows=%d archived_rows=%d', $batch->sourceRows, $batch->archivedRows));

        $verifyLine = sprintf('  verify: %s  → %s', $batch->verifyDetail !== '' ? $batch->verifyDetail : 'n/a', strtoupper($batch->verifyStatus));
        $this->line($verifyLine);

        $action = match ($batch->action) {
            'copied' => 'copied (dry-run)',
            'deleted' => 'deleted (apply)',
            'skipped' => 'skipped: ' . ($batch->message ?? 'already applied'),
            'failed' => 'failed: ' . ($batch->message ?? 'unknown'),
            default => 'blocked: ' . ($batch->message ?? 'unknown'),
        };
        $this->line('  action: ' . $action);
    }

    /**
     * UTC 时间解析：Y-m-d 或 Y-m-d H:i:s，非法返回 null。
     */
    private function parseUtc(string $value): ?string
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d'] as $format) {
            $time = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($time !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                // Y-m-d 归一为当日 00:00:00
                return $time->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}
