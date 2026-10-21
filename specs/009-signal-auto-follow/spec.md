# Feature Specification: 信号自动跟单（Retro-spec）

**Feature Branch**: `009-signal-auto-follow`

**Created**: 2026-10-21

**Status**: Implemented (retro-spec)

**Input**: User description: "好的，开始自动跟单 retro-spec" —— 为已交付的「信号自动跟单」能力补写规格，以现有代码为事实基准（as-built），同时显式登记未实现缺口，供后续迭代排期。

> **Retro-spec 说明**：constitution I 约定「不为 P1–P5.5 现有代码补 retroactive spec」。本文件是用户显式要求的例外，用途是**记录已实现行为 + 盘点缺口**，不作为回溯改造依据；任何新增行为仍需按 SDD 五阶段另立增量 spec。

## 背景

信号分析链路（SignalPipeline）在已闭 bar 上产出 long/short 信号并落库、分发通知。历史上信号仅通知、人工确认后调用 `POST /v1/signals/{signal}/execute` 一键下单（`SignalExecutionService`）。自动跟单在订阅维度增加一个开关：开启后，信号落库即投递 `orders` 队列 Job，在无人值守情况下复用同一执行服务完成下单与全部安全校验。

**设计红线**：自动跟单不得复制任何下单/风控逻辑，必须复用 `SignalExecutionService`；`ai` 队列 worker 不得直接触达交易所（constitution III）。

## User Scenarios & Testing *(mandatory)*

### User Story 1 - 订阅开启后自动执行信号 (Priority: P1) — 已实现

租户在 Admin「信号订阅」编辑页开启「自动跟单」，绑定一条 running 的策略实例，设置单笔名义额（可选，留空用默认值）。此后该订阅命中新信号时，系统自动在 `orders` 队列完成市价下单，无需人工确认。

**Why this priority**: 这是功能本体；没有自动执行，其余配置项无意义。

**Independent Test**: 构造一条满足全部条件的订阅与信号，执行 `AutoExecuteSignalJob::handle()`，断言 `strategy_orders` 新增一行且 `side` 与信号方向一致；重复执行同一 Job 断言订单数仍为 1。

**Acceptance Scenarios**:

1. **Given** 订阅 `active=1`、`auto_execute=1`、绑定 running 实例，**When** 落库一条 long 信号，**Then** 自动产生 `client_order_id = sig_{signalId}_{instanceId}` 的买单
2. **Given** 同一信号被重复投递，**When** Job 再次执行，**Then** 不新增订单（幂等）
3. **Given** 订阅 `auto_execute=0`，**When** 信号落库，**Then** 不入队、不下单

---

### User Story 2 - 执行前二次校验与安全边界 (Priority: P1) — 已实现

订阅配置可能在入队后被修改、停用，或实例被熔断暂停。Job 必须在真正下单前重新读取数据库并复核全部条件；任一不满足即跳过并记录可观测日志，绝不「带病下单」。

**Why this priority**: 资金安全高于功能可用性；自动执行无人工兜底，二次校验是唯一防线。

**Independent Test**: 分别构造「订阅已停用」「置信度低于阈值」「实例非 running」「信号超过 TTL」「现货账户做空」五种场景，断言零订单且日志级别符合预期。

**Acceptance Scenarios**:

1. **Given** 订阅在入队后被停用，**When** Job 执行，**Then** 跳过且不产生订单
2. **Given** 信号置信度低于 `execution_min_confidence`，**When** Job 执行，**Then** 跳过
3. **Given** 实例状态非 `running`，**When** Job 执行，**Then** 跳过
4. **Given** 信号 `triggered_at` 超过 15 分钟执行窗口，**When** 执行服务校验，**Then** 拒绝（`SignalExecutionException`）
5. **Given** 现货账户收到 short 信号，**When** 执行服务校验，**Then** 拒绝
6. **Given** 平台全局急停已启用，**When** 任意信号执行（含自动跟单），**Then** 在第一步即拒绝

---

### User Story 3 - 资金与频率上限 (Priority: P2) — 已实现（部分上限）

租户可配置单笔执行名义额、当日信号单笔数上限；系统另以全局单笔名义额上限与实例分配资金作为硬约束。所有金额计算使用 bcmath 字符串。

**Why this priority**: 自动执行会放大错误信号的损失，上限是止损边界；但基础链路可先可用，故列 P2。

