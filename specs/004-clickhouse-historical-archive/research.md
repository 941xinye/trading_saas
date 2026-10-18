# Research: ClickHouse 历史归档技术决策

**Feature**: 004-clickhouse-historical-archive | **Date**: 2026-10-18
**Scope**: 解决 plan Technical Context 全部未知点；镜像 001-003 research.md 决策格式（Decision / Rationale / Alternatives）。
**来源**: clarify 三决定（手动触发 / 仅内部服务层契约 / 默认 dry-run + --apply）+ 四表 migrations 实测列结构 + constitution 原则（II 精度 / IV 幂等 / VI 测试隔离）。
**证据分级**: 「已核实」= 代码库 migrations/既有类直读或两个独立来源一致；「候选待核」= 单一来源或版本相关行为，实现期实测佐证。

---

## R-001: 部署形态——自托管单节点起步

**Decision**: ClickHouse 单节点自托管起步（节点机 Docker Compose 起 `clickhouse/clickhouse-server` 单容器，HTTP 端口 8123 / native 9000 仅容器内用）。平台侧只依赖 HTTP 接口。连接配置全 env 化：`ARCHIVE_CH_HOST` / `ARCHIVE_CH_PORT`(默认 8123) / `ARCHIVE_CH_DATABASE` / `ARCHIVE_CH_USER` / `ARCHIVE_CH_PASSWORD` / `ARCHIVE_CH_TIMEOUT_MS`。云托管（ClickHouse Cloud / Altinity）与集群化留后续增强，本期零设计占用。

**Rationale**: 数据规模（四类时序表，P8 立项时主库存量在千万行级）单节点绰绰有余；spec 已约束"历史层是旁路依赖"（FR-008），单点故障面已通过降级语义兜住，不需要为可用性上集群；自托管避免本期引入云服务依赖与计费面（P9 计费域之外的新外部账单）。

**Alternatives considered**: 云托管起步（拒绝——引入外部依赖与成本，当前量级用不到）；三副本集群（拒绝——过度设计，constitution 复杂度须有正当理由）。

**验证指针**: 部署步骤落 quickstart.md；本机无 Docker，实际起服务在节点机执行（与 BTCPay PoC 同模式）。

---

## R-002: PHP 客户端选型——零新依赖，Laravel Http facade 直连 HTTP 接口

**Decision**: 不引入任何 ClickHouse PHP 客户端库。在 `app/Domain/Archive/Stores/ClickHouseStore.php` 内用 Laravel `Http` facade 直连 ClickHouse HTTP 接口：DDL/SELECT 走 `POST /?query=...`（读结果带 `FORMAT JSON` + 设置 `output_format_json_quote_decimals=1`），批量写走 `POST /?query=INSERT INTO t FORMAT JSONEachRow` + NDJSON body。底层 HTTP 细节收敛在 `ClickHouseStore` 单类内，`ArchiveStore` 契约（R-009）对上屏蔽。

**Rationale**:
1. **精度主控权（constitution II 的决定性理由）**：CH JSON 输出默认把 Decimal 输出为 JSON number，JS/PHP 解码超过 2^53 会丢精度——必须逐查询带 `output_format_json_quote_decimals=1` 让 Decimal 以字符串返回。自持客户端把该设置内化为写死的查询构造，第三方客户端按行配置反而绕远。
2. 协议极简：本 feature 只用 insert/count/SELECT/DELETE 四种语句形态，第三方客户端（smi2/phpClickHouse、官方 clickhouse/clickhouse-php-client）的连接池/游标抽象用不上。
3. smi2/phpClickHouse 维护活跃度弱、官方 PHP 客户端较新（成熟度风险）；zero-dep 与 composer 现状（无任何 DB 客户端第三方库）一致。
4. `Http` facade 天然可 `Http::fake()` 离线断言（constitution VI 测试隔离）。

**协程注意（constitution III）**: `Http` facade = Guzzle/curl 阻塞调用。archive:run 是独立 artisan 进程（无协程），阻塞无影响；HistoricalQuery 若未来在 Octane HTTP 进程内调用，单次阻塞 <100ms（Performance Goals）与既有 exchange REST 调用同模式可接受——首期消费端在服务层/命令层，不进 market:feed 行情协程进程（constitution III 慢调用隔离原则的既有边界）。

**Alternatives considered**: smi2/phpClickHouse（拒绝——维护弱 + 精度设置非一等公民）；官方 clickhouse/clickhouse-php-client（拒绝——新增依赖收益为负，见上）；TCP native 协议（拒绝——需实现 CH 私有二进制协议，复杂度不正当）。

