<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Tenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * archive:status 归档状态查询（spec 004 US5 / T027 / FR-009）。
 *
 * 每类型：retention_days / enabled / 双水位 / 待归档量估算
 * （COUNT 谓词 time >= copied_watermark AND time < now-retention_days）/ 最近 5 批次清单。
 * {type} 省略 = 四类型逐段输出；未启用类型标注 disabled。纯只读，退出码恒 0（用法错误 2）。
 */
final class ArchiveStatusCommand extends Command
{
    private const EPOCH = '1970-01-01 00:00:00';

    protected $signature = 'archive:status
        {type? : 数据类型 kline_bar|signal|strategy_order|liquidation_event，省略 = 全部类型（含 disabled 标注）}';

    protected $description = '归档进度状态：水位/待归档量估算/最近批次清单';

    public function handle(): int
    {
        $typesConfig = config('archive.types');
        $typeArg = $this->argument('type');
        if ($typeArg !== null) {
            $dataType = ArchiveDataType::tryFrom((string) $typeArg);
            if ($dataType === null) {
                $this->error(sprintf('未知数据类型：%s（可选 kline_bar|signal|strategy_order|liquidation_event）', $typeArg));

                return self::INVALID;
            }
            $types = [$dataType];
        } else {
            $types = array_filter(
                array_map(static fn (string $value): ?ArchiveDataType => ArchiveDataType::tryFrom($value), array_keys($typesConfig)),
            );
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($types as $dataType) {
            $value = $dataType->value;
            $cfg = $typesConfig[$value] ?? [];
            if (! ($cfg['enabled'] ?? false)) {
                $this->line(sprintf('[%s] disabled（config archive.types.%s.enabled=false）', $value, $value));

                continue;
            }

            $retention = (int) ($cfg['retention_days'] ?? 0);
            $watermark = DB::table('archive_watermarks')->where('data_type', $value)->first();
            $copied = (string) ($watermark->copied_watermark ?? self::EPOCH);
            $applied = (string) ($watermark->applied_watermark ?? self::EPOCH);

            $this->line(sprintf('[%s] enabled retention_days=%d', $value, $retention));
            $this->line(sprintf('  copied_watermark=%s  applied_watermark=%s', $copied, $applied));

            // 待归档量估算（R-005 安全上界；tenant 类型系统级统计绕过 Scope——平台运维视图）
            $typeClass = $dataType->typeClass();
            $type = new $typeClass;
            $safeTo = $now->modify(sprintf('-%d days', $retention))->format('Y-m-d H:i:s');
            $estimate = 0;
            if ($copied < $safeTo) {
                $query = $type->applySliceGuards($type->hotCopyQuery($copied, $safeTo), $now);
                if ($type->visibility() === 'tenant') {
                    $query->withoutGlobalScope(TenantScope::class);
                }
                $estimate = $query->count();
            }
            $this->line(sprintf('  待归档量估算：%d 行（time >= %s AND time < %s）', $estimate, $copied, $safeTo));

            // 最近 5 批次（新→旧取 5，展示按时间正序）
            $batches = DB::table('archive_batches')
                ->where('data_type', $value)
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->reverse();
            $this->line('  最近 5 批次：');
            if ($batches->isEmpty()) {
                $this->line('    （无）');
            }
            foreach ($batches as $batch) {
                $this->line(sprintf(
                    '    [batch %d] %s ~ %s source_rows=%d verify=%s applied_at=%s',
                    $batch->id,
                    $batch->time_from,
                    $batch->time_to,
                    $batch->source_rows,
                    $batch->verify_status,
                    $batch->applied_at ?? '-',
                ));
            }
        }

        return self::SUCCESS;
    }
}
