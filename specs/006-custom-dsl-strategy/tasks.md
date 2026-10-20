# Tasks: 自定义 DSL 策略引擎（P7）

**Input**: Design documents from `/specs/006-custom-dsl-strategy/`

**Prerequisites**: [plan.md](plan.md)（必需）/ [spec.md](spec.md)（US1–US5）/ [data-model.md](data-model.md) / [contracts.md](contracts.md) / [research.md](research.md) / [quickstart.md](quickstart.md)

**Tests**: 本 feature 明确要求自动化断言（SC-001~010），采用测试先行（constitution I）。所有测试任务在对应实现任务之前完成并先失败。

**Organization**: 按 plan.md §2 的 WS1–WS6 工作流组织，映射到 spec.md 的 US1–US5 优先级。

## Format: `[ID] [P?] [Story] 描述`

- **[P]**: 可并行（不同文件、无未完成依赖）
- **[Story]**: 归属用户故事（US1–US5）
- 每个任务含精确文件路径 + `验收` 断言

## Path Conventions

单项目 Laravel：`app/`、`config/`、`tests/` 均在仓库根。测试分层 `tests/Unit` / `tests/Integration` / `tests/Feature`（与既有 spec 001–005 一致）。
constitution VI：PHPUnit 12 静态 `#[DataProvider]`；Redis 用例打 `@group redis`；Feature 用 `RefreshDatabase`；tearDown 精确清理共享 TTL 键且先于 `parent::tearDown()`。

---

## Phase 1: Setup（共享基础设施）

**Purpose**: 配置扩展与目录/契约骨架，供所有工作流消费。

- [x] T001 新增 `config/strategy.php` `dsl` 块（`max_script_bytes=8192` / `max_lines=200` / `max_depth=8` / `max_rules_per_section=20` / `max_indicators=8` / `eval_step_budget=500` / `indicator_window_max=150` / `allowed_triggers` / `allowed_actions` / `allowed_indicators`）；**保留**既有 `strategies.custom_dsl.dsl_schema` 不删
  - 验收：`config('strategy.dsl.eval_step_budget')` 可读为 500；`config('strategy.strategies.custom_dsl.dsl_schema')` 仍存在；新增 `tests/Unit/Strategy/Dsl/DslConfigConsistencyTest.php` 断言 `dsl.allowed_triggers/actions/indicators` 与 `dsl_schema` 取值一致（FR-002 / data-model §6.1）

---

## Phase 2: Foundational（阻塞前置 — WS1 + WS2）

**⚠️ CRITICAL**: 所有 US 均依赖此阶段完成。

### WS1: DSL 子集解析器 + AST + 诊断

- [x] T002 [P] 新建 AST 不可变模型：`app/Domain/Strategy/Custom/Dsl/{DslProgram,DslMeta,RuleNode,ConditionNode,OperandNode,DslRisk}.php`（readonly，字段严格按 contracts §3 / data-model §2）
  - 验收：6 类只读、类型签名与 contracts 一致；`RuleNode.action ∈ open_long|open_short|close_long|close_short|noop`，`qty` 与 `qtyPct` 二选一
- [x] T003 [P] 新建诊断与异常：`app/Domain/Strategy/Custom/Dsl/{DslDiagnostic,DslValidationException}.php`（`DslDiagnostic` 含 `level/line/field/message`；异常携带 `DslDiagnostic[]` 并暴露 `diagnostics()`）
  - 验收：异常 message 由 error 级诊断拼接；`diagnostics()` 返回数组（data-model §3）
- [x] T004 实现 `app/Domain/Strategy/Custom/Dsl/DslYamlParser.php`：仅支持块映射/块序列/plain 标量/注释/中文注释/2 空格缩进；预扫描拒绝 锚点/别名/tag/多文档/flow 语法/Tab 缩进/重复键，均抛 `DslValidationException` 且带行号
  - 验收：新建 `tests/Unit/Strategy/Dsl/DslYamlParserTest.php`——合法子集解析成嵌套数组；7 类违规各自定位行号（FR-001/002/004 / spec Edge）
