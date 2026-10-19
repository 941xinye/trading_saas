<?php

declare(strict_types=1);

namespace App\Domain\Archive\Services;

use App\Domain\Archive\Contracts\ArchiveStore;
use App\Domain\Archive\Contracts\ArchiveType;
use App\Domain\Archive\Contracts\HistoricalQuery;
use App\Domain\Archive\Dto\HistoricalQueryRequest;
use App\Domain\Archive\Dto\HistoricalQueryResult;
use App\Domain\Archive\Exceptions\ArchiveQueryLimitException;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * 统一历史查询服务（spec 004 US2 / T020 / contracts/historical-query-contract.md §2，R-007）。
 *
 * 路由基准 = archive_watermarks.applied_watermark（W，缺失行 = epoch 即纯热）：
 *   to <= W  → 纯冷（只查 CH）；
 *   from >= W → 纯热（只查主库，CH 故障零影响，US4 机制性保证）；
 *   跨界     → 冷侧 [from, to) 全段 + 热侧 [W, to)，(时间列 ASC, id ASC) 归并 + id 去重。
 *
 * 冷侧为何查全段而非截到 W：in-flight 批次行（已写 CH、主库未删）时间 >= W，
 * 与热侧同现，靠自然键 id 去重保留一条（R-007；内容逐位一致，取热侧）。
 *
 * 分页：keyset 游标 = (最后行时间列值, 最后行 id) base64 不透明串；两侧各取 pageSize+1
 * 归并后 > pageSize 即有更多页（任一侧返回满页即未耗尽）。行形态 = canonicalColumns 投影，
 * 冷热同形，Decimal/时间全字符串（R-004）。
 *
 * 租户隔离（R-008）：tenant 类型强制 TenantContext::requireTenantId()——热侧 TenantScope
 * 自动注入同谓词，冷侧 filters 显式带 tenant_id，两层等价；无上下文抛 RuntimeException。
 */
final class HistoricalQueryService implements HistoricalQuery
{
    private const EPOCH = '1970-01-01 00:00:00';

    public function __construct(private readonly ArchiveStore $store)
    {
    }

    public function query(HistoricalQueryRequest $request): HistoricalQueryResult
    {
        $typeClass = $request->dataType->typeClass();
        /** @var ArchiveType $type */
        $type = new $typeClass;

        $pageSize = $request->pageSize ?? (int) config('archive.query_page_size', 1000);
        $maxRows = (int) config('archive.query_max_rows', 50000);
        if ($pageSize > $maxRows) {
            throw new ArchiveQueryLimitException(sprintf(
                'pageSize %d 超上限 archive.query_max_rows=%d（FR-011 显式报错，不静默截断）',
                $pageSize,
                $maxRows,
            ));
        }

        // 租户隔离强制点（contracts §4）：无上下文即抛，越权通道物理不存在
        $tenantId = $type->visibility() === 'tenant' ? TenantContext::requireTenantId() : null;

        $cursor = $this->decodeCursor($request->cursor);

        $from = $request->fromUtc->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to = $request->toUtc->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $w = (string) (DB::table('archive_watermarks')
            ->where('data_type', $request->dataType->value)
            ->value('applied_watermark') ?? self::EPOCH);

        $filters = array_filter([
            'symbol' => $request->symbol,
            'exchange_code' => $request->exchangeCode,
            'interval' => $request->interval,
        ], static fn (?string $v): bool => $v !== null);
        $coldFilters = $tenantId !== null ? $filters + ['tenant_id' => (string) $tenantId] : $filters;

        $coldRows = [];
        $hotRows = [];
        if ($to <= $w) {
            $servedFrom = HistoricalQueryResult::COLD;
            $coldRows = $this->fetchCold($type, $coldFilters, $from, $to, $cursor, $pageSize + 1);
        } elseif ($from >= $w) {
            $servedFrom = HistoricalQueryResult::HOT;
            $hotRows = $this->fetchHot($type, $filters, $from, $to, $cursor, $pageSize + 1);
        } else {
            $servedFrom = HistoricalQueryResult::MERGED;
            $coldRows = $this->fetchCold($type, $coldFilters, $from, $to, $cursor, $pageSize + 1);
            $hotRows = $this->fetchHot($type, $filters, $w, $to, $cursor, $pageSize + 1);
        }

        $rows = $this->mergeRows($type->timeColumn(), $hotRows, $coldRows);

        $hasMore = count($rows) > $pageSize;
        $page = array_slice($rows, 0, $pageSize);
        $nextCursor = null;
        if ($hasMore && $page !== []) {
            $last = $page[count($page) - 1];
            $nextCursor = base64_encode((string) json_encode([$last[$type->timeColumn()], $last['id']]));
        }

        return new HistoricalQueryResult($page, $nextCursor, $servedFrom);
    }

