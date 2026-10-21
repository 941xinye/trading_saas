# Tasks: 信号自动跟单（Retro-spec）

**Input**: Design documents from `/specs/009-signal-auto-follow/`

**Prerequisites**: spec.md ✅, plan.md ✅, data-model.md ✅

**Tests**: 已交付 7 项（Signal 6 + Filament 1）；缺口测试列于 Phase 8。

**说明**: `[x]` 为**代码已在仓库中交付**的任务（回溯登记，不代表本次执行）；`[ ]` 为尚未实现的缺口，按优先级排列。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 不同文件、无依赖，可并行
- **[Story]**: 对应 spec.md 用户故事
- 所有路径相对仓库根

---

## Phase 1: Retro-spec 工作区

- [x] T001 核对 `.specify/feature.json` 并创建 `specs/009-signal-auto-follow/`（spec.md / plan.md / data-model.md / tasks.md）
- [x] T002 [P] 以代码为事实基准核对：`SignalPipeline` → `SignalAutoTrader` → `AutoExecuteSignalJob` → `SignalExecutionService` → `OrderExecutor` 全链路

---

## Phase 2: 数据与配置（Foundational）

- [x] T003 [P] 迁移 `database/migrations/2026_10_21_000009_add_auto_execution_to_signal_subscriptions_table.php`：`auto_execute` / `execution_instance_id`（FK nullOnDelete）/ `execution_notional` / `execution_min_confidence` / `execution_daily_limit` + 索引 `idx_ss_tenant_auto_execute`
- [x] T004 [P] `app/Models/SignalSubscription.php`：补 fillable 与 casts（boolean / decimal:8 / integer）
- [x] T005 [P] `config/signal.php`：`execution.ttl_minutes` / `default_notional` / `max_notional`

**Checkpoint**: 订阅可持久化自动跟单配置

---

## Phase 3: US1 - 订阅开启后自动执行信号 (P1)

- [x] T006 [US1] `app/Domain/Signal/SignalAutoTrader.php`：`isEligible()` 纯内存判定 + `maybeQueue()` 投递 `orders` 队列并记 info
- [x] T007 [US1] `app/Domain/Signal/SignalPipeline.php`：落库与通知后 try/catch 调用 `maybeQueue()`，入队异常不影响信号落库
- [x] T008 [US1] `app/Jobs/AutoExecuteSignalJob.php`：`tries=1`、`onQueue('orders')`、TenantContext initialize/finally clear、二次校验、日上限、执行与日志
- [x] T009 [US1] `app/Domain/Signal/SignalExecutionService.php`：复用全部执行期安全校验与 `OrderExecutor::place()`；`client_order_id = sig_{signal}_{instance}`
- [x] T010 [US1] 测试 `tests/Feature/Signal/SignalAutoFollowTest.php::test_enabled_subscription_places_order_once`（开启即下单 + 重复投递幂等）
- [x] T011 [US1] 测试 `...::test_disabled_subscription_places_no_order`（关闭不下单）
- [x] T012 [US1] 测试 `...::test_auto_trader_queues_job_only_when_eligible`（仅符合条件入队，断言 `Queue::assertPushedOn('orders', ...)`）

**Checkpoint**: 自动跟单主路径可用（MVP）

---

## Phase 4: US2 - 执行前二次校验与安全边界 (P1)

- [x] T013 [US2] Job 内重读订阅（id + tenant_id + active=1）并复核 `auto_execute=1`
- [x] T014 [US2] Job 内租户作用域重读信号与实例，复核 long/short、置信度、`status=running`
- [x] T015 [US2] `SignalExecutionService` 首行接入 `PlatformKillSwitch`（全局急停）
- [x] T016 [US2] `SignalExecutionService` 校验同租户、交易对、TTL、名义额、分配资金、账户与凭据、现货禁空
- [x] T017 [US2] 测试 `...::test_signal_below_min_confidence_is_skipped`
- [x] T018 [US2] 测试 `...::test_stopped_instance_is_skipped`
- [x] T019 [US2] 幂等预检 + `OrderExecutor` 唯一键兜底（DB unique + 活跃状态冲突拒绝；failed 允许同 cid 重放）
- [x] T020 [US2] 异常语义：`SignalExecutionException` → warning 不重试；其他 `Throwable` → error 并抛出

**Checkpoint**: 无人值守链路具备资金安全边界

