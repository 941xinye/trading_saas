# Implementation Plan: 自定义 DSL 策略引擎（P7）

> spec 006 plan 阶段实施计划。沿用 P1–P5 + spec 001-005 的 SDD 流程：测试先行、一子项一提交、文档同步。所有工作流回溯 spec.md 的 FR-001~017 / SC-001~010 / US1~US5。

## 1. 工作流拆解

按依赖关系分为 6 个工作流（US 映射），建议按 WS1→WS6 顺序执行：

```
WS1: DSL 子集解析器 + AST + 诊断（FR-001/002/004/005，US1/US4）
  └─ WS2: 沙箱求值器 + 限额（FR-003/004/008/017，US2）
       └─ WS3: DslStrategy 全生命周期 + 状态/恢复 + 幂等（FR-006/009/010/013，US1）
            ├─ WS4: 注册/配置/白名单门控 + 结构性断言（FR-015/016，US5）
            └─ WS5: Filament 编辑器 + 校验/试装载（FR-011/016，US4）
                 └─ WS6: 回测预览 + 内建策略等价性 + 冒烟验证（FR-007/012，US3）
```

依赖说明：WS1 提供 AST（WS2/WS3 消费）；WS2 是 WS3 的求值内核；WS3 落地后 WS4（门控）与 WS5（编辑器）可并行；WS6 依赖 WS2/WS3（等价性）+ WS5（预览入口）。

## 2. 各工作流范围

### WS1: DSL 子集解析器 + AST + 诊断

| 产出 | 文件 |
|---|---|
| `DslYamlParser`（受限子集解析 + 预扫描拒绝 + 行号） | `app/Domain/Strategy/Custom/Dsl/DslYamlParser.php` |
| `DslCompiler`（顶层键白名单/四段/枚举/类型/范围校验） | `app/Domain/Strategy/Custom/Dsl/DslCompiler.php` |
| 填充 `DslParser`（编排，返回 `DslProgram`） | `app/Domain/Strategy/Custom/DslParser.php` |
| AST：`DslProgram`/`DslMeta`/`RuleNode`/`ConditionNode`/`OperandNode`/`DslRisk` | `app/Domain/Strategy/Custom/Dsl/{DslProgram,DslMeta,RuleNode,ConditionNode,OperandNode,DslRisk}.php` |
| `DslDiagnostic` / `DslValidationException`（携带诊断数组） | `app/Domain/Strategy/Custom/Dsl/{DslDiagnostic,DslValidationException}.php` |
| `config/strategy.php` 新增 `dsl` 块（解析限额字段） | `config/strategy.php` |

**验收**：
- Unit：子集解析（mapping/sequence/scalar/注释/中文注释）+ 预扫描拒绝（锚点/别名/tag/多文档/flow/Tab/重复键）各自定位行号。
- Unit：结构校验拒绝（未知顶层键/未知 op/四段缺失/类型不符/period≤0/qty≤0）+ 超限（字节/行/深度/规则数）。
- Unit：合法示例（contracts §10）编译为期望 AST。

### WS2: 沙箱求值器 + 限额

| 产出 | 文件 |
|---|---|
| `DslInterpreter` 接口 + `WhitelistInterpreter` 实现 | `app/Domain/Strategy/Custom/Dsl/DslInterpreter.php` + `.../Dsl/WhitelistInterpreter.php` |
| `DslEvalContext` / `DslEvalResult` / `DslSignal` | `app/Domain/Strategy/Custom/Dsl/{DslEvalContext,DslEvalResult,DslSignal}.php` |
| `DslExecutionException`（超预算中断，不静默） | `app/Domain/Strategy/Custom/Dsl/DslExecutionException.php` |
| 指标快照构建（窗口=`max period+1`、prev/shifted、NaN→null） | `app/Domain/Strategy/Custom/Dsl/IndicatorSnapshotBuilder.php` |

**核心逻辑**：
- 判别式 AST 的 `match` 求值（比较/交叉/逻辑/恒真），白名单原生。
- 每 bar 计算一次指标快照（复用 `IndicatorCalculator::compute()`，FR-008）。
- NaN/null ⇒ 条件 false（不触发）。
- `eval_step_budget`（默认 500）与每 bar 时间预算；超限抛 `DslExecutionException`。

**验收**：
- Unit：四类条件真值表（含 NaN→null 不触发、窗口不足不触发）。
- Unit：`cross` 的 prev（ma/macd 内置 prev；rsi/boll/atr/price 用 shifted snapshot）。
- Integration：红线语料库（`eval`/`exec`/文件/网络/反射/未知原语）在运行期被拒（SC-003 运行侧）。
- Unit：超 step budget 中断并记录（SC-009）。

### WS3: DslStrategy 全生命周期 + 状态/恢复 + 幂等