- [x] T005 [P] 实现 `app/Domain/Strategy/Custom/Dsl/DslCompiler.php`：顶层键白名单 + 四段（entry/exit/filter/risk）解析 + 枚举/类型/范围校验 + AST 构建（`sourceHash=sha1(raw)`）
  - 验收：新建 `tests/Unit/Strategy/Dsl/DslCompilerTest.php`——未知顶层键/未知 op/未知指标/未知动作/四段缺失/类型不符/`period≤0`/`qty≤0`/分式不在 `(0,1]` 各拒并带 `field` 定位；**脚本 `version > 1`（高于支持）拒**（contracts §4）；**`meta.symbol`/`meta.name`/`meta.interval` 空值拒**；超限（字节/行/深度/规则数/指标数）拒（FR-002/005 / spec Edge）
- [x] T006 填充 `app/Domain/Strategy/Custom/DslParser.php`（既有占位）：编排 `预扫描 → DslYamlParser → DslCompiler`，`parse(string): DslProgram`（收窄既有 `parse(): array`）
  - 验收：新建 `tests/Unit/Strategy/Dsl/DslParserTest.php`——落单 `contracts §10` MA 金叉示例编译为期望 AST（`entries[0].condition.op=cross_above`、operand=ma7/ma25、`qty_pct="0.30"`、`meta.trigger=on_bar`、`sourceHash=sha1(script)`）；非法脚本（含高版本 `version`）抛 `DslValidationException`（FR-002/005）

### WS2: 沙箱求值器 + 限额

- [x] T007 [P] 新建求值契约与 DTO：`app/Domain/Strategy/Custom/Dsl/{DslInterpreter,DslEvalContext,DslEvalResult,DslSignal,DslExecutionException}.php`（签名按 contracts §5）
  - 验收：接口 `evaluate(DslProgram, DslEvalContext): DslEvalResult`；DTO readonly；`DslExecutionException` 可抛
- [x] T008 [P] 实现 `app/Domain/Strategy/Custom/Dsl/IndicatorSnapshotBuilder.php`：窗口=`max(operand period)+1`（上限 `indicator_window_max`），复用 `IndicatorCalculator::compute()`，NaN→null，每 bar 至多一次
  - 验收：新建 `tests/Unit/Strategy/Dsl/DslIndicatorSnapshotTest.php`——`ma[p]{v,prev}` / `macd{dif,dea,hist,hist_prev}` 复用内置 prev；**操作数指标名 `bollinger` 映射到计算器输出键 `boll{upper,middle,lower}`**；`rsi/boll/atr/price` 用 shifted snapshot；窗口不足输出 null（FR-008 / data-model §5）
- [x] T009 实现 `app/Domain/Strategy/Custom/Dsl/WhitelistInterpreter.php`：判别式 AST 的 `match` 求值（comparison/logical/cross/always），白名单原生，禁 eval/exec/反射/IO；NaN/null ⇒ 条件 false；step/time budget 超限抛 `DslExecutionException`
  - 验收：新建 `tests/Unit/Strategy/Dsl/DslInterpreterTest.php`——四类条件真值表（含 NaN→null 不触发、窗口不足不触发、cross 的 prev）；超 `eval_step_budget` 中断不静默（FR-003/004/008/017 / SC-009）

**Checkpoint**: 解析链 + 沙箱内核就绪，US1–US5 可并行开工。

---

## Phase 3: User Story 1 - YAML 策略定义 → 装载 → 实跑闭环 (Priority: P1) 🎯 MVP

**Goal**: `DslStrategy` 全生命周期实现，装载编译→onBar 求值→幂等下单→成交回报→状态持久化，崩溃恢复无重复下单。