**Independent Test**: 预置当日已达上限的 `sig_` 订单，断言 Job 跳过；再以超限名义额直接调用执行服务，断言被拒。

**Acceptance Scenarios**:

1. **Given** `execution_daily_limit=1` 且当日已有 1 笔有效 `sig_` 订单，**When** 新信号到达，**Then** 跳过
2. **Given** `execution_daily_limit=0`，**When** 连续执行多笔，**Then** 不按笔数拦截
3. **Given** 名义额超过 `signal.execution.max_notional`（默认 10000 USDT），**When** 执行，**Then** 拒绝
4. **Given** 名义额超过实例 `allocated_capital`（>0 时），**When** 执行，**Then** 拒绝

---

### User Story 4 - 可观测与运维处置 (Priority: P2) — 部分实现

自动跟单的入队、跳过、拒绝、成功分别写入结构化日志，便于运维检索；平台级急停可一键阻断所有自动开仓（含自动跟单）。

**Why this priority**: 无人值守链路必须可排障；但日志已随实现落地，独立审计/回放仍缺失。

**Independent Test**: 触发跳过与成功各一次，断言日志含 signal_id/subscription_id/instance_id；启用急停后断言订单数为 0。

**Acceptance Scenarios**:

1. **Given** 订阅未开启自动执行，**When** 信号落库，**Then** 不写「已入队」日志
2. **Given** 预期内拒绝（TTL/重复/额度/凭据），**When** Job 执行，**Then** `Log::warning` 且不重试
3. **Given** 非预期异常，**When** Job 执行，**Then** `Log::error` 并抛出（队列记为失败）
4. **Given** 平台急停开启，**When** 自动跟单执行，**Then** 被拒且不触达交易所

---

### User Story 5 - 订阅配置入口 (Priority: P2) — 部分实现

租户在 Filament「信号订阅」表单开启自动跟单并配置执行实例、单笔名义额、最低置信度、当日上限；开启后执行实例字段必填。

**Why this priority**: 配置是使用前提，但当前 REST 写接口仍是骨架，Admin 表单是唯一可用入口。

**Independent Test**: `Livewire::test(CreateSignalSubscription::class)` 将 `auto_execute` 置 1，断言出现执行实例/最低置信度/当日信号单上限字段，且可选到本租户 running 实例。

**Acceptance Scenarios**:

1. **Given** 打开新建订阅页，**When** `auto_execute` 选择「开启」，**Then** 展示执行实例、单笔名义额、最低置信度、当日信号单上限
2. **Given** `auto_execute=1`，**When** 未选择执行实例提交，**Then** 校验失败
3. **Given** 执行实例下拉，**When** 展开，**Then** 仅列出本租户实例（如 BTCUSDT）

---

### Edge Cases

- **中性信号**：constitution V 规定 neutral 不落库，自动跟单天然不会收到；`isEligible()` 仍对非 long/short 方向做防御性拒绝
- **订阅绑定实例被删除**：`execution_instance_id` 外键为 `nullOnDelete`，Job 二次校验发现实例为空即跳过
- **信号缺少入场价**：执行服务回退到 ticker 最新价；行情不可用则拒绝，不猜测价格
- **重复入队/重复执行**：`client_order_id` 幂等 + `strategy_orders` unique(tenant, cid) 双保险；已处于 pending/submitted/partial/filled 时直接冲突拒绝
- **下单失败后重放**：`failed` 状态不占用幂等锚点，同一 cid 允许重试（`OrderExecutor` 复用既有行）
- **队列不可用**：入队异常被 SignalPipeline 捕获，仅记 error，不影响信号落库与通知
- **Worker 崩溃**：`tries=1`，不自动重试，避免交易所状态不明时重复下单

## Requirements *(mandatory)*

### Functional Requirements

**订阅配置**

- **FR-001** `[已实现]` 订阅必须支持 `auto_execute` 开关，默认关闭
- **FR-002** `[已实现]` 开启时必须绑定一个策略实例（`execution_instance_id`，实例删除时置空）
- **FR-003** `[已实现]` 必须支持可选单笔名义额 `execution_notional`（decimal 20,8），留空使用 `signal.execution.default_notional`（默认 100 USDT）
- **FR-004** `[已实现]` 必须支持最低置信度 `execution_min_confidence`（0–100，0 表示不过滤）
- **FR-005** `[已实现]` 必须支持当日信号单笔数上限 `execution_daily_limit`（默认 20，0 表示不限）

