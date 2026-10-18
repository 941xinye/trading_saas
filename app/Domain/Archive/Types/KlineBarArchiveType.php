<?php

declare(strict_types=1);

namespace App\Domain\Archive\Types;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\ArchiveType;
use App\Domain\Archive\Stores\RowNormalizer;
use App\Models\KlineBar;
use Illuminate\Database\Eloquent\Builder;

/**
 * kline_bars 归档策略（public 数据，R-008）。
 *
 * 时间轴 = open_time（R-005）；「活数据」保险：切片不含 close_time >= now-5min 的未闭合行。
 * 列映射 = contracts/clickhouse-schema-contract.md ch_kline_bars（Decimal(20,8)×6 + trades_count UInt32）。
 */
final class KlineBarArchiveType implements ArchiveType
{
    public function dataType(): ArchiveDataType
    {
        return ArchiveDataType::KlineBar;
    }

    public function visibility(): string
    {
        return 'public';
    }

    public function chTable(): string
    {
        return 'ch_kline_bars';
    }

    public function timeColumn(): string
    {
        return 'open_time';
    }

    public function hotQuery(): Builder
    {
        return KlineBar::query();
    }

    public function hotCopyQuery(string $fromUtc, string $toUtc): Builder
    {
        return KlineBar::query()
            ->where('open_time', '>=', $fromUtc)
            ->where('open_time', '<', $toUtc)
            ->orderBy('id');
    }

    public function applySliceGuards(Builder $query, \DateTimeImmutable $nowUtc): Builder
    {
        // 闭合保险（R-005）：retention 被配小时只防御性排除未闭合 bar
        return $query->where('close_time', '<', $nowUtc->modify('-5 minutes')->format('Y-m-d H:i:s'));
    }

    public function toChRow(\Illuminate\Database\Eloquent\Model $row, int $batchId, string $archivedAtUtc): array
    {
        /** @var KlineBar $row */
        return [
            'id' => (string) $row->getKey(),
            'exchange_code' => (string) $row->exchange_code,
            'symbol' => (string) $row->symbol,
            'interval' => (string) $row->interval,
            'open_time' => RowNormalizer::ts($row->open_time),
            'close_time' => RowNormalizer::ts($row->close_time),
            'open' => (string) $row->open,
            'high' => (string) $row->high,
            'low' => (string) $row->low,
            'close' => (string) $row->close,
            'volume' => (string) $row->volume,
            'quote_volume' => (string) $row->quote_volume,
            'trades_count' => (string) $row->trades_count,
            'created_at' => RowNormalizer::ts($row->created_at),
            'updated_at' => RowNormalizer::ts($row->updated_at),
            'batch_id' => (string) $batchId,
            'archived_at' => $archivedAtUtc,
        ];
    }

    public function canonicalColumns(): array
    {
        // DDL 业务列序（不含 batch_id/archived_at 审计列）
        return [
            'id', 'exchange_code', 'symbol', 'interval', 'open_time', 'close_time',
            'open', 'high', 'low', 'close', 'volume', 'quote_volume', 'trades_count',
            'created_at', 'updated_at',
        ];
    }
}
