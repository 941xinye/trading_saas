# Quickstart: ClickHouse 历史归档验证指南

**Feature**: 004-clickhouse-historical-archive | **Date**: 2026-10-18
**目的**: 端到端验证 spec 五个用户故事；命令与断言锚定 [contracts/](contracts/) 与 [data-model.md](data-model.md)，实现细节见 tasks 阶段产物。
**环境注**：本机（Windows/ServBay）无 Docker，CH 单节点在节点机以 Docker Compose 起（与 BTCPay PoC 同执行模式）；集成测试在本机跑时 CH 不可达会自动 skip（不阻塞 CI 基线）。

---

## 0. 前置

1. **ClickHouse 单节点**（research R-001）：节点机 `docker compose up -d clickhouse`（镜像 `clickhouse/clickhouse-server`，暴露 8123）；确认 `curl http://{CH_HOST}:8123/ping` 返回 `Ok.`。
2. **env 配置**（research R-001 键集）：`ARCHIVE_CH_HOST` / `ARCHIVE_CH_PORT=8123` / `ARCHIVE_CH_DATABASE=trading_archive` / `ARCHIVE_CH_USER` / `ARCHIVE_CH_PASSWORD`；`ARCHIVE_CH_DATABASE` 库需预建（`CREATE DATABASE IF NOT EXISTS trading_archive`，可由 archive:schema 顺带执行）。
3. **主库存量**：`php artisan migrate` 建账目表（archive_batches / archive_watermarks）。
4. 改 .env 后重启相关 worker（constitution 工作流规则）。

## 1. Schema 建立（US1 前置）

```text
php artisan archive:schema
```

**预期**：输出 4 张表创建确认；重复执行输出"已存在"且不报错（幂等）；`SHOW TABLES` 于 CH 侧核对表结构与 contracts/clickhouse-schema-contract.md 一致。

## 2. 造数与首轮归档 dry-run（US1 / FR-005 / FR-012）

造一批超过保留期（默认 90 天）的 kline_bars 行 + 持续新写入（可复用既有聚合链路或测试工厂）：

```text
php artisan archive:run kline_bar --max-batches=2 --throttle-ms=200
```

**预期**（dry-run，无 `--apply`）：
- 输出批次报告：每批 source_rows == archived_rows、三级校验 PASSED、`action: copied (dry-run)`；
- **主库行数不变**（只报「可删范围」不删除）；
- 归档期间既有热链路（market:feed / 下单 / 信号）全部正常——US1 Independent Test 的"零失败"断言；
- `archive:status kline_bar` 显示 copied_watermark 已推进、applied_watermark 仍为 0、待归档量随批减少。

## 3. 校验复检与显式删除（FR-005 / US5）

```text
php artisan archive:verify kline_bar            # 独立复检全部 copied/passed 批次
php artisan archive:run kline_bar --apply       # 二次确认：只删 verify_status=passed 批次
```

**预期**：
- verify 输出批次清单（时间范围/行数/校验状态），复检与首轮结论一致；
- `--apply` 后：主库超期行消失、行数账目对平（删除事务内再核对，FR-005 红线 2）、applied_watermark 推进至已删批次上界；
- 人为制造不一致（例如 delete 一行 CH 内已搬数据）→ `archive:verify` 定位到具体批次报 FAILED → `--apply` 拒删该批（US5 场景 2 断言）；
- **未过校验批次在 `--apply` 下同样拒删**（FR-005 显式断言）。

## 4. 冷热合并查询一致性（US2 / SC-003）

归档部分 K 线后，通过统一入口查询跨越 applied_watermark 的整段范围（tinker 或集成测试驱动 `HistoricalQuery::query`）：

**预期**：
- 跨界查询（热段+冷段）行数与归档前基线完全一致、`(时间列, id)` 有序、无重复（SC-003；in-flight 去重路径用"已 copy 未 apply"窗口期查询断言）；
- 纯热查询（`from >= applied_watermark`）与纯冷查询（`to <= applied_watermark`）各自正确；
- 超上限请求（pageSize > `archive.query_max_rows`）抛 `ArchiveQueryLimitException` 而非静默截断（FR-011）；
- 全字段字符串形态：Decimal/时间回读为字符串，逐位等于主库原值（constitution II round-trip 断言）。

## 5. 租户隔离（US3 / SC-004）

双租户各造历史 signals（含各自 tenant_id）→ 归档 → 分别以租户 A / B 上下文查询：

**预期**：
- A 只见 A 行，B 只见 B 行；跨租户不可见（SC-004 零泄露）；
- 无租户上下文（CLI/平台超管）调 tenant 类型 → 抛 TenantContext 既有异常；
- `HistoricalQueryRequest` 无 tenantId 字段（越权通道不存在——US3 场景 2"强制覆盖"的物理实现形态）；
- kline/liquidation 公共类型任何上下文可查，与租户无关。

## 6. 幂等与断点续跑（FR-004 / SC-005）

copy 阶段中途 kill 进程（SIGKILL，constitution IV 验证范式）→ 重跑同命令：

**预期**：重跑先清 batch_id 半途行再重插；完成后行数与一次跑完完全一致（账目对平）；CH 无重复行（`count() == source_rows`）；多跑一次 `--apply` 前置校验结果不变（重复归档幂等）。

## 7. 历史层故障演练（US4 / SC-006）

归档运行中人工断开 CH（`docker compose stop clickhouse`）：

**预期**：
- archive:run 当前批次标 failed、退出码 1、报因清晰；主库数据零污染（无半删半改）；
- 热链路全部操作正常（下单/信号/调度/计费）——P1-P5 代码无任何 Archive import（机制性隔离，analyze gate 核对）；
- 纯热历史查询正常返回；跨界/纯冷查询抛 `ArchiveStorageUnavailableException`（明确降级，非空结果非连锁失败）；
- 恢复 CH 后重跑归档 → failed 批次续跑完成、账目对平（SC-006）。

## 8. 自动化测试基线

```text
composer test
```

**预期**：
- 新增 `tests/Domain/Archive/` 全绿：管线状态机 / 路由归并 / 行哈希 / 租户隔离（离线断言 + fake ArchiveStore，`#[DataProvider]`）；
- `ClickHouseStoreIntegrationTest` 在 CH 可达时全绿、不可达时 skip（可用性标志模式，与既有 56 skipped 同惯例）；
- 既有基线不回归：271 passed 起步只增不减；P1-P5 既有测试零改动全绿（纯增量验证点）。

## 9. 验收对账表

| 验证步骤 | 对应验收 |
|---|---|
| §1 schema | FR-002 存储就绪 |
| §2 dry-run + 零失败 | US1 / FR-003 / FR-012 / FR-005(dry-run 默认) |
| §3 verify + apply | FR-005 / US5 / SC-007（主库行数下降可量化） |
| §4 合并查询 | US2 / FR-006 / FR-011 / SC-003 / SC-001（耗时记录） |
| §5 隔离 | US3 / FR-007 / SC-004 |
| §6 幂等续跑 | FR-004 / SC-005 |
| §7 故障演练 | US4 / FR-008 / FR-010 / SC-006 |
| §8 测试基线 | constitution VI / 纯增量 |