**触发与队列**

- **FR-006** `[已实现]` 信号落库且通知分发后，必须由 `SignalAutoTrader` 做纯内存前置判定：开关开启、实例已绑定、方向 long/short、置信度达阈值
- **FR-007** `[已实现]` 满足条件必须投递 `AutoExecuteSignalJob(tenantId, signalId, subscriptionId)` 到 `orders` 队列
- **FR-008** `[已实现]` 入队失败不得影响信号落库与通知（try/catch 包裹）
- **FR-009** `[已实现]` Job 必须 `tries=1`，不自动重试；初始化 tenant context 并在 finally 清理

**二次校验**

- **FR-010** `[已实现]` Job 必须按 id + tenant_id + `active=1` 重读订阅，且复核 `auto_execute=1`
- **FR-011** `[已实现]` 必须复核信号存在、租户一致、方向 long/short、置信度阈值
- **FR-012** `[已实现]` 必须复核实例存在、租户一致、状态为 `running`
- **FR-013** `[已实现]` 必须按实例统计当日 `client_order_id` 以 `sig_` 开头且状态为 pending/submitted/partial/filled 的订单数，达到上限即跳过

**执行与风控（复用 SignalExecutionService）**

- **FR-014** `[已实现]` 执行第一步必须检查平台级急停 `PlatformKillSwitch`，开启即拒绝
- **FR-015** `[已实现]` 必须校验信号与实例同租户、同交易对（大小写不敏感）
- **FR-016** `[已实现]` 必须校验信号在 TTL 窗口内（`signal.execution.ttl_minutes`，默认 15 分钟）
- **FR-017** `[已实现]` 必须校验名义额 > 0、≤ `signal.execution.max_notional`（默认 10000）、≤ 实例 `allocated_capital`（>0 时）
- **FR-018** `[已实现]` 必须校验账户存在、凭据可解密、交易所定义存在；现货账户拒绝做空
- **FR-019** `[已实现]` 必须使用 `client_order_id = sig_{signalId}_{instanceId}`；已处于有效状态时按冲突拒绝
- **FR-020** `[已实现]` 参考价优先取 `entry_price`，缺失时取 ticker；仍不可得则拒绝
- **FR-021** `[已实现]` `side` 由方向映射（long→buy，short→sell）；`qty = notional / referencePrice`，bcmath 12 位
- **FR-022** `[已实现]` 下单必须复用 `OrderExecutor::place()`：权益/日配额闸门 → LOT_SIZE 对齐 → 先落库后下单 → 回写成交
- **FR-023** `[已实现]` 预期内拒绝记 warning 且不重试；非预期异常记 error 并抛出

**可观测与运维**

- **FR-024** `[已实现]` 入队、跳过、拒绝、成功必须写入结构化日志（signal_id / subscription_id / instance_id / 原因）
- **FR-025** `[已实现]` 平台急停必须同时阻断策略开仓与信号执行（含自动跟单），平仓/reduceOnly 放行

**接口与缺口登记**

- **FR-026** `[已实现]` 订阅 REST 写接口：`POST/PATCH/DELETE /v1/signal-subscriptions` 支持创建、更新、删除，自动跟单字段与 Filament 同语义校验；开启自动跟单必须绑定本租户实例；跨租户一律 404/422
- **FR-027** `[已实现]` 租户级单日自动跟单**资金总额**上限：`signal.execution.daily_notional_cap` 按租户当日 `sig_` 有效订单 `notional` 汇总比对（0 = 不限；人工一键下单同计入）
- **FR-028** `[已实现]` 自动跟单决策审计表 `signal_auto_follow_audits`（append-only）与回放命令 `signal:auto-follow-trace {signalId}`
- **FR-029** `[已实现]` 订阅配置版本号 `execution_config_version`：执行字段变更自动 +1，入队携带、执行前比对，不一致跳过
- **FR-030** `[已实现]` 自动跟单成交结果主动通知：`NotifyAutoFollowExecutedJob`（notifications 队列）按订阅渠道投递

### Key Entities *(include if feature involves data)*