---

## R-003: CH 表引擎与幂等机制——MergeTree + batch_id 列 + 重跑先删后插

**Decision**: 四张 CH 表均为 **MergeTree**（非 Replicated、非 Replacing），统一附加审计列 `batch_id UInt64`（= MySQL archive_batches.id）与 `archived_at DateTime('UTC')`。PARTITION BY `toYYYYMM(时间列)`，ORDER BY 按各表查询主轴（见 data-model.md）。幂等机制：**批次重跑先执行 lightweight delete `DELETE FROM {table} WHERE batch_id = {id}`（CH 22.8+ 原生语法）清掉该批半途行，再重新插入**；查询路径零 FINAL、零去重依赖——正确性在写入侧即时成立。

**Rationale**: FR-004 幂等续跑的最小机制：批次是搬迁原子单元（spec Key Entities），batch_id 把"批次"具象为 CH 内可精确删除的行集合；崩溃在 insert 中途 → 重跑删干净重来；崩溃在 verify → 批次 verify_status 未过，--apply 拒删（FR-005），重跑同批。MergeTree 裸表查询最快、语义最简单。lightweight delete 是异步 mutation 但**立即对后续查询可见**（rows marked deleted），满足"删完再插"的顺序正确性。

**Alternatives considered**: ReplacingMergeTree + FINAL（拒绝——去重靠后台 merge 异 eventual，查询必须 FINAL 才正确，性能与心智负担；且与"先删后插"叠加属双保险冗余）；`insert_deduplicate=1`（拒绝——仅 Replicated* 引擎生效，单机 MergeTree 不适用）；ALTER TABLE DELETE mutation 按时间范围删（拒绝——mutation 全分区重写太重，batch_id 精确删除更轻）。

**验证指针**: lightweight delete 的即时可见性实现期用集成测试断言（删后 count=0 立即成立）；`allow_experimental_lightweight_delete` 在 23.x+ 已 GA 默认开，部署版本若较旧需在 DDL 前确认（候选待核）。

---

## R-004: 数据类型映射——Decimal 逐列镜像 + 全程字符串语义

**Decision**（列级映射，镜像 migrations 实测结构）：

| MySQL | ClickHouse | 读写形态 |
|---|---|---|
| `decimal(20,8)`（价格/数量/量） | `Decimal(20,8)` | JSONEachRow 写入传字符串；读取带 `output_format_json_quote_decimals=1` 返回字符串 |
| `decimal(5,2)`（signals.confidence） | `Decimal(5,2)` | 同上 |
| `decimal(20,2)`（liquidation_events.value_usd） | `Decimal(20,2)` | 同上 |
| `timestamp` / `dateTime`（秒精度） | `DateTime('UTC')` | 字符串 `'YYYY-MM-DD HH:MM:SS'`（UTC）写入读取 |
| `id` 自增 | `UInt64`（保留原值） | 自然键组成部，保证冷热行可对账 |
| `enum` | `LowCardinality(String)` 或 `String` | 原字符串值存（direction/side/status/type/source） |
| `json`（signals.raw_payload） | `String` | 原文 JSON 串存，不解析不重排 |
| `text`（rationale/error_message） | `String`，NULL → `''` | 空串语义归一，回读侧 `''` 视为 null |
| `boolean`（reduce_only） | `UInt8` | 0/1 |
| `string`（symbol 等） | `LowCardinality(String)`（低基数列）/ `String` | 原值 |

**Rationale**: 逐列同精度镜像使校验哈希（R-006）在两侧对同一规范化字符串计算，杜绝隐式转换；`output_format_json_quote_decimals=1` 是 constitution II「归档与回读全程不得引入浮点精度损失」的落地点；id 保留使 (自然键 + id) 成为跨层对账键与去重键。

**Alternatives considered**: Decimal(38,18) 放大精度（拒绝——两侧精度不一致会让哈希比对需要额外规范化，同精度镜像最简）；DateTime64(3)（拒绝——MySQL 列就是秒精度，升精度制造假数据）。

**验证指针**: 实现期对每张表做一轮 round-trip 断言（MySQL 行 → CH 写入 → 读回 → 全字段字符串逐位相等），作为集成测试固定用例。

---

## R-005: 批次切片、限速与"活数据"安全边界

