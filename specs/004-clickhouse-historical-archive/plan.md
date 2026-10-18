# Implementation Plan: ClickHouse 历史数据归档（P8）

**Branch**: `004-clickhouse-historical-archive` | **Date**: 2026-10-18 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/004-clickhouse-historical-archive/spec.md)

**Input**: Feature specification from `/specs/004-clickhouse-historical-archive/spec.md`

## Summary

四类持续增长的时序表（kline_bars / signals / strategy_orders / liquidation_events）冷热分层归档到 ClickHouse 单节点，主库瘦身且热链路零感知。核心架构命题：**批量搬迁管线零双写**（建批 → copy 分块限速 → verify 三级校验 → apply 事务删除+水位推进，P1-P5 代码零 import 零改动——搬迁是纯消费侧旁路）；**幂等锚在 batch_id 审计列**（CH MergeTree 裸表 + 重跑先 lightweight delete 清半途行，查询路径零 FINAL 零去重依赖）；**精度红线跨存储延伸**（Decimal 逐列镜像 + `output_format_json_quote_decimals=1` + 全字符串 DTO，constitution II 的 CH 落点）。统一查询以**内部服务层契约**交付（clarify 决定 2，无 HTTP API）：冷热路由基准 applied 水位（删除与水位同事务的原子性保证路由正确）、跨界归并 + 自然键去重 + 游标分页；租户隔离**下沉到查询构造层**（tenant 类型 DTO 无租户字段，越权通道物理不存在；public 类型按平台公共数据处理）。安全顺序（clarify 决定 3）：dry-run 默认（只搬迁+校验+报告可删范围），`--apply` 显式确认才删主库行，未过校验批次确认模式下同样拒删。经澄清决定 1：手动命令触发（archive:schema/run/verify/status 四命令族），调度自动化留后续。

## Technical Context

**Language/Version**: PHP 8.4（项目已确立，WSL Ubuntu-22.04 开发机 + ServBay MySQL 8.4）

**Primary Dependencies**: Laravel 13.x + Octane(Swoole) 既有栈。**零新增 Composer 依赖**——ClickHouse 经 HTTP 接口直连（`Http` facade 包装在 `ClickHouseStore` 单类内），选型依据 research R-002（精度设置主控权 + 协议极简 + 协程边界清晰）。

**Storage**: MySQL 8.4（四表热数据 + 新增 `archive_batches` / `archive_watermarks` 两张账目表——账目与主库删除同库保证事务性）+ ClickHouse 单节点自托管（Docker，节点机部署；四张 MergeTree 冷表，DDL 契约唯一事实源）。Redis 不涉及。

**Testing**: PHPUnit 12（`#[DataProvider]` 属性，坑 27）。离线断言（fake ArchiveStore）覆盖管线状态机/路由归并/行哈希/租户隔离；`ClickHouseStoreIntegrationTest` 以可用性标志守卫（CH 不可达 skip，与既有 56 skipped 同惯例，不阻塞 271 passed 基线）；tearDown 按 batch_id 精确清理测试数据（constitution VI）。端到端验证由 quickstart.md 九步承载。

**Target Platform**: Linux server（CH Docker 单节点 + Laravel 双进程模型不变；archive 命令为独立 artisan 进程）。

**Project Type**: web-service（Laravel SaaS monolith，多租户）。

**Performance Goals**: 单页历史查询 CH 侧 p95 <100ms（<500ms 的 SC-001 经游标分页达成全年分钟级全量拉取：CH 扫描 + 列裁剪 + 流式分页，tasks 阶段实测校准）；搬迁限速默认 chunk 1000 行/请求、throttle 200ms（FR-012，首跑存量 `--max-batches` 控量）。

**Constraints**:
- 金融精度（constitution II）：Decimal 逐列镜像 + Decimal/64bit 整数引号回读 + 行规范化哈希两侧同构 + 全 UTC；round-trip 逐位相等是集成测试固定断言。
- 幂等续跑（constitution IV / FR-004）：batch_id 独立审计列（不复用业务字段）；重跑先 `DELETE WHERE batch_id` 清半途行再重插；apply 阶段删除+applied 水位推进同一事务。
- 安全顺序（FR-005）：verify gate 红线（未过校验永不进删除候选）+ apply 事务内主库行数再核对 + dry-run 默认。
- 旁路隔离（FR-008 / US4）：P1-P5 代码零 import 指向 `App\Domain\Archive`（analyze 阶段 gate 核对 import 闭包）；纯热查询不触碰 CH。
- 租户隔离（FR-007 / US3）：构造层强制（R-008），tenant 类型 DTO 无租户字段。
- 协程边界（constitution III）：archive:run 独立 artisan 进程无协程；HistoricalQuery 在 Octane 内为阻塞 HTTP 单页 <100ms（与既有 exchange REST 同模式），禁入 market:feed 行情协程进程。

