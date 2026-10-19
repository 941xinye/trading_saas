<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Services\ArchivePipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * archive:verify 独立复检命令（spec 004 US5 / T026 / contracts/archive-pipeline-contract.md §1/§4）。
 *
 * 对 copied/passed 未 applied 批次重跑三级校验（归档前后各跑一次的独立校验位）；
 * 复检 failed 立即降级记账（verify_status=failed + failed_reason），永不进入 apply 删除候选（FR-005）。
 * 退出码：0 全部通过 / 1 复检失败或运行期失败 / 2 用法参数错误。
 */
final class ArchiveVerifyCommand extends Command
{
    protected $signature = 'archive:verify
        {type? : 数据类型 kline_bar|signal|strategy_order|liquidation_event，省略 = 全部启用类型}
        {--batch= : 指定批次 id（需配合 {type}；省略 = 该类型全部 copied/passed 未 applied 批次）}';

    protected $description = '独立复检归档批次（三级校验），不一致批次立即降级 failed 并阻断删除';

    public function handle(ArchivePipeline $pipeline): int
    {
        // ---- 类型解析（用法错误 → 2，与 archive:run 同语义）----
        $typesConfig = config('archive.types');
        $typeArg = $this->argument('type');
        if ($typeArg !== null) {
            $dataType = ArchiveDataType::tryFrom((string) $typeArg);
            if ($dataType === null) {
                $this->error(sprintf('未知数据类型：%s（可选 kline_bar|signal|strategy_order|liquidation_event）', $typeArg));

                return self::INVALID;
            }
            if (! ($typesConfig[$dataType->value]['enabled'] ?? false)) {
                $this->error(sprintf('数据类型未启用：%s', $dataType->value));

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

        // ---- --batch 校验 ----
        $batchId = null;
        if ($this->option('batch') !== null) {
            $batchId = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
            if ($batchId === false || $batchId < 1) {
                $this->error(sprintf('--batch 必须为正整数：%s', $this->option('batch')));

                return self::INVALID;
            }
            if ($typeArg === null) {
                $this->error('--batch 需配合 {type} 使用（批次归属类型需明确）');

                return self::INVALID;
            }
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $exitCode = self::SUCCESS;

        foreach ($types as $dataType) {
            $query = DB::table('archive_batches')
                ->where('data_type', $dataType->value)
                ->whereNull('applied_at')
                ->whereIn('verify_status', ['copied', 'passed'])
                ->orderBy('id');
            if ($batchId !== null) {
                $query->where('id', $batchId);
            }
            $batches = $query->get();

            if ($batches->isEmpty()) {
                if ($batchId !== null) {
                    $this->error(sprintf('批次 %d 不存在或不在复检范围（copied/passed 未 applied）', $batchId));

                    return self::INVALID;
                }
                $this->line(sprintf('[%s] 无待复检批次（copied/passed 未 applied）', $dataType->value));

                continue;
            }

            $typeClass = $dataType->typeClass();
            $type = new $typeClass;
            $passed = 0;
            $failed = 0;

            foreach ($batches as $batch) {
                $this->line(sprintf('[batch %d] %s %s ~ %s', $batch->id, $dataType->value, $batch->time_from, $batch->time_to));
                $this->line(sprintf('  source_rows=%d archived_rows=%d', $batch->source_rows, $batch->archived_rows));

                try {
                    [$ok, $detail, $reason] = $pipeline->reverifyBatch($type, $batch, $now);
                } catch (Throwable $e) {
                    $this->error(sprintf('  复检运行期失败：%s（批次保持原状态，恢复后重跑）', $e->getMessage()));

                    return self::FAILURE;
                }

                if ($ok) {
                    $passed++;
                    $this->line(sprintf('  verify: %s  → PASSED', $detail));
                    $this->line('  action: rechecked passed');

                    continue;
                }

                // 降级记账：failed 批次永不进入 apply 删除候选（FR-005）
                $failed++;
                DB::table('archive_batches')->where('id', $batch->id)->update([
                    'verify_status' => 'failed',
                    'failed_reason' => '复检降级：' . ($reason ?? 'unknown'),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);
                $this->line(sprintf('  verify: %s  → FAILED', $detail));
                $this->line('  action: demoted to failed: ' . ($reason ?? 'unknown'));
            }

            $this->line(sprintf('汇总[%s]：%d 批复检 / %d passed / %d failed（failed 已降级，不可进入 apply 候选）', $dataType->value, $batches->count(), $passed, $failed));
            if ($failed > 0) {
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }
}
