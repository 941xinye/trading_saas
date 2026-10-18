<?php

declare(strict_types=1);

namespace App\Domain\Archive\Types;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\ArchiveType;
use App\Domain\Archive\Stores\RowNormalizer;
use App\Models\StrategyOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * strategy_orders 归档策略（tenant 数据，R-008）。
 *
 * 时间轴 = created_at 而非 placed_at：切片/删除谓词必须建立在非空列上（R-005 纯时间片语义）。
 * 列映射 = contracts/clickhouse-schema-contract.md ch_strategy_orders：
 *   exchange_order_id/price/stop_price/leverage/avg_fill_price/placed_at/filled_at 可空保真（Nullable）；
 *   error_message 为 String 非 Nullable（null→''）；reduce_only bool → UInt8 '0'/'1'。
 */
final class StrategyOrderArchiveType implements ArchiveType
{
    public function dataType(): ArchiveDataType
    {
        return ArchiveDataType::StrategyOrder;
    }

    public function visibility(): string
    {
        return 'tenant';
    }

    public function chTable(): string
    {
        return 'ch_strategy_orders';
    }

    public function timeColumn(): string
    {
        return 'created_at';
    }

    public function hotQuery(): Builder
    {
        return StrategyOrder::query();
    }

    public function hotCopyQuery(string $fromUtc, string $toUtc): Builder
    {
        return StrategyOrder::query()
            ->where('created_at', '>=', $fromUtc)
            ->where('created_at', '<', $toUtc)
            ->orderBy('id');
    }

    public function applySliceGuards(Builder $query, \DateTimeImmutable $nowUtc): Builder
    {
        return $query; // 订单行只在近期被 UPDATE，超保留期切片内不可变（R-005）
    }

    public function toChRow(\Illuminate\Database\Eloquent\Model $row, int $batchId, string $archivedAtUtc): array
    {
        /** @var StrategyOrder $row */
        return [
            'id' => (string) $row->getKey(),
            'tenant_id' => (string) $row->tenant_id,
            'strategy_instance_id' => (string) $row->strategy_instance_id,
            'exchange_order_id' => $row->exchange_order_id, // 可空保真
            'client_order_id' => (string) $row->client_order_id,
            'symbol' => (string) $row->symbol,
            'side' => (string) $row->side,
            'type' => (string) $row->type,
            'quantity' => (string) $row->quantity,
            'price' => $row->price === null ? null : (string) $row->price,
            'stop_price' => $row->stop_price === null ? null : (string) $row->stop_price,
            'leverage' => $row->leverage === null ? null : (string) $row->leverage,
            'reduce_only' => $row->reduce_only ? '1' : '0',
            'status' => (string) $row->status,
            'filled_qty' => (string) $row->filled_qty,
            'avg_fill_price' => $row->avg_fill_price === null ? null : (string) $row->avg_fill_price,
            'fee' => (string) $row->fee,
            'error_message' => $row->error_message ?? '',
            'placed_at' => RowNormalizer::ts($row->placed_at),
            'filled_at' => RowNormalizer::ts($row->filled_at),
            'created_at' => RowNormalizer::ts($row->created_at),
            'updated_at' => RowNormalizer::ts($row->updated_at),
            'batch_id' => (string) $batchId,
            'archived_at' => $archivedAtUtc,
        ];
    }

    public function canonicalColumns(): array
    {
        return [
            'id', 'tenant_id', 'strategy_instance_id', 'exchange_order_id', 'client_order_id',
            'symbol', 'side', 'type', 'quantity', 'price', 'stop_price', 'leverage',
            'reduce_only', 'status', 'filled_qty', 'avg_fill_price', 'fee', 'error_message',
            'placed_at', 'filled_at', 'created_at', 'updated_at',
        ];
    }
}
