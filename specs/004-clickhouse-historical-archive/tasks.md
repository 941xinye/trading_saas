---
description: "Task list for ClickHouse 历史数据归档实现"
---

# Tasks: ClickHouse 历史数据归档（P8）

**Input**: Design documents from `/specs/004-clickhouse-historical-archive/`（[plan.md](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/plan.md)、[spec.md](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/spec.md)、[research.md](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/research.md)、[data-model.md](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/data-model.md)、[contracts/](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/contracts/)、[quickstart.md](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/quickstart.md)）

**Prerequisites**: plan.md ✓、spec.md ✓（5 个 User Story，P1×3→P2×2）、research.md ✓（R-001~R-011 全 Resolved，候选待核项：lightweight delete GA 版本、cityHash64 vs MD5 降级，均带验证指针）、data-model.md ✓、contracts×3 ✓、quickstart ✓

**Tests**: 本 feature 显式要求测试（plan.md Testing 节 + constitution VI），测试任务按 user story 分组生成；CH 集成测试用可用性标志 skip（不阻塞 271 passed 基线）；**纯增量门槛**——P1-P5 代码与既有模型/config 零改动（FR-008 机制性隔离）。

**Organization**: 按 user story 分组（US1 归档管线 → US2 统一查询 → US3 租户隔离 → US4 故障降级 → US5 校验可观测）。Foundational 阶段承载全部 story 共用的类型策略/规范化/存储层；US3/US4 是 US2 的隔离与降级收尾深化，US5 是 US1 账目体系上的独立命令。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1/US2/US3/US4/US5)
- Include exact file paths in descriptions

## Path Conventions

- **Domain**: `app/Domain/Archive/`（Contracts / Dto / Types / Services / Stores，唯一新增 Domain 包）
- **Commands**: `app/Console/Commands/Archive{Schema,Run,Verify,Status}Command.php`
- **Config**: `config/archive.php`（新增）+ `.env.example`（ARCHIVE_CH_* 占位）
- **Migrations**: `database/migrations/`（archive_batches + archive_watermarks 两张账目表）
- **Tests**: `tests/Domain/Archive/`（含 `Stores/ClickHouseStoreIntegrationTest.php`）

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 配置、env 占位与账目表——全部 user story 的公共地基（research R-001/R-010/R-011）。

- [X] T001 新建 `config/archive.php` 完整结构：(1) `'ch'` 连接块——`'host' => env('ARCHIVE_CH_HOST', '127.0.0.1')`、`'port' => env('ARCHIVE_CH_PORT', 8123)`、`'database' => env('ARCHIVE_CH_DATABASE', 'trading_archive')`、`'user' => env('ARCHIVE_CH_USER', 'default')`、`'password' => env('ARCHIVE_CH_PASSWORD', '')`、`'timeout_ms' => env('ARCHIVE_CH_TIMEOUT_MS', 30000)`（R-001 键集）；(2) `'types'` 块四类型（kline_bar/signal/strategy_order/liquidation_event）各含 `'enabled' => env('ARCHIVE_{TYPE}_ENABLED', true)`、`'retention_days' => env('ARCHIVE_{TYPE}_RETENTION_DAYS', 90)`、`'batch_window_hours'`（kline_bar 24 / signal 24 / strategy_order 24 / liquidation_event 6，高频小窗口）、`'chunk_size' => 1000`、`'throttle_ms' => 200`（R-005）；(3) `'query_page_size' => 1000`、`'query_max_rows' => 50000`、`'verify_sample_rows' => 5`；(4) 注释标明最小值下限红线（contracts/archive-pipeline-contract.md §3 红线 4）：chunk_size ≥100、throttle_ms ≥50、query_max_rows ≤200000，命令层校验违反即退出码 2
- [X] T002 [P] 在 `.env.example` 增加 ARCHIVE_CH_* 六个占位变量（T001 键集，无真实凭据）
- [X] T003 新建 migrations `database/migrations/2026_10_19_000001_create_archive_batches_table.php` 与 `2026_10_19_000002_create_archive_watermarks_table.php`（字段与索引严格按 [data-model.md §2](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/data-model.md)）：batches——id 自增、data_type varchar(32)、time_from/time_to timestamp(0) not null、source_rows bigint unsigned not null、archived_rows bigint unsigned default 0、verify_status varchar(16) default 'pending'、applied_at timestamp(0) nullable、failed_reason text nullable、timestamps；唯一键 `uniq_batch_type_range(data_type, time_from, time_to)`、索引 `idx_batch_type_status(data_type, verify_status)`、`idx_batch_type_applied(data_type, applied_at)`；watermarks——data_type unique、copied_watermark/applied_watermark timestamp(0) default '1970-01-01 00:00:00'、timestamps

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: ArchiveDataType 枚举 + ArchiveType 策略契约与四实现（时间轴/CH 表/visibility/行映射）、RowNormalizer 规范化哈希、ArchiveStore 契约与 ClickHouseStore HTTP 直连实现 + archive:schema 命令。这是 US1（写路径）与 US2（读路径）的共同前置。