**Independent Test**: 写最简 YAML（MA 上穿开多/下穿平多），装载并跑通 onBar→信号→下单→成交回报，验证订单回报与状态持久化；SIGKILL 后重启无重复下单。

### Tests for User Story 1 ⚠️（先写并失败）

- [x] T010 [P] [US1] `tests/Unit/Strategy/Dsl/DslStrategyLifecycleTest.php`：onBar→pending→shouldOpen/shouldClose + 5 动作枚举映射（FR-006）
- [x] T011 [P] [US1] `tests/Unit/Strategy/Dsl/DslStateMigrationTest.php`：state(v2) round-trip + v1→v2 迁移 + 高版本拒绝（FR-010 / spec Edge）
- [x] T012 [P] [US1] `tests/Unit/Strategy/Dsl/DslPrecisionTest.php`：`qty_pct` 换算边界（极小/极大/尾数舍入，bcmath 零损）(FR-009 / SC-010)
- [x] T013 [P] [US1] `tests/Integration/Strategy/DslOrderExecutorTest.php`：RecordingAdapter 端到端 onBar→shouldOpen→`OrderExecutor::place`→onFill 闭环，`clientOrderId` 可查回不重复（FR-006/009 / SC-001）
- [x] T014 [US1] `tests/Integration/Strategy/DslCrashRecoveryTest.php`（`@group redis`）：pending 单核实 + 状态/指标尾部重放无重复下单（FR-010 / SC-005）

### Implementation for User Story 1

- [x] T015 [US1] 填充 `app/Domain/Strategy/Custom/DslStrategy.php` 构造期：读 `params['dsl_script']` → `DslParser::parse()` 持 `DslProgram`，非法即抛（不进入运行态）；实现 `onTick`/`onBar`/`onFill`（求值 → 挂 `pending_signal`；onFill 更新持仓快照与 `realizedPnl` 并清理已兑现信号）
  - 验收：T010 绿；非法脚本构造抛 `DslValidationException`；**装载期校验 `meta.symbol` 与实例 symbol（`params['symbol']`）一致，不一致即拒**；**`trigger=on_fill` 时 `onFill` 亦触发四段求值**（FR-005/006）
- [x] T016 [US1] 实现 `shouldOpen`/`shouldClose` + `risk` 段（冷却、`max_position_pct`、`stop_loss_pct`/`take_profit_pct` 合成条件、`max_entries_per_bar`）；`qty_pct` 开仓 `bcdiv(bcmul(allocatedCapital,qtyPct,8),refPrice,8)`（refPrice=bar.close/ticker.last，不可得则不产出），平仓 `bcmul(positionQty,qtyPct,8)`；显式 `qty` 原样透传
  - 验收：T012 绿；平仓路径不受熔断拦截（FR-009/013）
- [x] T017 [US1] 覆写 `getState`/`restoreState`：`protected const SCHEMA_VERSION = 2`；`positionSnapshot['dsl']={compiled_hash,last_signal_bar,cooldown_remaining,pending_signal,recovered}`；v1→v2 迁移分支；高版本拒绝；`compiled_hash` 不一致记 warning
  - 验收：T011 绿（FR-010）
- [x] T018 [US1] 实现 `RecoverablePendingOrders`：`pendingClientOrderIds()` 返回 `pending_signal.client_order_id`；`applyRecoveredOrder(ExchangeOrder)` 复用 P5.5 语义（filled 入账 / 其余终态释放占位）；恢复幂等守卫用独立一次性标志 `recovered`
  - 验收：T014 绿（FR-010 / constitution IV）
- [x] T019 [US1] 接通 `OrderExecutor::place()`（decision `{side,qty,price?,client_order_id?}`，bcmath 字符串），经 T013 闭环
  - 验收：T013 绿（FR-006/009 / SC-001）

**Checkpoint**: US1 独立可跑通闭环。

---

## Phase 4: User Story 2 - 沙箱执行与安全红线 (Priority: P1)

**Goal**: 以语料库证明红线在解析与运行两处 100% 被拒；超限被拦截且失败不静默。