**Scale/Scope**: 4 张 CH 冷表 + 2 张 MySQL 账目表 + 4 个 artisan 命令 + 1 个 `app/Domain/Archive` 包（Contracts/Dto/Types/Services/Stores）；既有模型、既有 config、P1-P5 全部代码零改动。出范围：定时调度、HTTP API、云托管、CH 集群化、Reverb 广播、历史层聚合报表（spec Assumptions）。

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | Plan Compliance | Status |
|---|-----------|------------------|--------|
| I | SDD 优先 | 本 feature 走完整 SDD 五阶段（specify✓ → clarify✓(3 问：决定 1/2/3) → plan 当前 → tasks → analyze → implement） | ✓ PASS |
| II | 金融精度与时间基准 | Decimal 逐列镜像 MySQL 精度；`output_format_json_quote_decimals=1` + `output_format_json_quote_64bit_integers=1` 引号回读；DTO 全字符串形态，消费端 bcmath 直用；行哈希两侧同规范化串（R-004/R-006）；归档水位/批次/查询边界全 UTC | ✓ PASS |
| III | 协程隔离与连接边界 | archive:run 独立 artisan 进程（无协程、无 WS）；HistoricalQuery 阻塞 HTTP 单页 <100ms 与既有 REST 同模式，契约明确禁入 market:feed 协程进程；无慢调用进行情协程 | ✓ PASS |
| IV | 状态持久化与崩溃恢复幂等 | 幂等守卫用独立账目字段（batch_id/verify_status/applied_at，不复用业务字段，R-010）；中断重跑先删 CH batch_id 半途行再重插（R-003）；删除+水位同事务；SIGKILL 中断续跑演练进 quickstart §6（constitution IV 验证范式） | ✓ PASS |
| V | 计费语义与风控边界 | 不触计费/风控/下单/信号路径；P1-P5 零 import 零改动（纯增量）；无 plan_code、无配额交互 | ✓ PASS |
| VI | 测试隔离与完整路径 | CH 集成测试可用性标志 skip（不可达不阻塞 CI 基线）；tearDown 按 batch_id 精确清理、try/catch 包住、先于 parent::tearDown；`#[DataProvider]` 驱动；校验走三级完整路径非单点断言（R-006） | ✓ PASS |
| VII | PSR-4 与框架基类对齐 | 新增文件一文件一类（含 enum/DTO 独立文件）；无框架基类覆写；Redis 不涉及（phpredis 签名坑不适用）；Http facade 用法与既有域一致 | ✓ PASS |

**Gate Result**: 全部 7 项 PASS，无违反，Complexity Tracking 为空。Phase 0 research 完成（R-001~R-011 全部 Resolved；两个候选待核项——lightweight delete GA 版本、cityHash64 自研 vs MD5 降级——均已登记实现期验证指针与降级方案，不阻塞设计），Phase 1 设计产物（data-model / contracts×3 / quickstart）已生成，复检结论不变。

## Project Structure

### Documentation (this feature)

```text
specs/004-clickhouse-historical-archive/
├── spec.md              # specify✓ + clarify✓（3 条 Clarifications：手动触发/内部契约/dry-run 默认）
├── plan.md              # 本文件
├── research.md          # Phase 0 产出（R-001~R-011 决策，含两个候选待核项与降级方案）
├── data-model.md        # Phase 1 产出（账目表/CH 四表/契约实体/状态机/生命周期）
├── quickstart.md        # Phase 1 产出（九步端到端验证指南 + 验收对账表）
├── contracts/
│   ├── clickhouse-schema-contract.md   # CH DDL 唯一事实源 + 读写设置 + null 规范化口径
│   ├── historical-query-contract.md    # HistoricalQuery 服务层契约（语义/隔离/失败/分页）
│   └── archive-pipeline-contract.md    # 四命令族签名 + 管线阶段契约 + 安全红线
├── checklists/
│   └── requirements.md  # specify 阶段产出（16/16）
└── tasks.md             # tasks 阶段产出（非 plan 创建）
```