**⚠️ CRITICAL**: 本阶段完成前不得开始任何 user story。

- [X] T004 新建 `app/Domain/Archive/Contracts/ArchiveDataType.php`（enum：KlineBar='kline_bar'/Signal='signal'/StrategyOrder='strategy_order'/LiquidationEvent='liquidation_event'）与 `app/Domain/Archive/Contracts/ArchiveType.php` 接口：`dataType(): ArchiveDataType`、`visibility(): string`（'tenant'|'public'，R-008）、`chTable(): string`、`timeColumn(): string`（kline_bar=open_time、signal=triggered_at、strategy_order=created_at、liquidation_event=event_ts，R-005 纯时间片语义）、`hotQuery(): Eloquent Builder 工厂`（复用既有模型，TenantScope 继续生效）、`toChRow(Model $row, int $batchId): array`（列名=>字符串/JSON null 值映射，规范化口径按 [contracts/clickhouse-schema-contract.md](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/contracts/clickhouse-schema-contract.md) MySQL→CH 映射表：Decimal 原字符串、timestamp 'Y-m-d H:i:s' UTC、nullable 数值/时间→JSON null、String 类 null→''）、`canonicalColumns(): list<string>`（哈希规范化列序，与 toChRow 一致）、`hotCopyQuery(string $from, string $to): Eloquent Builder`（切片谓词 `time >= from AND time < to`，按 id 升序）
- [X] T005 新建四实现 `app/Domain/Archive/Types/KlineBarArchiveType.php`、`SignalArchiveType.php`、`StrategyOrderArchiveType.php`、`LiquidationEventArchiveType.php`（一文件一类，constitution VII）：逐列映射严格按 [data-model.md §3](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/data-model.md)（kline_bar 六 Decimal(20,8)+trades_count；signal 含 confidence Decimal(5,2)、raw_payload 原 JSON 串不解析；strategy_order 可空列 price/stop_price/avg_fill_price/leverage/placed_at/filled_at → Nullable 保真、时间轴 created_at；liquidation_event value_usd Decimal(20,2)、exchange_event_id null→''）；公共列 id/batch_id/archived_at 由 `toChRow` 统一附加；时间轴/visibility 声明按 T004 接口
- [X] T006 [P] 新建 `app/Domain/Archive/Stores/RowNormalizer.php`：`canonicalRow(ArchiveType $type, array $fields): string`（按 canonicalColumns 列序 `|` 分隔拼接，null→''，Decimal/timestamp 已是字符串逐位直传）与 `rowHash(string $canonical): string`（R-006 主方案 cityHash64 纯 PHP 实现 + 黄金向量断言；若实现期判定成本超预期，降级 MD5 hex 前 16 位转无符号——两侧同构语义不变，决策点登记 docs/pitfalls.md）；`hashSum` 累加用 bcmath（constitution II 禁浮点）
- [X] T007 新建 `app/Domain/Archive/Contracts/ArchiveStore.php` 契约与 `app/Domain/Archive/Stores/ClickHouseStore.php` 实现（R-002 零依赖直连）：`Http::asText()` `POST {scheme}://{host}:{port}/?query=...`，鉴权头 `X-ClickHouse-User`/`X-ClickHouse-Key`，超时 config timeout_ms；**所有 SELECT 恒带设置** `output_format_json_quote_decimals=1`、`output_format_json_quote_64bit_integers=1`（R-004 精度红线）；方法面：`createSchema(): array`（执行 [contracts/clickhouse-schema-contract.md](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/contracts/clickhouse-schema-contract.md) 四条 CREATE TABLE IF NOT EXISTS + CREATE DATABASE IF NOT EXISTS，返回已执行语句）、`insertRows(string $chTable, array $rows): void`（`INSERT INTO {db}.{table} FORMAT JSONEachRow`，body NDJSON，Decimal/timestamp 字符串、nullable JSON null）、`countByBatch(string $chTable, int $batchId): int`、`deleteByBatch(string $chTable, int $batchId): void`（lightweight delete `DELETE FROM {db}.{t} WHERE batch_id={id}`，候选待核 GA 版本在集成测试断言）、`fetchRows(string $chTable, array $filters, array $orderBy, int $limit, int $offset): array`（读路径原语，行以 列名=>字符串/ null 返回）、`hashSum(string $chTable, int $batchId, array $columns): string`（CH 侧 `sum(cityHash64(concat(...)))` 与 RowNormalizer 同构，或 MD5 降级对齐）；**全部 HTTP 异常（连接/超时/5xx）统一包装 `ArchiveStorageUnavailableException`**（新建 `app/Domain/Archive/Exceptions/ArchiveStorageUnavailableException.php`，携带原始 message，R-009）；新建 `app/Console/Commands/ArchiveSchemaCommand.php`（signature `archive:schema`，调 createSchema 输出逐表确认，CH 不可达非零退出）