| 产出 | 文件 |
|---|---|
| 填充 `DslStrategy`（onTick/onBar/onFill/shouldOpen/shouldClose） | `app/Domain/Strategy/Custom/DslStrategy.php` |
| `getState`/`restoreState` 覆写（`SCHEMA_VERSION=2`、`position_snapshot.dsl`、v1→v2 迁移） | 同上 |
| `RecoverablePendingOrders` 实现（pending cid + applyRecoveredOrder） | 同上 |
| `qty_pct` bcmath 换算（开仓：`bcdiv(bcmul(capital, pct, 8), refPrice, 8)`；平仓：`bcmul(positionQty, pct, 8)`） | 同上 |

**关键约束**：
- 装载构造期解析编译（非法即抛，FR-005，不进运行态）。
- 下单经既有 `OrderExecutor::place()` 幂等路径（`clientOrderId` 模板不变，FR-006/009）。
- 熔断语义：开仓/加仓 `check('open')` 拦截，平仓/减仓永远放行（FR-013，constitution V）。
- 恢复幂等守卫用独立一次性标志（`recovered`，constitution IV / FR-010）。
- 快照高版本拒绝启动；`compiled_hash` 校验脚本未被改写。

**验收**：
- Unit：生命周期（onBar→pending→shouldOpen/shouldClose）+ 动作枚举映射。
- Unit：state(v2) round-trip + v1→v2 迁移 + 高版本拒绝。
- Unit：`qty_pct` 换算边界（极小/极大/尾数，SC-010）。
- Integration（RecordingAdapter）：端到端 onBar→shouldOpen→place→onFill 闭环（SC-001）。
- Integration：崩溃恢复（pending 单核实 + 状态/指标尾部重放，SC-005）。

### WS4: 注册/配置/白名单门控 + 结构性断言

| 产出 | 文件 |
|---|---|
| 确认 `StrategyRegistry` 映射 `custom_dsl => DslStrategy`（已存在，验证） | `app/Domain/Strategy/StrategyRegistry.php` |
| `config/strategy.php` `implemented` 追加 `custom_dsl` | `config/strategy.php` |
| `config/plans.php` pro/enterprise 追加 `custom_dsl`（free 不变） | `config/plans.php` |
| 白名单结构性断言（任一套餐 strategies ⊆ implemented） | `tests/Feature/StrategyWhitelistIntegrityTest.php` |
| 门控测试（pro/enterprise 可建 DSL、free 被拒） | `tests/Feature/Billing/DslPlanGateTest.php` |

**验收**：
- 结构性断言通过（SC-008）。
- pro/enterprise 租户可创建 DSL 实例，free 被拒（US5-1）。
- 计费闸门复用验证：配额耗尽 DSL 开仓交易所零调用；熔断 OPEN 下平仓放行、开仓拦截（FR-014/SC-007；`@redis` 组）。

### WS5: Filament 编辑器 + 校验/试装载

| 产出 | 文件 |
|---|---|
| DSL 编辑器（YAML 编辑 + 即时校验 + 试装载 action） | `app/Filament/Admin/Resources/StrategyInstanceResource.php`（含对应 Pages） |
| `DslValidator` 契约 + 实现（返回带 行/字段 诊断） | `app/Domain/Strategy/Custom/Dsl/DslValidator.php`（+ 实现） |
| 保存草稿（写 `params.dsl_script`，实例级快照） | 同上 Resource |
| 租户隔离（Resource 走 `TenantScope`） | 同上 Resource |

**验收**：
- 含错误 DSL 触发校验 → 返回可读定位错误，保存/试装载被阻断（US4-1 / FR-011）。
- 试装载仅编译校验，不启动实例。
- 跨租户草稿不可见（US4-3 / FR-016）。

### WS6: 回测预览 + 内建策略等价性 + 冒烟验证

| 产出 | 文件 |
|---|---|
| `DslPreviewService` 接口 + `HistoricalPreviewService` 实现 + `DslPreviewResult` | `app/Domain/Strategy/Custom/Dsl/Preview/{DslPreviewService,HistoricalPreviewService,DslPreviewResult}.php` |
| 等价性 fixture（SpotDca / SpotGrid parity） | `tests/Integration/Strategy/DslEquivalenceTest.php` |
| 预览一致性测试（预览信号序列 == 实跑信号序列） | `tests/Integration/Strategy/DslPreviewConsistencyTest.php` |
| 冒烟命令 `poc:verify-dsl`（离线全链路断言） | `app/Console/Commands/VerifyDsl.php` |

**核心逻辑**：预览分页 `HistoricalQuery(KlineBar, symbol, interval, from, to)`（默认 30 天），行 → `ExchangeKline` 后经**同一** `DslInterpreter` 回放；输出信号序列 + 简易资金曲线 + 统计，注明「未模拟滑点/手续费」。

