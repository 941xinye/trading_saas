<?php

declare(strict_types=1);

namespace App\Domain\Archive\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 归档数据类型策略契约（spec 004 / plan「数据类型差异内聚在 ArchiveType 策略」/ R-005 / R-008）。
 *
 * 管线（ArchivePipeline）与查询服务（HistoricalQueryService）对本接口无感知具体类型；
 * 第五类数据接入 = 新增一个实现（开闭性验证锚点）。
 *
 * 时间片语义（R-005）：批次切片、copy/verify/delete 三步共用同一谓词
 * `time >= from AND time < to`（左闭右开，UTC 字符串 'Y-m-d H:i:s'）。
 */
interface ArchiveType
{
    /**
     * 数据类型标识。
     */
    public function dataType(): ArchiveDataType;

    /**
     * 可见性域（R-008）：'tenant'（signals/strategy_orders，强制租户隔离）| 'public'（kline/liquidation，平台公共）。
     */
    public function visibility(): string;

    /**
     * CH 冷表名（DDL 唯一事实源 = contracts/clickhouse-schema-contract.md）。
     */
    public function chTable(): string;

    /**
     * 时间轴列（切片键，R-005）：kline_bar=open_time、signal=triggered_at、
     * strategy_order=created_at（非空列，placed_at 可空不可作切片键）、liquidation_event=event_ts。
     */
    public function timeColumn(): string;

    /**
     * 热侧查询基底（复用既有 Eloquent 模型，TenantScope 自动注入继续生效）。
     */
    public function hotQuery(): Builder;

    /**
     * 归档切片查询基底：时间切片谓词 + id 升序（copy 流式分块 / count / 删除共用）。
     *
     * @param string $fromUtc 'Y-m-d H:i:s'（含）
     * @param string $toUtc   'Y-m-d H:i:s'（不含）
     */
    public function hotCopyQuery(string $fromUtc, string $toUtc): Builder;

    /**
     * 切片附加保险（「活数据」edge case）：kline_bars 追加 close_time 闭合保险
     * （切片不含 close_time >= now-5min 的行，R-005）；其余类型原样返回。
     */
    public function applySliceGuards(Builder $query, \DateTimeImmutable $nowUtc): Builder;

    /**
     * MySQL 热行 → CH 冷行（JSONEachRow 兼容值：字符串或 null）。
     *
     * 统一附加公共列 id / batch_id / archived_at（R-003 审计列）。
     * 映射口径（R-004 / contracts/clickhouse-schema-contract.md「MySQL 侧 null → CH 侧值规范化」）：
     *   Decimal/时间戳原字符串（UTC）；可空数值/时间 null→JSON null；
     *   String 类文本列（rationale/raw_payload/error_message/exchange_event_id）null→''。
     */
    public function toChRow(Model $row, int $batchId, string $archivedAtUtc): array;

    /**
     * 规范化列序（R-006 哈希与抽样比对的等价域）：= DDL 业务列序（含 id，
     * 不含 batch_id/archived_at 审计列——审计列在重跑时会变，不参与等价判定）。
     *
     * @return list<string>
     */
    public function canonicalColumns(): array;
}