### Source Code (repository root)

```text
app/Domain/Archive/                    # 唯一新增 Domain 包（P1-P5 零 import，FR-008 机制性隔离）
├── Contracts/
│   ├── HistoricalQuery.php             # 统一历史查询契约（contracts/historical-query-contract.md）
│   ├── ArchiveStore.php                # 历史层存储契约（CH 实现 / 测试 fake 可替换）
│   └── ArchiveType.php                 # 数据类型策略契约（时间轴列/CH 表名/visibility/行映射/规范化列序）
├── Dto/
│   ├── HistoricalQueryRequest.php      # 不可变；tenant 类型无租户字段（R-008）
│   ├── HistoricalQueryResult.php       # rows(列=>字符串) + nextPageCursor + servedFrom
│   └── BatchOutcome.php                # 管线单批报告（三级校验明细/可删范围/删除结果）
├── Types/
│   ├── KlineBarArchiveType.php         # public；时间轴 open_time
│   ├── SignalArchiveType.php           # tenant；时间轴 triggered_at
│   ├── StrategyOrderArchiveType.php    # tenant；时间轴 created_at（非空列，R-005）
│   └── LiquidationEventArchiveType.php # public；时间轴 event_ts
├── Services/
│   ├── HistoricalQueryService.php      # implements HistoricalQuery：路由/归并/游标/上限/隔离强制
│   └── ArchivePipeline.php             # 建批→copy→verify→apply 状态机（事务水位推进，R-010）
└── Stores/
    ├── ClickHouseStore.php             # ArchiveStore 实现：Http facade 直连 HTTP（R-002），
    │                                   #   统一包装 ArchiveStorageUnavailableException（R-009）
    └── RowNormalizer.php               # 行规范化串 + 可交换哈希和（cityHash64 或 MD5 降级，R-006）

app/Console/Commands/
├── ArchiveSchemaCommand.php            # archive:schema（幂等 DDL，CH 侧无 migration 机制）
├── ArchiveRunCommand.php               # archive:run（dry-run 默认 / --apply 显式，FR-005/FR-013）
├── ArchiveVerifyCommand.php            # archive:verify（独立复检，US5）
└── ArchiveStatusCommand.php            # archive:status（FR-009）

config/
└── archive.php                         # ch 连接（env 化，R-001）+ types（enabled/retention_days/
                                        #   batch_window_hours/chunk_size/throttle_ms）+ query_max_rows
                                        #   + verify_sample_rows

database/migrations/
├── 2026_10_18_000001_create_archive_batches_table.php
└── 2026_10_18_000002_create_archive_watermarks_table.php

tests/Domain/Archive/
├── ArchivePipelineTest.php             # 状态机/账目/verify gate/幂等续跑（fake ArchiveStore）
├── HistoricalQueryServiceTest.php      # 路由/归并去重/游标/上限/错误语义（fake ArchiveStore）
├── RowNormalizerTest.php               # 规范化串与哈希黄金向量（含 null/Decimal 边界）
├── TenantIsolationTest.php             # 双租户归档后零泄露 + 无租户上下文拒绝（SC-004）
└── Stores/
    └── ClickHouseStoreIntegrationTest.php  # 真实 CH：DDL/insert/轻量删除/round-trip 精度
                                           #   （可用性标志 skip；tearDown 按 batch_id 清理）
```

**Structure Decision**: 单项目（Laravel monolith）。纯增量：全部新增代码收敛于 `app/Domain/Archive/` + 4 个命令 + 1 个 config + 2 个 migration + 1 个测试目录；既有 Eloquent 模型热侧复用（TenantScope 自动注入继续生效）、既有 config/命令/P1-P5 全部零改动。数据类型差异内聚在 `ArchiveType` 策略四实现（镜像 spec 002 RequestSigner 策略域范式），管线与查询服务对类型无感知——第五类数据接入 = 新增一个 Type 实现，验证开闭性。

## Complexity Tracking

> Constitution Check 全部 PASS，无违反需 justify，此表为空。