    /**
     * 冷侧读取（CH 经 ArchiveStore，keyset 游标下推到存储层 WHERE，故障原样上抛 R-009）。
     *
     * @param array<string, string> $filters
     * @param ?array{time: string, id: string} $cursor
     * @return list<array<string, string|null>>
     */
    private function fetchCold(ArchiveType $type, array $filters, string $from, string $to, ?array $cursor, int $limit): array
    {
        $rows = $this->store->fetchRows(
            $type->chTable(),
            $filters,
            $type->timeColumn(),
            $from,
            $to,
            [$type->timeColumn() => 'ASC', 'id' => 'ASC'],
            $limit,
            0,
            $cursor,
        );

        return array_map(fn (array $row): array => $this->project($type, $row), $rows);
    }

    /**
     * 热侧读取（复用既有 Eloquent 模型，TenantScope 自动注入继续生效；keyset 同语义）。
     *
     * @param array<string, string> $filters
     * @param ?array{time: string, id: string} $cursor
     * @return list<array<string, string|null>>
     */
    private function fetchHot(ArchiveType $type, array $filters, string $from, string $to, ?array $cursor, int $limit): array
    {
        $timeColumn = $type->timeColumn();
        $query = $type->hotQuery()
            ->where($timeColumn, '>=', $from)
            ->where($timeColumn, '<', $to);
        foreach ($filters as $column => $value) {
            $query->where($column, $value);
        }
        if ($cursor !== null) {
            $query->where(static function ($q) use ($timeColumn, $cursor): void {
                $q->where($timeColumn, '>', $cursor['time'])
                    ->orWhere(static function ($q2) use ($timeColumn, $cursor): void {
                        $q2->where($timeColumn, '=', $cursor['time'])
                            ->where('id', '>', (int) $cursor['id']);
                    });
            });
        }

        return $query
            ->orderBy($timeColumn)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (\Illuminate\Database\Eloquent\Model $model): array => $this->project($type, $type->toChRow($model, 0, '')))
            ->all();
    }

    /**
     * (时间列 ASC, id ASC) 归并 + 自然键 id 去重（热侧优先——in-flight 行主库是即时真相）。
     *
     * @param list<array<string, string|null>> $hotRows
     * @param list<array<string, string|null>> $coldRows
     * @return list<array<string, string|null>>
     */
    private function mergeRows(string $timeColumn, array $hotRows, array $coldRows): array
    {
        $byId = [];
        foreach (array_merge($hotRows, $coldRows) as $row) {
            $id = (string) $row['id'];
            if (! isset($byId[$id])) {
                $byId[$id] = $row;
            }
        }
        $rows = array_values($byId);
        usort($rows, static fn (array $a, array $b): int => [(string) $a[$timeColumn], (int) $a['id']] <=> [(string) $b[$timeColumn], (int) $b['id']]);

        return $rows;
    }

    /**
     * 行投影到 canonicalColumns（冷热同形，审计列不外泄；值全字符串/null）。
     *
     * @param array<string, mixed> $row
     * @return array<string, string|null>
     */
    private function project(ArchiveType $type, array $row): array
    {
        $out = [];
        foreach ($type->canonicalColumns() as $column) {
            $value = $row[$column] ?? null;
            $out[$column] = $value === null ? null : (string) $value;
        }

        return $out;
    }

    /**
     * 游标解码（不透明 base64 [时间列值, id]；非法即 ArchiveQueryLimitException，FR-011）。
     *
     * @return ?array{time: string, id: string}
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            throw new ArchiveQueryLimitException('非法游标（base64 解码失败）');
        }
        $payload = json_decode($decoded, true);
        if (! is_array($payload) || count($payload) !== 2 || ! is_string($payload[0]) || ! is_scalar($payload[1] ?? null)) {
            throw new ArchiveQueryLimitException('非法游标（格式不识别）');
        }

        return ['time' => $payload[0], 'id' => (string) $payload[1]];
    }
}