- [X] T008 [P] 新建 `tests/Domain/Archive/RowNormalizerTest.php`（先 FAIL 后随 T006 转绿）：cityHash64 纯 PHP 实现黄金向量断言（官方测试向量，R-006 主方案）+ `canonicalRow` 边界 `#[DataProvider]`（null→''、Decimal 字符串逐位直传、timestamp 'Y-m-d H:i:s' UTC 格式、列序与 ArchiveType::canonicalColumns 一致）+ `hashSum` bcmath 累加无浮点（constitution II）；若实现期启用 MD5 降级方案则切换对应向量集（R-006 决策点，结论落 docs/pitfalls.md）
- [X] T009 新建 `tests/Domain/Archive/Stores/ClickHouseStoreIntegrationTest.php`：**可用性标志守卫**（CH 不可达 markTestSkipped，与既有 56 skipped 同模式，不阻塞 CI）；固定用例：(1) `createSchema` 幂等两跑（二次执行不报错）；(2) `insertRows`/`countByBatch` 记账一致；(3) **`deleteByBatch` 后 `count()==0` 立即可见**（R-003 lightweight delete 候选待核 GA 版本的验证指针）；(4) **round-trip 逐位精度断言**：MySQL 造行 → ArchiveType::toChRow → CH 写入 → fetchRows 读回 → canonicalRow 与主库侧逐位相等（R-004 验证指针 / constitution II，四表各一例含 Nullable 列）；(5) tearDown 按 batch_id 精确清理测试写入（try/catch 包住、先于 parent::tearDown，constitution VI）

**Checkpoint**: 类型策略/规范化哈希/CH 存储层/schema 命令 + 离线黄金向量与 CH 集成测试就位——US1 写路径与 US2 读路径可并行开始。

---

## Phase 3: User Story 1 - 冷数据归档，主库瘦身，业务零感知 (Priority: P1) 🎯 MVP

