# Feature Specification: 合约原生账实对账（销技术债 A5）

**Feature Branch**: `010-futures-reconciliation`

**Created**: 2026-10-22

**Status**: Implemented（2026-10-22，T001-T025 全部完成。测试统计：`--group=redis` 全量 91 passed / 465 assertions——FuturesReconciliationTest 11 用例（含 hedge 合成）、ReconciliationServiceTest 10 用例（含护栏反转）、BinanceFuturesAdapterTest 17 用例（含 getPositions 覆写）；现货零回归。quickstart.md 11 场景矩阵逐项有用例对应；testnet 手动场景无条件执行，以自动化层为准。实施提交号见本提交补记）

**Plan 产物**：[research.md](research.md) / [data-model.md](data-model.md) / [contracts.md](contracts.md) / [quickstart.md](quickstart.md) / [plan.md](plan.md) / [tasks.md](tasks.md)（25 任务，6 阶段）

**Input**: User description: "合约（futures）策略实例的原生账实对账：reconcile:run 对 futures 实例改用 ExchangeAdapter::getPosition(symbol) 取得的带方向签名持仓量，与 strategy_orders 成交签名净额（buy=+、sell=-）比对，沿用现有 ok/drift/critical/unknown 四级分级、容差配置（qty/quote 容差与 critical_quote 阈值）、「只报告+暂停（paused_by_reconcile）绝不自动改账」铁律、连续 unknown 计数告警与每 5 分钟调度；支持单向持仓与双向持仓（hedge mode）语义；成功后解除 b1edb62 护栏对 futures 实例的排除（由新口径接管，现货口径保持不变）"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - 合约实例按持仓口径对账 (Priority: P1)

运行中的合约策略实例每 5 分钟被自动对账一次：以交易所侧「带方向签名持仓量」比对账本侧「应持仓量」，差异按既有 ok / drift / critical / unknown 四级分级落对账报告；判 critical 时实例被自动暂停并告警，等待人工核查处理。全程系统不做任何自动改账、补单或平仓。

**Why this priority**: 这是技术债 A5 的本体——护栏（b1edb62）只是把合约实例排除在对账外避免误杀，原生持仓口径才真正让合约用户的账实漂移可见、可拦截；没有它，合约实例被强平或漏单造成的账实不符完全无感知。

**Independent Test**: 构造一个 running 合约实例，分别注入「交易所持仓与账本净额一致」「超容差且交易所多仓」「交易所无仓而账本有仓」「取持仓失败」四类场景，断言报告分级、暂停与告警行为。

**Acceptance Scenarios**:

1. **Given** running 合约实例且交易所签名持仓与账本净额之差在容差内，**When** 对账周期执行，**Then** 报告落 ok，实例运行不受打扰。
2. **Given** 差值超容差且方向为「交易所多仓」（实际多于账本），**When** 对账执行，**Then** 分级 drift，实例不暂停，报告提示人工核查。
3. **Given** 方向为「账本有、交易所无」（如强平后仓位消失而账本仍记持仓）或差值等值超严重阈值，**When** 对账执行，**Then** 分级 critical，实例进入 paused_by_reconcile 并发出告警；系统不做任何自动改账/补单/平仓，恢复运行须人工显式执行。
4. **Given** 取交易所持仓失败（网络/凭据），**When** 对账执行，**Then** 报告记 unknown 且实例不暂停；连续 unknown 达配置次数后才告警，恢复非 unknown 后计数清零。
5. **Given** 参考价获取失败但持仓获取成功，**When** 对账执行，**Then** 等值容差与严重阈值判定退化，仅按数量容差与方向完成分级，不阻断对账。

---

### User Story 2 - 调度统一接管现货与合约，解除临时护栏 (Priority: P1)

对账调度的扫描范围从「仅现货实例」（护栏态）恢复为「全部运行中/已暂停实例」：现货实例沿用既有余额资产口径，合约实例走本特性的持仓口径，同一调度入口按账户市场类型各走各的口径；现货行为零回归。