**Independent Test**: 投喂含 `eval`/`exec`/文件/网络/shell/未知原语脚本断言两处均拒；投喂超长/深嵌套断言规模上限拦截。

- [x] T020 [US2] `tests/Integration/Strategy/DslSandboxRedlineCorpusTest.php`：语料库覆盖 `eval`/`exec`/文件读写/网络/shell/未知原语/未知指标/未知动作/未知 op，断言**解析期**（`DslParser`）与**运行期**（`WhitelistInterpreter`）双处拒绝，绝不执行（SC-003）
  - 验收：全部断言绿；无任何脚本被求值执行
- [x] T021 [US2] `tests/Unit/Strategy/Dsl/DslNoDynamicEvalTest.php`：静态扫描 `app/Domain/Strategy/Custom/**` 断言无 `eval` / `exec` / `shell_exec` / `proc_open` / `fs` 写 / 网络调用 / `ReflectionClass` 等动态求值路径（FR-003/017 解析侧红线）
  - 验收：扫描断言绿；新增文件不引入违规调用
- [x] T022 [US2] 规模/成本上限用例补齐：字节/行/深度/规则数/指标数超限各拒（并入 T005 用例集）；每 bar step budget 与耗时上限超限中断并记录（并入 T009/T020 用例集）
  - 验收：SC-004/SC-009 用例齐备并绿（FR-004 / spec Edge）；**每 bar 求值给出可测上界数值**（step 数 + 毫秒级耗时，fixture 断言）

**Checkpoint**: 红线可证；US2 独立验收完成。

---

## Phase 5: User Story 3 - 与内置策略语义等价 (Priority: P2)

**Goal**: 证明等价 DSL 与内置策略决策序列一致（触发 bar/side/qty），指标逐点一致。

**Independent Test**: 固定 bar fixture + 固定参数，分别驱动等价 DSL 与内置策略，断言决策序列完全一致。

- [x] T023 [P] [US3] `tests/Integration/Strategy/DslEquivalenceTest.php`（SpotDca parity）：`{op:true}` + on_bar DSL 与 `SpotDcaStrategy` 在固定 fixture 下决策序列 100% 一致
  - 验收：触发 bar/side/qty 全等（FR-007 / SC-002）
- [x] T024 [US3] 同上文件补 SpotGrid parity（小网格：`{price:close}` vs `{const:level}`）；并断言 DSL 引用指标与内置策略同一 `IndicatorCalculator` 实现的输出（含 NaN→null）逐点一致
  - 验收：SC-002 / US3-2 绿

**Checkpoint**: 语义等价有客观基准。

---

## Phase 6: User Story 4 - 在线编辑器与回测预览 (Priority: P2)

**Goal**: Filament 即时校验/试装载/保存草稿 + 租户隔离；历史 K 线轻量预览与实跑一致。

**Independent Test**: 编辑器写错误 DSL 断言即时定位并阻断保存；写正确 DSL 对历史数据预览，断言信号序列与实跑一致。

### Implementation for User Story 4

- [x] T025 [P] [US4] `app/Domain/Strategy/Custom/Dsl/DslValidator.php`（接口）+ 实现（新增 `app/Domain/Strategy/Custom/Dsl/DslScriptValidator.php`）：`validate(string): DslDiagnostic[]`，空数组=通过；仅收集诊断不启动实例（contracts §8）
  - 验收：含错误脚本返回带 行/字段 诊断；正确脚本返回空数组（FR-011）
- [x] T026 [US4] `app/Filament/Admin/Resources/StrategyInstanceResource.php`（含对应 Pages）：DSL 编辑器（YAML 字段 + 即时校验 + 试装载 action + 保存草稿写 `params.dsl_script`）；Resource 走 `TenantScope`
  - 验收：错误 DSL 阻断保存/试装载；试装载仅编译校验不启动实例；跨租户草稿不可见（FR-011/016 / US4-1/3）