**Goal**: archive:run 完整管线（建批→copy 分块限速→verify 三级→--apply 事务删除+水位推进），dry-run 默认，幂等可续跑，热链路零感知。覆盖 spec FR-001/FR-002/FR-003/FR-004/FR-005/FR-012/FR-013 与 SC-005/SC-007。

**Independent Test**: quickstart.md §2+§3——造超保留期数据 → `php artisan archive:run kline_bar --max-batches=2`（dry-run：主库行数不变、CH 有数据、报告可删范围）→ `--apply` 复跑（主库超期行消失、账目对平）；或 `php artisan test --filter=ArchivePipelineTest`。

### Tests for User Story 1

- [X] T010 [P] [US1] 新建 `tests/Domain/Archive/ArchivePipelineTest.php`，写状态机与账目断言（fake ArchiveStore + 内存行集）：建批切片 `[from, now-retention)` 按窗口切分、`uniq_batch_type_range` 防重复建批（同切片复用未完成批次行）；copy 阶段先 `deleteByBatch` 清半途行再插（幂等守卫，R-003）、source_rows/archived_rows 记账、chunk 分块（1000 行/块）与 throttle 间隔生效；verify 三级全过 → verify_status=passed、任一级不过 → failed + failed_reason 记账；failed 批次重跑复位 pending；全程断言状态迁移 pending→copied→passed→applied（data-model.md 状态机）
- [X] T011 [P] [US1] 在 `tests/Domain/Archive/ArchivePipelineTest.php` 写安全红线断言（contracts/archive-pipeline-contract.md §3）：(1) verify_status != 'passed' 的批次 apply 阶段被拒（未过校验确认模式下同样拒删，FR-005）；(2) apply 事务内主库切片行数再核对 != archived_rows 即 abort 回滚（无半删）；(3) dry-run（默认）不执行任何主库删除、报告输出可删范围；(4) 删除与 applied_watermark 推进同事务（事务内抛异常水位不变）；(5) 切片上界恒 ≤ now-retention、kline_bars 另加 close_time 闭合保险（R-005）；(6) 命令参数越下限（chunk_size<100 / throttle_ms<50）退出码 2
- [X] T012 [P] [US1] 在 `tests/Domain/Archive/ArchivePipelineTest.php` 写热链路零感知断言（FR-003）：管线运行期间（fake 慢速 CH 写入）模拟主库并发写入新行（保留期内），断言新行不受影响且不落入任何批次切片（时间片谓词隔离）；tenant 类型 copy 读取显式 `withoutGlobalScope(TenantScope::class)` 绕过租户作用域读取全部行（系统级搬迁专用路径，不借道超管上下文语义），断言跨租户行全部搬出且不误伤保留期内其他租户行

### Implementation for User Story 1