---

## Phase 5: US3 - 资金与频率上限 (P2)

- [x] T021 [US3] `execution_daily_limit` 日上限统计（`sig_` 前缀 + pending/submitted/partial/filled + 当日 + 按实例）；0 = 不限
- [x] T022 [US3] 测试 `...::test_daily_limit_blocks_further_auto_orders`
- [x] T023 [US3] 单笔名义额上限（`max_notional`）与实例 `allocated_capital` 校验
- [x] T024 [US3] 补测 `execution_daily_limit=0` 时连续执行不拦截
- [x] T025 [US3] 单日自动跟单**资金总额**上限（FR-027）：`config/signal.php` 增 `execution.daily_notional_cap`，Job 内按当日 `sig_` 订单 `notional` 求和

**Checkpoint**: 频率与单笔金额受限；组合级资金上限已补

---

## Phase 6: US4 - 可观测与运维处置 (P2)

- [x] T026 [US4] 结构化日志：入队 / 跳过 / 拒绝（warning）/ 非预期异常（error）/ 成功（含 cid、side、notional、status）
- [x] T027 [US4] 平台急停命令与 Platform 面板按钮（`ops:kill-switch`、`/platform/ops-control`）覆盖信号执行路径
- [x] T028 [US4] 自动跟单审计表 `signal_auto_follow_audits` 与回放工具（FR-028）
- [x] T029 [US4] 自动跟单成交结果主动通知租户（FR-030）

**Checkpoint**: 可排障；审计/通知已补

---

## Phase 7: US5 - 订阅配置入口 (P2)

- [x] T030 [US5] `app/Filament/Admin/Resources/SignalSubscriptionResource.php`：开关 `live()`、条件展示执行实例/名义额/最低置信度/当日上限，实例下拉租户隔离
- [x] T031 [US5] 测试 `tests/Feature/Filament/SignalSubscriptionAutoFollowFormTest.php::test_auto_execution_fields_appear_after_enabling`
- [x] T032 [US5] REST 写接口（FR-026）：`SignalSubscriptionController@store/update/destroy` 实现创建/更新/删除，含自动跟单字段校验、开启时必须绑定本租户实例、跨租户 404；`SignalSubscriptionResource` 回显 5 个自动跟单字段
- [x] T033 [US5] 补 API 功能测试 `tests/Feature/Api/SignalSubscriptionApiTest.php`（9 项）：创建/更新/删除、字段落库与回显、缺实例 422、跨租户实例 422、列表与详情租户隔离

**Checkpoint**: Admin 与 REST 双入口可用

---

## Phase 8: 缺口补测与收口（Polish）

- [x] T034 [P] 测试全局急停下自动跟单零下单（`SignalAutoFollowTest::test_platform_kill_switch_blocks_auto_order`）
- [x] T035 [P] 测试跨租户订阅/实例不可执行（`test_subscription_of_other_tenant_is_skipped`、`test_subscription_pointing_to_foreign_instance_is_skipped`）
- [x] T036 [P] 测试 TTL 过期拒绝（`test_expired_signal_is_skipped`）
- [x] T037 [P] 测试现货账户 short 信号自动拦截（`test_spot_account_rejects_short_signal`）
- [x] T038 [P] 测试重复投递与幂等语义（`test_pending_order_with_same_client_order_id_blocks_replay`、`test_failed_order_with_same_client_order_id_allows_retry`）
- [x] T039 [P] 测试队列/Redis 故障时信号落库不受影响（入队异常降级）
- [x] T040 订阅配置版本号（FR-029）：入队携带版本、执行前比对
- [x] T041 同步文档：README 功能矩阵标注自动跟单「已实现/缺口」，`docs/architecture.md` 补链路图

---

## 依赖关系

```text
Phase 2 (数据/配置)
   └─> Phase 3 (US1 主链路)
          ├─> Phase 4 (US2 安全)
          ├─> Phase 5 (US3 上限)
          ├─> Phase 6 (US4 可观测)
          └─> Phase 7 (US5 配置)
                    └─> Phase 8 (缺口收口)
```

## 实施策略

- 已完成能力保持现状，不因 retro-spec 重写
- 优先补 P0 缺口：REST 写接口（T032/T033）+ 安全测试（T034–T039）
- P1 缺口（资金总额上限、审计）建议另立增量 spec，走 SDD 五阶段