- [x] T027 [US4] `tests/Feature/DslEditorValidationTest.php`：校验定位 + 阻断 + 试装载不启动 + 租户隔离（`RefreshDatabase`）
  - 验收：T027 绿（FR-011/016）
- [x] T028 [P] [US4] `app/Domain/Strategy/Custom/Dsl/Preview/{DslPreviewService,HistoricalPreviewService,DslPreviewResult}.php`：复用 P8 `HistoricalQuery(KlineBar)` 分页（默认 30 天，`pageSize` 取 config，`cursor` 续页），行 → `ExchangeKline` → **同一** `DslInterpreter` 回放；输出信号序列 + 简易资金曲线 + 统计，注明「未模拟滑点/手续费」（contracts §7）
  - 验收：返回 `DslPreviewResult`（signals/equityCurve/barCount/signalCount/triggerRate/assumption/nextPageCursor）（FR-012）
- [x] T029 [US4] `tests/Integration/Strategy/DslPreviewConsistencyTest.php`：同 DSL 预览信号序列 == 实跑信号序列（SC-006）
  - 验收：T029 绿（FR-012 / SC-006）

**Checkpoint**: US4 编辑器与预览可用且一致。

---

## Phase 7: User Story 5 - 售卖门控与计费/风控接线 (Priority: P2)

**Goal**: `custom_dsl` 回归白名单（pro/enterprise），结构性断言防超卖；DSL 下单同受两阶段计费与账户熔断约束。

**Independent Test**: 含/不含自定义策略套餐租户创建 DSL 实例断言门控；配额耗尽下单断言交易所零调用；熔断 OPEN 下断言平仓放行/开仓拦截。

### Implementation for User Story 5

- [x] T030 [US5] `config/strategy.php` `implemented` 追加 `custom_dsl`（`['spot_grid','spot_dca','futures_grid','custom_dsl']`）
  - 验收：`config('strategy.implemented')` 含 `custom_dsl`（FR-015）
- [x] T031 [US5] `config/plans.php` `pro`/`enterprise` 的 `features.strategies` 追加 `custom_dsl`（`free` 不变）
  - 验收：`pro`/`enterprise` 含 `custom_dsl`；`free` 不含（FR-015）
- [x] T032 [US5] 验证/确认 `app/Domain/Strategy/StrategyRegistry.php` 映射 `custom_dsl => DslStrategy`；更新 `tests/Feature/StrategyWhitelistIntegrityTest.php`：任一套餐 `strategies ⊆ config('strategy.implemented')` 结构性断言（SC-008）
  - 验收：断言绿；`StrategyRegistry::make('custom_dsl', ...)` 返回 `DslStrategy`（FR-015）
- [x] T033 [US5] `tests/Feature/Billing/DslPlanGateTest.php`：`pro`/`enterprise` 租户可创建 DSL 实例，`free` 被拒（US5-1）
  - 验收：T033 绿（FR-015/016）；**文件置于 `tests/Integration/Billing/` 与既有 `PlanStrategyGateTest` 同层（可用 `RefreshDatabase`），避免同义测试分层漂移**
- [x] T034 [US5] `tests/Integration/Strategy/DslBillingBreakerTest.php`（`@group redis`）：配额耗尽 DSL 开仓交易所零调用且计数不变；熔断 OPEN 下平仓 100% 放行、开仓 100% 拦截；受理结算/拒绝冲正复用既有闸门（SC-007）
  - 验收：T034 绿（FR-013/014 / constitution V）

**Checkpoint**: US5 门控与计费/风控接线完成。

---

## Phase 8: Polish & Cross-Cutting Concerns

- [x] T035 新增 `app/Console/Commands/VerifyDsl.php`（`poc:verify-dsl`）：离线全链路断言 SC-001~010（解析→编译→沙箱→生命周期→等价→预览→门控），输出结构化结果
  - 验收：`php artisan poc:verify-dsl` 全绿（SC-001~010）