- [X] T013 [US1] 实现 `app/Domain/Archive/Services/ArchivePipeline.php`（建批→copy→verify→apply 状态机，依赖 T004-T009）：建批（复用未完成批次/uniq 防重/安全上界）；copy（先 deleteByBatch 清半途 → tenant 类型主库读取显式 `Model::query()->withoutGlobalScope(TenantScope::class)`（系统级搬迁专用，不借道超管上下文语义）→ 按 id 升序 chunk 流式读 + `insertRows` 逐块写 + `usleep(throttle_ms)` → 记账 copied）；verify 三级（行数 `countByBatch` vs 主库 COUNT / 哈希和：主库侧 RowNormalizer 流式求和（bcmath）vs `hashSum` / 抽样 `verify_sample_rows` 行全字段规范化串逐位比对）；apply（仅 passed 批次：`DB::transaction { COUNT 再核对 → 分块限速 DELETE 切片行 → 推进 archive_watermarks.applied_watermark（只增）→ 批次 applied_at }`）；dry-run 语义（跳过 apply，报告可删范围）；异常 → 批次 failed + failed_reason，主库零改动（R-009）
- [X] T014 [US1] 新建 `app/Console/Commands/ArchiveRunCommand.php`（signature `archive:run {type?} {--apply} {--batch-window-hours=} {--chunk-size=} {--throttle-ms=} {--max-batches=} {--from=} {--to=}`，依赖 T013）：{type} 省略 = 四类型按 enabled 顺序执行、未启用类型报错退出码 2；参数覆盖 config（越下限拒绝，红线 4）；`--from/--to` UTC 解析（Y-m-d 或 Y-m-d H:i:s）、越界截断警告；`--max-batches` 首跑限速（FR-012）；退出码 0 成功 / 1 运行期失败 / 2 用法错误；批次报告输出格式按 contracts/archive-pipeline-contract.md §4（单批次行 + 汇总：N 批处理/M passed/K failed/可删范围）
- [X] T015 [US1] 冒烟验证（CH 可达环境）：按 quickstart.md §1→§2→§3 顺序执行——`archive:schema` 幂等两跑、dry-run 主库行数不变且 CH 行数=source_rows、`--apply` 后主库超期行 100% 移出（SC-007 可量化记录）、账目对平；CH 不可达时以集成测试替代并记录 skip（2026-10-19 开发机已按降级条款执行并记录于 quickstart §10，真 CH 冒烟待节点机回填）
- [ ] T016 [US1] 幂等续跑演练（quickstart.md §6）：copy 阶段中途 SIGKILL 进程（constitution IV 端到端验证范式）→ 重跑同命令 → 断言行数与一次跑完一致、CH 无重复行（countByBatch == source_rows）、账目对平；记录结果入 quickstart 验收对账

**Checkpoint**: US1 完整可测——归档管线 dry-run/apply/幂等/限速全通，主库瘦身可量化，热链路零回归。

---

## Phase 4: User Story 2 - 统一历史查询入口，调用方不感知冷热 (Priority: P1)

**Goal**: HistoricalQuery 服务层契约落地：applied 水位冷热路由 + 跨界归并去重 + 游标分页 + 上限显式报错。覆盖 spec FR-006/FR-011 与 SC-003。

**Independent Test**: quickstart.md §4——归档部分 K 线后查询跨越 applied_watermark 的整年范围：行数与归档前基线一致、(时间列, id) 有序、无重复；超上限请求抛 `ArchiveQueryLimitException`；或 `php artisan test --filter=HistoricalQueryServiceTest`。

### Tests for User Story 2

- [X] T017 [P] [US2] 新建 `tests/Domain/Archive/HistoricalQueryServiceTest.php`（fake ArchiveStore + 真实 Eloquent 热侧数据集），写路由与归并断言：`to <= applied_watermark` 纯冷（store 被调、热侧零查询）、`from >= applied_watermark` 纯热（**store 零调用**，R-007 纯热短路）、跨界两侧查 + (时间列, id) 归并升序 + 自然键 id 去重（in-flight 批次两侧同现保留一条）；servedFrom 返回 HOT/COLD/MERGED 正确
- [X] T018 [P] [US2] 在 `tests/Domain/Archive/HistoricalQueryServiceTest.php` 写分页与上限断言：游标翻页全量拉取不重不漏（页界边界行校验）、nextPageCursor=null 终止；pageSize 默认 1000、超 query_max_rows 抛 `ArchiveQueryLimitException` 不静默截断（FR-011）；非法游标（篡改/过期格式）抛同异常；参数校验（from >= to、kline_bar 以外类型传 exchangeCode/interval、KlineBar 以外传 interval）抛 `InvalidArgumentException`（contracts/historical-query-contract.md §3 过滤开放表）
- [X] T019 [P] [US2] 新建 `app/Domain/Archive/Dto/HistoricalQueryRequest.php` 与 `HistoricalQueryResult.php`（不可变 DTO，字段与规则按 [data-model.md §4](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/data-model.md)：dataType/from/to/symbol/exchangeCode/interval/pageSize/cursor；**无 tenantId 字段**（R-008 物理隔离）；Result：rows（列名=>字符串/null 字典，全结果 (时间列,id) 有序）+ nextPageCursor + servedFrom 枚举）