**验收**：
- 等价性：SpotDca（`{op:true}`+on_bar）与 SpotGrid（小网格 `{price:close}` vs `{const:level}`）决策序列（触发 bar/side/qty）100% 一致（SC-002）。
- 预览一致性：同 DSL 预览与实跑信号序列一致（SC-006）。
- `poc:verify-dsl` 全绿（SC-001~010 离线断言）。

## 3. 测试矩阵

| 层 | 文件 | 分组 | 覆盖 FR |
|---|---|---|---|
| Unit | `DslYamlParserTest` | default | FR-001/002/004 |
| Unit | `DslCompilerTest` | default | FR-001/002/005 |
| Unit | `DslParserTest`（编排 + 示例编译） | default | FR-002/005 |
| Unit | `DslInterpreterTest` | default | FR-003/004/008 |
| Unit | `DslIndicatorSnapshotTest` | default | FR-008 |
| Unit | `DslStrategyLifecycleTest` | default | FR-006/009/013 |
| Unit | `DslStateMigrationTest`（v1→v2、高版本拒绝） | default | FR-010 |
| Unit | `DslPrecisionTest`（qty_pct 边界） | default | FR-009/SC-010 |
| Integration | `DslSandboxRedlineCorpusTest` | default | FR-003/004/017（SC-003） |
| Integration | `DslOrderExecutorTest`（RecordingAdapter） | default | FR-006/009（SC-001） |
| Integration | `DslCrashRecoveryTest` | redis | FR-010（SC-005） |
| Integration | `DslEquivalenceTest`（SpotDca/SpotGrid parity） | default | FR-007（SC-002） |
| Integration | `DslPreviewConsistencyTest` | default | FR-012（SC-006） |
| Integration | `DslBillingBreakerTest`（配额零调用、OPEN 放行） | redis | FR-013/014（SC-007） |
| Feature | `StrategyWhitelistIntegrityTest` | default | FR-015（SC-008） |
| Feature | `DslPlanGateTest` | default | FR-015/016 |
| Feature | `DslEditorValidationTest` | default | FR-011/016 |
| Smoke | `poc:verify-dsl` | smoke | SC-001~010 |

> constitution VI：PHPUnit 12 静态 `#[DataProvider]`；Redis 用例打 `@group redis`；持久化字段走 Domain 层公开方法；`RefreshDatabase` 用于 Feature；tearDown 精确清理共享 TTL 键（dedup/冷却桶）且先于 `parent::tearDown()`。

## 4. 关键风险与缓解

| 风险 | 缓解 |
|---|---|
| 自研 YAML 子集与用户预期偏差（写了完整 YAML 被拒） | 文档化子集 + 精确定位诊断 + 语料库测试；拒绝时给行号与原因 |
| 沙箱逃逸（未知原语/动态求值） | 判别式 AST + 白名单 `match` + 解析/运行双处红线语料库（SC-003） |
| `cross` 的 prev 缺失导致误判 | ma/macd 复用内置 prev；rsi/boll/atr/price 用 shifted snapshot（每 bar 至多一次） |
| 指标窗口不足误触发 | NaN→null ⇒ 条件 false；窗口不足即不触发（spec Edge） |
| 预览与实跑偏差 | 共用同一解释器/指标路径（SC-006）+ 显式假设声明 |
| 等价口径争议 | 口径固定为 (触发 bar, side, qty)，排除撮合/滑点（spec Assumptions） |
| schemaVersion 演进误解释旧脚本 | 高版本拒绝启动；v1→v2 迁移分支显式 |
| 单脚本求值成本拖垮行情协程 | step/time budget + 规模上限（FR-004/SC-009），超限中断不静默 |
| 白名单超卖 | 结构性断言 strategies ⊆ implemented（SC-008） |
| 预览阻塞 IO 入行情协程 | 预览走编辑器请求路径（非行情协程），分页受 pageSize 约束 |
| 崩溃恢复重复下单 | 复用 `clientOrderId` 幂等 + `RecoverablePendingOrders` + 独立一次性标志（constitution IV） |

## 5. 提交节奏

按工作流粒度提交，每个提交包含：实现 + 测试 + 文档同步（README 技术债索引 B2 清零）。

1. `feat(spec-006): WS1 DSL 子集解析器 + AST + 诊断`
2. `feat(spec-006): WS2 沙箱求值器 + 限额`
3. `feat(spec-006): WS3 DslStrategy 全生命周期 + 状态/恢复 + 幂等`
4. `feat(spec-006): WS4 注册/配置/白名单门控 + 结构性断言`
5. `feat(spec-006): WS5 Filament 编辑器 + 校验/试装载`
6. `feat(spec-006): WS6 回测预览 + 内建策略等价性 + 冒烟命令`