**Why this priority**: 护栏是临时排除措施，本特性交付完成的标志就是合约实例回到对账覆盖之内；没有调度接管，US1 的口径无人执行。与 US1 同为交付闭环的必要条件。

**Independent Test**: 准备现货、合约实例各一（均 running），执行一次对账调度，断言两者均产出报告，且现货实例的比对字段语义与护栏前完全一致。

**Acceptance Scenarios**:

1. **Given** 现货与合约实例并存且均 running，**When** 调度执行，**Then** 两类实例各自产出对账报告，口径互不串扰。
2. **Given** 既有现货实例与既有对账测试套件，**When** 本特性上线后执行调度与测试，**Then** 现货口径（余额资产 vs 快照账本 + 成交净额）行为与护栏前一致，测试全部通过。
3. **Given** 实例处于 paused_by_breaker（熔断暂停），**When** 对账执行，**Then** 照常产出报告，但对账绝不覆盖熔断暂停态。

---

### User Story 3 - 持仓方向与双向持仓（hedge）模式语义 (Priority: P2)

合约持仓带方向：做多持仓量为正、做空为负；账本侧按成交流水 buy=+、sell=− 签名合成应持仓，两者在统一签名坐标下比对。单向持仓模式下直接比对；双向持仓模式下交易所对同一交易对返回多腿持仓，系统将多腿按方向签名合成为净持仓后与账本净额统一比对（净额口径与账本严格同构，不分腿比对）。

**Why this priority**: 方向语义是正确性前提——若把做空持仓误判为「无仓」，将造成大规模 critical 误暂停；但平台当前合约实例以单向模式为主，双向模式的完整支持列为 P2。

**Independent Test**: 分别注入 long / short / 无仓 / 双向多腿四档交易所持仓应答与对应账本净额，断言签名差计算与分级结果。

**Acceptance Scenarios**:

1. **Given** 交易所返回做空持仓（负持仓量）且账本净额为负，**When** 对账执行，**Then** 差值按签名坐标计算，方向与量级一致时判 ok。
2. **Given** 账本净额与交易所持仓符号相反（方向做反），**When** 对账执行，**Then** 差值量级为两倍持仓，必超严重阈值判 critical。
3. **Given** 双向持仓模式且多腿均有持仓，**When** 对账执行，**Then** 多腿签名合成净持仓后与账本净额统一比对并完成分级。

---

### Edge Cases