### Implementation for User Story 2

- [X] T020 [US2] 新建 `app/Domain/Archive/Services/HistoricalQueryService.php` 实现 HistoricalQuery 契约（依赖 T004-T007、T017-T019）：路由基准 `archive_watermarks.applied_watermark`（R-007 三段）；热侧经 `ArchiveType::hotQuery()` 复用既有模型（TenantScope 自动注入继续生效）、冷侧 `fetchRows`；归并 (时间列 ASC, id ASC) + id 去重；游标 = `(最后行时间列值, 最后行 id)` base64 不透明串编解码；每类型时间轴取自 ArchiveType::timeColumn()；新建 `app/Domain/Archive/Exceptions/ArchiveQueryLimitException.php`；在 `app/Providers/AppServiceProvider.php` 注册绑定 `HistoricalQuery::class => HistoricalQueryService`（**bind 非 singleton**，Octane 长驻禁 static 缓存）

**Checkpoint**: US2 完整可测——冷热合并查询与纯主库基线一致（SC-003），分页/上限/错误语义完备，调用方零感知数据所在层。

---

## Phase 5: User Story 3 - 租户隔离贯穿历史层 (Priority: P1)

**Goal**: 归档不成隔离旁路：tenant 类型（signal/strategy_order）构造层强制租户谓词，越权通道物理不存在；public 类型（kline_bar/liquidation_event）平台公共可查。覆盖 spec FR-007 与 SC-004。

**Independent Test**: quickstart.md §5——双租户历史 signals 归档后分别以 A/B 上下文查询，各只见己方行；无租户上下文调用抛异常；或 `php artisan test --filter=TenantIsolationTest`。

### Tests for User Story 3

- [X] T021 [P] [US3] 新建 `tests/Domain/Archive/TenantIsolationTest.php`：造双租户历史 signals（各自 tenant_id）→ 管线归档（T013 显式绕过 TenantScope 搬迁）→ 以租户 A 上下文 `HistoricalQuery::query` 断言仅返回 A 行（纯冷/跨界/纯热三段都要，SC-004 零泄露）；B 同理；无租户上下文（TenantContext::clear 后）调 tenant 类型抛 TenantContext 既有 RuntimeException；**结构性断言**：`HistoricalQueryRequest` 反射不存在 tenantId 公开属性/方法（R-008 越权通道不存在）；public 类型（kline_bar）在无租户上下文可正常查询

### Implementation for User Story 3

- [X] T022 [US3] 隔离强制落地核对与收尾（依赖 T020/T021）：`HistoricalQueryService` 对 tenant 类型构造查询前强制 `TenantContext::requireTenantId()` 注入 `tenant_id = ?`（热侧 TenantScope 自动注入 + 冷侧 `fetchRows` filters 同谓词，两层等价，R-008）；若 T020 已覆盖则补断言缺口——尤其纯热短路段同样带租户谓词（隔离与路由正交）；public 类型不附加租户条件

**Checkpoint**: US3 完整可测——双租户零泄露、无上下文拒绝、DTO 无越权字段，隔离与冷热路由正交成立。

---

## Phase 6: User Story 4 - 历史存储故障不伤热链路 (Priority: P2)

**Goal**: CH 故障时：热链路全部正常（机制性零依赖）、纯热查询不受影响、历史查询明确降级、归档失败不污染主库且可续跑。覆盖 spec FR-008/FR-010 与 SC-006。

**Independent Test**: quickstart.md §7——归档运行中断开 CH：archive:run 批次 failed 退出码 1、主库零污染、恢复后续跑账目对平；纯热查询正常、冷查询抛 `ArchiveStorageUnavailableException`。

### Tests for User Story 4

