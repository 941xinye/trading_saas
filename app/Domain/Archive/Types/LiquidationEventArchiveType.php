<?php

declare(strict_types=1);

namespace App\Domain\Archive\Types;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\ArchiveType;
use App\Domain\Archive\Stores\RowNormalizer;
use App\Models\LiquidationEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * liquidation_events 归档策略（public 数据，R-008）。
 *
 * 时间轴 = event_ts（R-005）；列映射 = contracts/clickhouse-schema-contract.md ch_liquidation_events：
 *   value_usd Decimal(20,2)；exchange_event_id 为 String 非 Nullable（null→''，幂等键保真）。
 */
final class LiquidationEventArchiveType implements ArchiveType
{
    public function dataType(): ArchiveDataType
    {
        return ArchiveDataType::LiquidationEvent;
    }

    public function visibility(): string
    {
        return 'public';
    }

    public function chTable(): string
    {
        return 'ch_liquidation_events';
    }

    public function timeColumn(): string
    {
        return 'event_ts';
    }

    public function hotQuery(): Builder
    {
        return LiquidationEvent::query();
    }

    public function hotCopyQuery(string $fromUtc, string $toUtc): Builder
    {
        return LiquidationEvent::query()
            ->where('event_ts', '>=', $fromUtc)
            ->where('event_ts', '<', $toUtc)
            ->orderBy('id');
    }

    public function applySliceGuards(Builder $query, \DateTimeImmutable $nowUtc): Builder
    {
        return $query; // 事件落库即不可变
    }

    public function toChRow(\Illuminate\Database\Eloquent\Model $row, int $batchId, string $archivedAtUtc): array
    {
        /** @var LiquidationEvent $row */
        return [
            'id' => (string) $row->getKey(),
            'exchange_code' => (string) $row->exchange_code,
            'symbol' => (string) $row->symbol,
            'side' => (string) $row->side,
            'price' => (string) $row->price,
            'qty' => (string) $row->qty,
            'value_usd' => (string) $row->value_usd,
            'event_ts' => RowNormalizer::ts($row->event_ts),
            'exchange_event_id' => $row->exchange_event_id ?? '',
            'created_at' => RowNormalizer::ts($row->created_at),
            'updated_at' => RowNormalizer::ts($row->updated_at),
            'batch_id' => (string) $batchId,
            'archived_at' => $archivedAtUtc,
        ];
    }

    public function canonicalColumns(): array
    {
        return [
            'id', 'exchange_code', 'symbol', 'side', 'price', 'qty', 'value_usd',
            'event_ts', 'exchange_event_id', 'created_at', 'updated_at',
        ];
    }
}