- **信号订阅（signal_subscriptions）**：自动跟单配置宿主；本功能新增 `auto_execute`、`execution_instance_id`、`execution_notional`、`execution_min_confidence`、`execution_daily_limit`，并建索引 `(tenant_id, auto_execute)`
- **信号（signals）**：触发源；关键属性 `tenant_id`、`symbol`、`direction`（long/short）、`confidence`、`entry_price`、`triggered_at`
- **策略实例（strategy_instances）**：执行载体；关键属性 `tenant_id`、`symbol`、`status`、`allocated_capital`、`leverage`
- **策略订单（strategy_orders）**：执行结果与幂等锚点；`unique(tenant_id, client_order_id)`，状态 pending/submitted/partial/filled/failed
- **交易所账户（exchange_accounts）**：凭据来源；`market` 决定是否允许做空，凭据经 KEK→DEK 解密

**状态流**

```text
信号落库 → [前置判定失败] 结束（不入队）
          → [前置判定通过] orders 队列
              → [二次校验失败] 跳过（warning/info）
              → [执行服务拒绝] 跳过（warning）
              → [成功] 订单落库 + 回写成交（info）
              → [非预期异常] 抛错（error，队列 failed）
```

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 开启自动跟单的订阅在信号落库后，无需人工操作即产生 1 笔订单，且 `side` 与信号方向 100% 一致
- **SC-002**: 同一信号重复投递 N 次，`strategy_orders` 中同 `client_order_id` 行数恒为 1
- **SC-003**: 关闭开关、置信度不足、实例非 running、超过日上限、超过 TTL、现货做空、全局急停七类场景，自动下单数均为 0
- **SC-004**: 自动跟单全部路径复用 `SignalExecutionService`，不存在第二套下单/风控实现（架构守护）
- **SC-005**: 入队异常不导致信号丢失：信号落库与通知成功率不因队列故障下降
- **SC-006**: 相关自动化测试全绿（订阅 API 9 项 + 自动跟单 14 项 + 订阅表单 1 项 = 24 项）

## 现有验证映射（retro-spec）

| 覆盖点 | 测试 |
|---|---|
| 开启即下单 + 重复投递幂等 | `tests/Feature/Signal/SignalAutoFollowTest::test_enabled_subscription_places_order_once` |
| 关闭不下单 | `...::test_disabled_subscription_places_no_order` |
| 置信度阈值 | `...::test_signal_below_min_confidence_is_skipped` |
| 实例非 running | `...::test_stopped_instance_is_skipped` |
| 当日上限 | `...::test_daily_limit_blocks_further_auto_orders` |
| 前置判定与队列名 | `...::test_auto_trader_queues_job_only_when_eligible` |
| 表单联动与租户隔离下拉 | `tests/Feature/Filament/SignalSubscriptionAutoFollowFormTest` |

补充验证（2026-10-21）：

| 覆盖点 | 测试 |
|---|---|
| 订阅创建/更新/删除 + 自动跟单字段 | `tests/Feature/Api/SignalSubscriptionApiTest`（9 项） |
| 开启自动跟单缺实例 / 跨租户实例 | 同上：`store_requires_instance_*`、`store_rejects_foreign_tenant_instance` |
| 列表与详情租户隔离 | 同上：`index_is_tenant_isolated`、`cross_tenant_subscription_is_not_found` |
| 全局急停 | `SignalAutoFollowTest::test_platform_kill_switch_blocks_auto_order` |
| 跨租户订阅 / 跨租户实例 | `...::test_subscription_of_other_tenant_is_skipped`、`...::test_subscription_pointing_to_foreign_instance_is_skipped` |
| TTL 过期 | `...::test_expired_signal_is_skipped` |
| 现货做空 | `...::test_spot_account_rejects_short_signal` |
| 日上限 0 = 不限 | `...::test_zero_daily_limit_means_unlimited` |
| 重复投递（pending 拦截 / failed 可重放） | `...::test_pending_order_with_same_client_order_id_blocks_replay`、`...::test_failed_order_with_same_client_order_id_allows_retry` |

**仍未覆盖**：队列/Redis 故障降级（入队异常不影响信号落库）。

## Assumptions

- 自动跟单仅处理 long/short；neutral 按 constitution V 不落库，天然不进入本链路
- 自动跟单与人工一键下单共享同一幂等键模板与执行服务，语义完全一致
- `orders` 队列由独立普通 PHP worker 消费，不与 `market:feed` 协程进程混跑
- 当前所有金额均以 USDT 计价，跨币种名义额换算不在本期
- 订阅 REST 写接口已补齐（FR-026）；剩余缺口为单日资金总额上限、审计/回放、配置版本号、成交通知