- [ ] T023 [P] [US4] 新建 `tests/Domain/Archive/Stores/DegradationTest.php`（或并入 ClickHouseStoreIntegrationTest 离线部分）：`Http::fake` 连接拒绝/超时/500 三形态 → ClickHouseStore 各方法统一抛 `ArchiveStorageUnavailableException` 且 message 携带原因（R-009）；`HistoricalQueryService` 原样上抛不改写不吞（fake store 抛 → 断言同实例异常冒泡）；纯热查询断言 store 零调用（US4 场景 2 机制性保证）
- [ ] T024 [US4] 失败记账路径收尾（依赖 T013/T023）：在 `tests/Domain/Archive/ArchivePipelineTest.php` 补 CH 断连场景断言——copy 中途 store 抛 `ArchiveStorageUnavailableException` → 当前批次 failed + failed_reason、主库账目无半途修改（无 applied_at、无水位推进）、命令退出码 1（US4 场景 1）；恢复（fake 恢复正常）重跑 → 先 deleteByBatch 清半途行再续跑、账目对平（SC-006）

**Checkpoint**: US4 完整可测——降级语义类型化、失败不污染、续跑幂等；热链路零依赖由 T028 import 闭包 gate 终检。

---

## Phase 7: User Story 5 - 归档可校验、进度可观测 (Priority: P2)

**Goal**: 独立复检命令（归档前后各跑一次校验）与状态查询命令（批次清单/水位/待归档量）。覆盖 spec FR-009 与 US5 两验收场景。

**Independent Test**: quickstart.md §3 复检 + `php artisan archive:status kline_bar`——输出批次清单（时间范围/行数/校验状态）与水位；人为制造不一致后复检定位到具体批次并拒删。

### Tests for User Story 5

- [ ] T025 [P] [US5] 新建 `tests/Domain/Archive/CommandsTest.php`（Laravel 命令测试，fake ArchiveStore + 已记账批次数据集）：`archive:verify`——passed 批次复检保持 passed、制造不一致（篡改 store 哈希和/行数）定位到具体批次降级 failed + failed_reason、failed 批次不可进入 apply 候选（US5 场景 2）；`archive:status`——输出 retention/enabled/copied_watermark/applied_watermark/待归档量估算（COUNT 谓词 `time >= copied_watermark AND time < now-retention`）/最近 N 批次清单含 applied_at（FR-009）

### Implementation for User Story 5

- [ ] T026 [US5] 新建 `app/Console/Commands/ArchiveVerifyCommand.php`（signature `archive:verify {type?} {--batch=}`，依赖 T013 verify 能力）：{batch} 指定批次 id、省略 = 该类型全部 copied/passed 未 applied 批次；复检三级校验、failed 立即降级记账；输出批次清单表（时间范围/行数/校验状态/明细）按 contracts/archive-pipeline-contract.md §4；退出码 0/1/2 同 archive:run
- [ ] T027 [US5] 新建 `app/Console/Commands/ArchiveStatusCommand.php`（signature `archive:status {type?}`）：{type} 省略 = 四类型逐行输出；每行 retention_days/enabled/双水位/待归档量估算/最近 5 批次（id、时间范围、source_rows、verify_status、applied_at）；未启用类型标注 disabled

**Checkpoint**: US5 完整可测——复检定位不一致并阻断删除、进度水位随时可查，US1 账目体系闭环。

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: 增量纯度终检、全量基线、端到端验收、文档同步。