**Decision**:
- **切片键**：每类数据固定一个时间轴（kline_bars=`open_time`、signals=`triggered_at`、strategy_orders=`created_at`、liquidation_events=`event_ts`），批次 = `[batch_from, batch_to)` 左闭右开时间片，窗口默认 1 天（config `archive.types.{type}.batch_window_hours` 可按类型覆盖；liquidation_events 高频可调小）。
- **安全上界**：可归档上界 = `now(UTC) - retention_days`。kline_bars 追加闭合保险：切片不含 `close_time >= now - 5min` 的行（FR「活数据」edge case；retention 90d ≫ 任意 interval，正常切片天然全闭合，保险仅防御 retention 被配小）。
- **分块与限速**：批次内按 id 升序 chunk（默认 1000 行/块）流式读主库 → 逐块 JSONEachRow 写 CH → 块间 `usleep(throttle_ms)`（默认 200ms）。命令行 `--batch-window-hours` / `--chunk-size` / `--throttle-ms` / `--max-batches` 可覆盖，首跑存量大批量用 `--max-batches` 控制（FR-012）。
- **纯时间片语义**：批次不做 status 过滤（strategy_orders 90 天前仍 pending 的孤儿单一并归档——它们不参与任何恢复路径，strategy_instances 恢复依赖 Redis 状态 + position_snapshot，不依赖 strategy_orders 行）。时间片内行均为不可变历史（四表行只在近期被 UPDATE，超保留期切片内行不可变），时间片删除安全。

**Rationale**: 纯时间片让 copy/verify/delete 三步用**同一个谓词**（`time >= from AND time < to`），行数账目（source_rows == archived_rows）才有单一含义；任何 status 过滤都会让三步谓词分叉、账目失配。

**Alternatives considered**: 按 id 区间切片（拒绝——四表 id 与时间不同序，跨 id 切片的时间语义混乱）；strategy_orders 剔除 pending（拒绝——谓词分叉破坏账目单一性，见上）。

---

## R-006: 校验机制——行数 + 可交换行哈希和 + 抽样全字段比对

**Decision**（FR-005「先校验后删除」的 verify 具体化，三级）：
1. **行数**：MySQL `COUNT(*)`（切片谓词）vs CH `SELECT count() WHERE batch_id = {id}`。
2. **行哈希和**：两侧对每行计算规范化哈希后**求和**（可交换，规避两侧行序差异）。MySQL 侧 PHP 流式分块算（每行：字段按契约列序拼接 `|` 分隔、Decimal/timestamp 已是字符串、null→`''`，`cityHash64` 64bit 无符号和，bcmath 累加）；CH 侧 `SELECT sum(cityHash64(concat(...)))` 同列序同分隔。和相等 ⇒ 两侧多重集逐行相等（碰撞概率可忽略，作为快速全量等价判定）。
3. **抽样全字段比对**：切片首行、尾行、随机 N 行（默认 5）回读 CH，与主库行全字段字符串逐位比对（round-trip 精度断言）。

三级全过 ⇒ 批次 `verify_status = passed`，才允许进入 --apply 删除候选。

**Rationale**: 行数可被"少一行多一行抵消"类错误骗过？不会——配合哈希和，任何行内容差异都会改变和；抽样比对兜住哈希函数本身实现 bug（constitution VI 完整路径精神）。cityHash64 是 CH 原生函数，PHP 侧需实现同算法——**候选待核**：PHP 无内置 cityHash，实现期以纯 PHP 实现 cityHash64（公开算法，约 60 行）并做黄金向量断言；若实现成本超预期，降级方案为两侧都用 `md5(行规范化串)` 的十六进制前 16 位转无符号整数求和（MD5 PHP 原生、CH `MD5()` 原生，零自研算法）。

**Alternatives considered**: 逐行拉回主库侧全量比对（拒绝——大批次 IO 翻倍，违背 FR-012 限速初衷）；只做行数校验（拒绝——FR-005 明确要求"行数 + 抽样内容一致"）。

**验证指针**: cityHash64 黄金向量（官方测试向量）单测；降级方案切换点在实现期由 tasks 决策，契约不变（校验三级语义不变）。

---

## R-007: HistoricalQuery 冷热路由与边界合并

**Decision**: 路由基准是 **applied 水位**（`archive_watermarks.applied_watermark`，= 最大已删主库批次的 batch_to）。查询 `[from, to)`：
- `to <= applied_watermark` → 纯冷（CH）；
- `from >= applied_watermark` → 纯热（MySQL 主库）；
- 跨界 → 冷热各查 + 按 `(时间列, id)` 排序归并 + **自然键去重**（归档已写 CH 但主库未删的 in-flight 批次行会在两侧同时出现，`id` 相同保留一条——内容逐位一致，取哪侧无差异）。

