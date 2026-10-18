<?php

declare(strict_types=1);

namespace App\Domain\Archive\Types;

use App\Domain\Archive\Contracts\ArchiveDataType;
use App\Domain\Archive\Contracts\ArchiveType;
use App\Domain\Archive\Stores\RowNormalizer;
use App\Models\Signal;
use Illuminate\Database\Eloquent\Builder;

/**
 * signals 归档策略（tenant 数据，R-008 隔离下沉到查询构造层）。
 *
 * 时间轴 = triggered_at（R-005）；列映射 = contracts/clickhouse-schema-contract.md ch_signals：
 *   stop_loss 可空 Decimal 保真（Nullable）；rationale/raw_payload 为 String 非 Nullable（null→''）；
 *   raw_payload 取 getRawOriginal 原 JSON 串存，不解析不重排（round-trip 逐位一致的前提）。
 */
final class SignalArchiveType implements ArchiveType
{
    public function dataType(): ArchiveDataType
    {
        return ArchiveDataType::Signal;
    }

    public function visibility(): string
    {
        return 'tenant';
    }

    public function chTable(): string
    {
        return 'ch_signals';
    }

    public function timeColumn(): string
    {
        return 'triggered_at';
    }

    public function hotQuery(): Builder
    {
        return Signal::query();
    }

    public function hotCopyQuery(string $fromUtc, string $toUtc): Builder
    {
        return Signal::query()
            ->where('triggered_at', '>=', $fromUtc)
            ->where('triggered_at', '<', $toUtc)
            ->orderBy('id');
    }

    public function applySliceGuards(Builder $query, \DateTimeImmutable $nowUtc): Builder
    {
        return $query; // 无「活数据」问题：信号落库即不可变
    }

    public function toChRow(\Illuminate\Database\Eloquent\Model $row, int $batchId, string $archivedAtUtc): array
    {
        /** @var Signal $row */
        return [
            'id' => (string) $row->getKey(),
            'tenant_id' => (string) $row->tenant_id,
            'subscription_id' => (string) $row->subscription_id,
            'symbol' => (string) $row->symbol,
            'interval' => (string) $row->interval,
            'direction' => (string) $row->direction,
            'entry_price' => (string) $row->entry_price,
            'exit_price' => (string) $row->exit_price,
            'stop_loss' => $row->stop_loss === null ? null : (string) $row->stop_loss,
            'confidence' => (string) $row->confidence,
            'source' => (string) $row->source,
            'rationale' => $row->rationale ?? '',
            'raw_payload' => $row->getRawOriginal('raw_payload') ?? '',
            'triggered_at' => RowNormalizer::ts($row->triggered_at),
            'created_at' => RowNormalizer::ts($row->created_at),
            'updated_at' => RowNormalizer::ts($row->updated_at),
            'batch_id' => (string) $batchId,
            'archived_at' => $archivedAtUtc,
        ];
    }

    public function canonicalColumns(): array
    {
        return [
            'id', 'tenant_id', 'subscription_id', 'symbol', 'interval', 'direction',
            'entry_price', 'exit_price', 'stop_loss', 'confidence', 'source',
            'rationale', 'raw_payload', 'triggered_at', 'created_at', 'updated_at',
        ];
    }
}