- [ ] T028 [P] 执行导入闭包 gate（FR-008 终检）：全库 Grep `use App\Domain\Archive` ——仅允许出现在 `app/Domain/Archive/**`、`app/Console/Commands/Archive*Command.php`、`app/Providers/AppServiceProvider.php`（绑定注册）；P1-P5 域（Exchange/Strategy/Signal/Risk/Billing/Notification/Liquidation/Tenancy）、Jobs、Events、market:feed 链路零命中；结果记录入 tasks 或 PR 描述
- [ ] T029 [P] 文档同步：`README.md` 技术栈表 ClickHouse 行改为「已引入（P8 历史归档）」并补目录结构 `app/Domain/Archive/`；`docs/architecture.md` 新增 P8 归档节（双进程模型不变、归档为独立 artisan 旁路）；新建 `docs/adr/0006-clickhouse-cold-tier.md` 记录四项选型决策（R-001 自托管单节点 / R-003 MergeTree+batch_id / R-002 零依赖 HTTP / R-008 隔离下沉）与拒绝方案
- [ ] T030 全量测试基线核对：`composer test` ——新增 `tests/Domain/Archive/` 全绿（CH 可达）或集成部分 skip（可用性标志，与既有 56 skipped 同模式）；既有基线 271 passed 起步只增不减、P1-P5 既有测试零改动全绿（纯增量验证点）；两候选待核项结论落档（lightweight delete 即时可见性断言结果、cityHash64 vs MD5 决策）入 `docs/pitfalls.md`
- [ ] T031 端到端验收：按 quickstart.md §1~§8 全程演练（节点机 CH 环境），对照验收对账表逐项记录 SC-001~SC-007 结果；SC-001 实测耗时校准（CH 侧单页 p95 与全年分钟级游标全量拉取），超标时在汇总中列明优化候选（列裁剪/预聚合）留 converge 处理

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖，立即开始；T003 账目表是 T013/T017 前置
- **Foundational (Phase 2)**: 依赖 Phase 1（T007 依赖 T001 config）；**BLOCKS 全部 user story**
- **US1 (Phase 3)**: 依赖 Phase 2 —— 归档管线是 MVP
- **US2 (Phase 4)**: 依赖 Phase 2（读路径与 US1 写路径共享存储层，可并行开发，但联调顺序建议 US1 先行——跨界归并测试需要真实归档数据）
- **US3 (Phase 5)**: 依赖 US2（隔离强制点在查询服务）；管线归档步骤依赖 US1
- **US4 (Phase 6)**: 依赖 US1（失败记账）+ US2（查询降级）；可与 US3 并行
- **US5 (Phase 7)**: 依赖 US1（账目体系与 verify 能力复用）；可与 US3/US4 并行
- **Polish (Phase 8)**: 依赖全部 user story 完成

### Parallel Opportunities

- Phase 1: T002 与 T001/T003 并行
- Phase 2: T004 → （T005、T006）可并行；T007 依赖 T004/T006；T008 依赖 T006、T009 依赖 T005+T007
- US1: T010/T011/T012 三组测试并行编写（先 FAIL 再实现 T013/T014）
- US2: T017/T018/T019 并行（DTO 与测试先行）；US2 与 US1 在 Foundational 完成后可双线并行
- US3/US4/US5 各自测试任务 [P] 先行；三个 story 之间无文件冲突可并行收尾

---

## Implementation Strategy

### MVP First (US1 Only)

1. Phase 1 Setup + Phase 2 Foundational → 地基就绪
2. Phase 3 US1 → dry-run/apply/幂等演练通过（quickstart §1-§3、§6）
3. **STOP and VALIDATE**: 主库超期行 100% 移出、热链路零回归、账目对平
4. 此时系统已具备"归档"独立价值（SC-007 达成），查询能力随后叠加

### Incremental Delivery

1. Setup + Foundational → 地基
2. US1 → 归档闭环（MVP！）
3. US2 → 统一查询（SC-003/SC-001）
4. US3 → 隔离零泄露（SC-004）——P1 三连完成后核心价值闭环
5. US4 → 故障演练（SC-006）
6. US5 → 可观测收口（FR-009）
7. Polish → gate/基线/文档/端到端验收

### Notes

- CH 不可达环境（本机）：集成测试自动 skip 不阻塞离线断言全绿；冒烟/演练任务（T015/T016/T024 演练部分/T031）在节点机 CH 环境执行并回填记录
- 两候选待核项的验证步骤已内嵌：T009（lightweight delete GA）、T006+T008（cityHash64 vs MD5 降级决策点）
- 每任务或逻辑组完成后 commit（用户惯例：不主动 push）