排序统一 `(时间列 ASC, id ASC)`；分页游标 = `(最后行时间列值, 最后行 id)` 编码为不透明 base64 串。行上限：单页默认 1000，硬上限 config `archive.query_max_rows`（默认 50000），请求超限抛类型化异常明确报错不静默截断（FR-011）。

**Rationale**: 主库删除与水位推进在同一事务（R-010）内原子完成 ⇒ applied 水位左侧的数据**必然只在 CH**、右侧**必然只在主库**（in-flight 批次例外，被去重规则覆盖）——路由正确性归结为单点事实。游标分页让 SC-001 的全年分钟级全量拉取成为连续廉价操作。

**Alternatives considered**: 双写期主库留影子（拒绝——spec Assumptions 明确不留影子副本）；按 copied 水位路由（拒绝——copied 但未 applied 的行两侧都在，路由歧义）；最终一致性延迟合并（拒绝——引入不可测正确性窗口）。

---

## R-008: 租户隔离下沉——查询构造层强制，DTO 不暴露租户字段

**Decision**: 数据类型在 `ArchiveType` 策略上声明 `visibility: tenant|public`（signals/strategy_orders=tenant，kline_bars/liquidation_events=public，镜像四表 migrations 实测：前两者有 tenant_id 列且挂 BelongsToTenant，后两者无租户列）。
- **tenant 类型**：HistoricalQueryService 查询构造时强制 `TenantContext::requireTenantId()` 注入 `WHERE tenant_id = ?`（冷侧 CH 查询同谓词）；`HistoricalQueryRequest` DTO **不设 tenant_id 公开字段**——调用方无法指定他人租户，隔离在构造层物理不可绕过（spec US3 场景 2「过滤条件被强制覆盖为自身租户」的实现形态即：请求里根本没有该字段）。平台侧需要跨租户读的场合（如未来 P10 回测平台数据）走 `TenantContext::runWithoutTenant()` 显式提权（既有类文档已标注"禁止跨租户查询业务数据"，本期历史查询不做平台全域模式）。
- **public 类型**：无租户维度，任何上下文可查；DTO 亦无租户字段，语义天然一致。

**Rationale**: 归档不能成为行级隔离旁路（US3）的最强实现 = 越权通道不存在，而非"校验并拒绝"；复用既有 TenantContext 契约，BelongsToTenant/TenantScope 的 Eloquent 自动注入在热侧查询继续生效，冷侧由 ArchiveType 强制同谓词，两层等价。

**Alternatives considered**: DTO 带 tenant_id + 运行时校验拒绝不匹配（拒绝——"校验并拒绝"留存在 API 面，构造层不可写更彻底，也正对应 spec 验收语句的两个分支）；冷侧绕过 TenantScope 直查（拒绝——租户数据热侧读取保持 TenantScope 自动注入，不另起炉灶）。

---

## R-009: 旁路故障隔离——类型化降级异常 + 账目失败旁路

**Decision**:
- `ClickHouseStore` 内所有 HTTP 异常（连接拒绝/超时/5xx）统一包装为 `ArchiveStorageUnavailableException`（Domain 异常，带原始 message）。HistoricalQueryService **不吞不改写**——原样上抛，由调用方决定降级展示（FR-008「明确的降级错误」；首期消费端是服务层调用方，HTTP 错误形态留给真实消费端出现时定义）。
- archive:run 遇 CH 不可用：当前批次标 `failed`（failed_reason 记录），已插入该批的 CH 行按 batch_id 在下次续跑时先删后插（R-003），主库零改动（删除只发生在 applied 阶段）⇒ **故障不产生脏状态**（US4 场景 1）。
- 热链路零依赖的机制性保证：P1-P5 代码（market:feed / OrderExecutor / Signal / Billing）**没有任何 import 指向 app/Domain/Archive**——归档是纯消费侧旁路，constitution gate 在 analyze 阶段核对 import 闭包。

**Rationale**: US4 的验收是"热操作全部正常、仅历史查询返回明确降级错误"——机制上用依赖方向（P1-P5 → 0 ← Archive）保证，而非运行时开关；类型化异常让"明确"可测试。

**Alternatives considered**: 全局 feature flag 动态开关归档（拒绝——无热链路依赖就不存在需要开关的故障面，flag 是安慰剂）；异常吞掉返回空结果（拒绝——空结果与"真没数据"不可区分，违反"明确降级"）。

---