- 交易所无任何持仓（flat）而账本净额非零 → 「账本有、交易所无」→ critical（典型强平/手动平仓场景），暂停 + 告警，绝不自动改账。
- 交易所持仓非零而账本净额为零 → 「交易所多仓」→ drift（不暂停，提示人工核查是否有账本外建仓）。
- 在途未成交委托不纳入持仓比对；部分成交按已成交量计入。
- 杠杆调整、追加保证金、强平价变化不影响持仓量比对（属仓位级风控职责，不在对账范围）。
- 参考价失败时降级为仅数量维度分级（沿用现货既有降级先例）。
- 同一实例同周期重复执行对账 → 报告按周期桶幂等覆盖，不产生重复行、不重复告警（告警去重冷却沿用现货配置）。

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: 对账调度 MUST 覆盖 market ∈ {spot, futures} 的 running / paused_by_breaker / paused_by_reconcile 实例；stopped / error / starting 不打扰。
- **FR-002**: 对 futures 实例，交易所侧比对量 MUST 为持仓查询得到的带方向签名持仓量（long 为正 / short 为负 / 无仓为 0），MUST NOT 使用余额资产（保证金）口径。
- **FR-003**: 对 futures 实例，账本侧应持仓 MUST 由该实例全部已成交流水按 buy=+qty、sell=−qty 签名合成，采用单维度比对口径；策略持仓快照 MUST NOT 参与分级（60s 双写滞后窗口会引入误报），可作为报告明细记录。
- **FR-004**: 差异分级 MUST 复用既有四级状态机与容差配置（数量容差、等值容差、等值严重阈值）；差值方向语义与现货一致（实际 − 账本，负值为「账本有、交易所无」判 critical）。
- **FR-005**: critical MUST 触发实例暂停（paused_by_reconcile）与告警；系统 MUST NOT 自动改账、补单或平仓；恢复运行 MUST 由人工显式执行；对账暂停 MUST NOT 覆盖熔断暂停态。
- **FR-006**: 交易所侧取数失败 MUST 记 unknown 且不暂停；连续 unknown 达配置次数 MUST 告警；恢复非 unknown 后连续计数 MUST 清零。
- **FR-007**: 参考价获取失败 MUST NOT 阻断对账：等值容差与严重阈值判定退化，仅按数量容差与方向完成分级。
- **FR-008**: 双向持仓模式下，交易所侧多腿持仓 MUST 先按方向签名合成为净持仓（long 为正、short 为负），再与账本签名净额统一比对分级；MUST NOT 分腿独立分级。
- **FR-009**: 对账报告 MUST 落库且 diff 明细包含：市场类型、持仓方向语义、交易所签名持仓、账本签名净额、差值、参考价、容差与分级结果；同实例同周期 MUST 幂等覆盖。
- **FR-010**: 现货实例对账行为 MUST 零回归（扫描口径之外的全部行为：比对维度、分级、暂停、告警、报告字段）。
- **FR-011**: 对账调度频率与周期桶语义 MUST 与现货一致（默认 5 分钟周期桶，可命令行覆盖）。

### Key Entities *(include if feature involves data)*

- **对账报告**：每实例每周期一行，含最终状态、diff 明细、周期桶；futures 的 diff 明细在既有结构上扩展市场类型与持仓方向/签名量字段（现货字段保持不变）。
- **交易所持仓快照**：带方向签名持仓量、入场价、标记价、杠杆；仅签名持仓量与（经由参考价或标记价的）等值换算参与比对。
- **成交流水**：实例全部已成交委托的方向（buy/sell）与数量，签名净额合成的权威数据源。

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 运行中合约实例对账覆盖率 100%——每个对账周期内，每个 running / paused 状态的合约实例均产出对账报告。
- **SC-002**: 误暂停归零——现货口径对合约实例的误判 critical 不再发生；合约口径下容差内差异 0 例触发暂停。
- **SC-003**: 真实账实差异从发生到产出 critical/drift 报告 ≤ 1 个对账周期（默认 5 分钟），critical 告警在当周期发出。
- **SC-004**: 现货对账回归 100% 通过（既有对账测试套件无失败）。
- **SC-005**: 做空、无仓、双向多腿等方向语义用例的分级正确率 100%。

## Assumptions

- 首期覆盖已实现持仓查询能力的合约交易所适配器（Binance U 本位永续）；后续其他合约适配器遵循同一接口契约即自动获得对账能力，无需改对账本体。
- 容差与严重阈值配置首期与现货共用同一组配置；合约大名义价值场景如需独立阈值，列为后续调优项，不在本期范围。
- 杠杆、强平价、保证金率、未实现盈亏不纳入对账维度（仓位级风控已覆盖）。
- 账户持仓模式（单向/双向）以交易所账户当前设置为准；本平台不主动切换持仓模式。
- 对账仅覆盖 U 本位线性合约的 base 资产持仓量维度；保证金余额、全/逐仓模式差异不纳入比对。
- 账本侧采用成交净额单维度口径（已澄清）：策略持仓快照不参与分级，避免 60s 双写滞后窗口引入误报。
- 双向持仓采用合成签名净持仓比对（已澄清）：与账本净额严格同构，不为分腿检测扩展订单数据模型。