- [x] T036 [P] README 同步：新增 P7（自定义 DSL）完成区块；技术债索引 B2（DslParser/DslStrategy 骨架抛异常）清零
  - 验收：README P7 行与技术债 B2 状态更新
- [x] T037 [P] `docs/pitfalls` 如有新踩坑追加（自研 YAML 子集边界、cross prev 口径、step budget 等）
  - 验收：无新坑则不动；有则条目齐全
- [x] T038 运行 `quickstart.md` 验证（离线 fixture 全路径）+ 全量回归 `php artisan test`
  - 验收：既有用例零回归；新增用例全绿（constitution VI）

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup（Phase 1）**: 无依赖，立即可开
- **Foundational（Phase 2, WS1+WS2）**: 依赖 Setup，**阻塞所有 US**
- **US1（Phase 3）**: 依赖 Phase 2；P1，MVP
- **US2（Phase 4）**: 依赖 Phase 2（沙箱内核）；P1
- **US3（Phase 5）**: 依赖 Phase 2 + US1（需实跑决策序列）
- **US4（Phase 6）**: 依赖 Phase 2 + US1（预览复用解释器与生命周期）
- **US5（Phase 7）**: 依赖 US1 完成（实例可建）；白名单/结构性断言可与实现并行
- **Polish（Phase 8）**: 依赖全部目标 US

### Within Each User Story

- 测试先写并失败 → 实现 → 断言绿（constitution I）
- 契约/DTO 先于服务；核心实现先于集成；story 完成再进下一个

### Parallel Opportunities

- T002/T003/T007/T008 可并行（不同文件）
- US1 测试 T010–T014 中 T010–T013 可并行（T014 依赖 T013 闭环）
- US4 内 T025/T028 可并行；US5 内 T030/T031 可并行后 T032–T034 串行
- Polish 内 T036/T037 可并行

---

## Parallel Example: Foundational（Phase 2）

```bash
# 并行落盘 AST / 诊断 / 求值契约：
Task: "新建 app/Domain/Strategy/Custom/Dsl/{DslProgram,DslMeta,RuleNode,ConditionNode,OperandNode,DslRisk}.php"   # T002
Task: "新建 app/Domain/Strategy/Custom/Dsl/{DslDiagnostic,DslValidationException}.php"                            # T003
Task: "新建 app/Domain/Strategy/Custom/Dsl/{DslInterpreter,DslEvalContext,DslEvalResult,DslSignal,DslExecutionException}.php"  # T007
Task: "实现 app/Domain/Strategy/Custom/Dsl/IndicatorSnapshotBuilder.php"                                          # T008
```

---

## Implementation Strategy

### MVP First（仅 US1 + 安全红线 US2）

1. Phase 1 Setup → 2. Phase 2 Foundational（阻塞项）→ 3. Phase 3 US1 → 4. Phase 4 US2
5. **STOP & VALIDATE**：US1 闭环 + 沙箱红线独立验收
6. 可演示：YAML 定义 → 装载 → 实跑下单

### Incremental Delivery

1. Setup + Foundational → 内核就绪
2. US1 → 自测 → 交付（MVP）
3. US2 → 红线可证
4. US3（等价）/ US4（编辑器+预览）/ US5（门控+计费）→ 各自独立验收后逐步交付
5. 每个 WS 完成即 commit（`feat(spec-006): WSx …`，中文 message）+ push origin/main（plan.md §5）

---

## Notes

- [P] = 不同文件、无未完成依赖
- 文档同步随 WS 同步进行（README 技术债 B2 清零）
- 避免：含糊任务、同文件冲突、破坏独立性的跨 story 依赖
- 核对宪法：II（bcmath/UTC/`clientOrderId` 模板）、III（行情协程禁阻塞）、IV（恢复幂等一次性标志）、V（计费不可降级 + 熔断只停开新仓）、VI（测试隔离）、VII（PSR-4）
