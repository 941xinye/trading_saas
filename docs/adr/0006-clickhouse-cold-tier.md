# ADR-0006: ClickHouse 历史归档冷层 — 自托管单节点 + MergeTree batch_id + 零依赖 HTTP + 隔离下沉

- **Status**: Accepted
- **Date**: 2026-10-19
- **Spec**: specs/004-clickhouse-historical-archive（P8）

## Context

kline_bars / signals / strategy_orders / liquidation_events 四类时序数据持续增长，MySQL 主库行体积膨胀将拖垮热查询与备份窗口。需要历史冷层承接超保留期数据，并为 P10 回测、P7 回测预览提供统一历史查询（HistoricalQuery）。

约束：constitution II（bcmath 字符串精度）/ III（协程隔离）/ VI（测试隔离）；租户数据跨层不得泄露；归档窗口内业务零感知；不引入重运维组件。

## Decision

### R-001 自托管单节点 ClickHouse

单节点自托管 CH（HTTP 8123），不上集群、不引入 Kafka/向量同步中间件。归档是后台旁路任务，单点故障只影响冷层可用性，热链路（MySQL）零依赖——CH 断开时纯热查询照常（US4 场景 2 由纯热短路机制性保证）。

**拒绝**：① ClickHouse Cloud 托管——成本与数据出境合规风险；② 集群/副本——本期数据量与可用性要求不需要，运维复杂度不匹配；③ 继续使用 MySQL 分区表充当冷层——分钟级 K 线全年 52 万行/标的×多标的，行存 OLTP 引擎做范围扫描与聚合成本远超列存。

### R-003 MergeTree + batch_id 列

四张 CH 表均为 MergeTree 家族（按时间列 ORDER BY），每行带 `batch_id`（UInt64 字符串）与 `archived_at` 审计列。批次级幂等锚：copy 前 `DELETE FROM ... WHERE batch_id = ?`（lightweight delete）清半途行再插入；verify 与抽样按 batch_id 过滤。

**拒绝**：① ReplacingMergeTree 去重——合并时机不可控，verify 读时可能双算；② 不设 batch_id 直接按时间窗对账——无法区分半途写入与完整批次，幂等无法落地。

### R-002 零依赖 HTTP 直连

不引入任何 ClickHouse PHP 客户端库。`ClickHouseStore` 单类内用 Laravel `Http` facade 直连：DDL/SELECT 走 `POST /?query=... FORMAT JSON`，批量写走 JSONEachRow。HTTP 异常统一包装 `ArchiveStorageUnavailableException`（R-009），`ArchiveStore` 契约对上屏蔽、测试可 fake。

**拒绝**：① smi2/phpclickhouse 等客户端库——依赖老化、协议面超出需求（只需 HTTP 三操作）；② 引入 ODBC/JDBC 桥——部署复杂化。

### R-008 租户隔离下沉到查询层强制

`HistoricalQueryRequest` **无 tenantId 字段**（越权通道物理不存在）；tenant 类型查询前强制 `TenantContext::requireTenantId()`，热侧由 TenantScope 自动注入谓词、冷侧 fetchRows filters 带同谓词，两层等价。归档 copy 侧反向操作：显式 `withoutGlobalScope(TenantScope::class)` 系统级搬迁全租户数据。

**拒绝**：① 查询参数携带 tenant_id——调用方可伪造，越权通道长存；② 归档按租户逐轮跑——窗口碎片化、账目复杂化，且归档是系统级运维行为本就不该走租户上下文。

## Consequences

- 冷热路由以 `applied_watermark` 为唯一基准（R-007），跨界归并自然键 id 去重覆盖 in-flight 批次两侧同现。
- 行哈希选 MD5 前 16 hex → 无符号十进制累加 mod 2^64（PHP bcmath 与 CH 同构，见 pitfalls #31）。
- lightweight delete 即时可见性为已知待核项，集成测试断言就绪待节点机回填（见 pitfalls #32）。
- P1-P5 零 import 闭包（FR-008）由 T028 gate 终检锁定，后续 analyze/converge 阶段可复跑同命令回归。