## R-010: 状态账目——MySQL 账目双表 + 批次状态机 + 事务水位推进

**Decision**: 主库新增两张账目表（ migrations 落主库，账目与数据同库保证事务性）：
- `archive_batches`：`id`、`data_type`（enum 四值）、`time_from`/`time_to`（UTC）、`source_rows`、`archived_rows`、`verify_status`（pending/passed/failed）、`applied_at`（nullable）、`failed_reason`（nullable）、`timestamps`。CH 行 `batch_id` 指向此 id。
- `archive_watermarks`：`data_type`（unique）、`copied_watermark`（= 最大 copied 批次 time_to，进度展示用）、`applied_watermark`（= 最大 applied 批次 time_to，**路由基准**）、`timestamps`。

批次状态机：`pending → copied → (verify) passed/failed → applied`；failed 批次重跑时复位为 pending 先删 CH 半途行。**applied 阶段事务**：`DB::transaction { 删除主库切片行(带行数再核对，不符即 abort)；推进 archive_watermarks.applied_watermark; 标记批次 applied_at }`——删除与水位原子推进是 R-007 路由正确性的根基。

**Rationale**: constitution IV「幂等守卫用独立一次性标志，不复用业务字段」——verify_status/applied_at 是独立账目字段；双水位区分"搬完"与"删完"，FR-009 进度展示（当前水位/待归档量）直接可算：待归档量 = `COUNT(*) WHERE 时间列 < now - retention AND 时间列 >= copied_watermark`。

**Alternatives considered**: 账目存 CH 自表（拒绝——账目必须与主库删除同事务，跨库无事务）；单水位（拒绝——无法区分 in-flight 批次与已完成删除，路由歧义）。

---

## R-011: config schema、CH DDL 管理与测试策略

**Decision**:
- **config/archive.php**：`ch` 连接块（R-001 env 键）+ `types` 块（每类型 `enabled` / `retention_days` 默认 90 / `batch_window_hours` / `chunk_size` / `throttle_ms`）+ `query_max_rows` + `verify_sample_rows`。未启用类型的 archive:run 显式报错退出。
- **CH DDL 管理**：不引入 CH 侧 migration 机制；artisan `archive:schema` 命令幂等执行 `CREATE TABLE IF NOT EXISTS` 四张 CH 表（DDL 即 contracts/clickhouse-schema-contract.md 的唯一事实源），部署 runbook 一步到位。
- **测试策略**（constitution VI 对齐）：
  - 单元：ArchivePipeline 状态机 / HistoricalQueryService 路由与归并 / 行规范化哈希（fake ArchiveStore + 内存行集），`#[DataProvider]` 驱动。
  - 集成：`ClickHouseStoreIntegrationTest` 以**可用性标志**守卫——CH 不可达时 `markTestSkipped`（与现有 56 skipped 同模式，不阻塞 CI 基线 271 passed）；tearDown 按 batch_id 精确清理测试写入（try/catch 包住、先于 parent::tearDown）。
  - 隔离：双租户数据归档后以租户 A 上下文查询，断言 B 行不可见（SC-004）；无租户上下文调 tenant 类型抛异常。
- **命令族**：`archive:schema` / `archive:run` / `archive:verify` / `archive:status`（命名镜像 ReconcileRunCommand → `reconcile:run` 先例），契约见 contracts/archive-pipeline-contract.md。

**Rationale**: DDL 用命令而非 migration 是因为 CH 不是 Laravel 连接池内的连接，Schema Builder 不适用；幂等 IF NOT EXISTS + 单一事实源 DDL 契约等价于 migration 的可重放性。

**验证指针**: quickstart.md 给出从起服务到全场景验收的完整命令序列；候选待核项（lightweight delete GA 版本、cityHash 实现决策点）在 tasks 对应任务中带验证步骤。

---

## 决策总览（对应 spec Assumptions 的两项 plan 期待定 + 消费契约形态）

| 待定项 | 决策 | 承接 |
|---|---|---|
| 历史存储选型与部署形态 | 自托管单节点 CH（Docker，env 化连接） | R-001 |
| 搬迁机制 | 批量搬迁（copy→verify→apply），零双写，P1-P5 零改动 | R-003/R-005/R-010 |
| 统一查询契约 | 内部服务层 PHP 契约（无 HTTP API），枚举+时间范围+symbol+游标分页 | R-007/R-008 + contracts/historical-query-contract.md |
| 租户隔离下沉 | 查询构造层强制（DTO 无租户字段），public/tenant 分域 | R-008 |
