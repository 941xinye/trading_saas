# Tasks: 合约原生账实对账（销技术债 A5）

**Input**: Design documents from `/specs/010-futures-reconciliation/`

**Prerequisites**: plan.md / spec.md（3 用户故事）/ research.md（R1-R5）/ data-model.md / contracts.md（C1-C4）/ quickstart.md（11 场景矩阵）

**Tests**: 本特性含测试任务——spec 验收场景与 quickstart.md 测试矩阵明确要求；测试先行（先写失败测试再实现），遵循 Constitution VI 隔离规则。

**Organization**: 按用户故事分阶段。注意：US2（护栏移除）依赖 US1（futures 口径须先接管），不可并行乱序。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行（不同文件、无未完成依赖）
- **[Story]**: US1/US2/US3 映射 spec.md 用户故事
- 金额/数量一律 bcmath 字符串；时间 UTC（Constitution II）

## Phase 1: Setup（基线确认）

**Purpose**: 动手前锁定现货回归基线，后续任何红测试可归因

- [x] T001 跑基线：`php artisan test --group=redis --filter=ReconciliationServiceTest` 与 `php artisan test --filter=BinanceFuturesAdapterTest` 全绿并记录用例数（Redis 未起则 WSL 先 `redis-server --daemonize yes --port 6379`）——✅ 基线：ReconciliationServiceTest 10 用例（9 常规+1 redis）/ BinanceFuturesAdapterTest 14 用例（12 常规+2 redis）全绿

---

## Phase 2: Foundational（适配器契约扩展，阻塞所有故事）

**Purpose**: `getPositions` 多腿契约落地，默认实现保证 5 个既有适配器与测试 fake 零回归（contracts.md C1）

**⚠️ CRITICAL**: 契约未落地前 US1/US3 无法取持仓

- [x] T002 [P] 在 tests/Domain/Exchange/BinanceFuturesAdapterTest.php 新增失败测试：`getPositions` 默认实现包装语义——单腿持仓返回单元素数组、无持仓返回空数组（调 `getPosition` 路径，断言元素即 `getPosition` 返回值）
- [x] T003 [P] 在 app/Domain/Exchange/Contracts/ExchangeAdapter.php 新增 `getPositions(string $symbol): array` 方法签名与文档块（`@return ExchangePosition[]` 仅非零腿；`@throws Throwable` 取数失败；注明现货恒空数组、hedge 可多腿）
- [x] T004 在 app/Domain/Exchange/Adapters/AbstractExchangeAdapter.php 实现 `getPositions` 默认实现：`$p = $this->getPosition($symbol); return $p === null ? [] : [$p];`（依据 T003 契约）
- [x] T005 在 tests/Fakes/FakeExchangeAdapter.php 补 `getPositions` 实现（该类直接 implements ExchangeAdapter，不继承 Abstract）：返回可注入的腿数组，缺省空数组；同步加 `setPositions(array $legs)` 注入辅助（另补 `throwOnGetPositions` 异常注入旗标；其余 3 处直接实现接口的桩同步补齐：RecordingAdapter、OrderQuotaGateTest 匿名类、PocVerifyBillingCommand 匿名类）
- [x] T006 跑 T002 测试转绿 + 全量适配器测试（Okx/Bybit/Bitget/BinanceFutures）确认接口扩展零破坏——✅ Adapter 过滤 125 passed（T002 两用例绿）；StrategyExchangeCompatibilityTest 5 passed；OrderQuotaGateTest 3 passed。（注：BacktestNoExchangeCallTest 因继承 PHPUnit TestCase 无 Laravel app 而 error，被 --filter=Adapter 名称误匹配，与本特性无关的存量问题）

**Checkpoint**: 契约可用，所有适配器与 fake 编译通过、测试全绿

---

## Phase 3: User Story 1 - 合约实例按持仓口径对账 (Priority: P1) 🎯 MVP

**Goal**: running 合约实例每周期以「交易所签名净持仓 vs strategy_orders 成交签名净额」单维度比对，ok/drift/critical/unknown 分级落报告，critical 暂停+告警，铁律不动

**Independent Test**: fake adapter 注入持仓/异常应答，断言报告分级、实例状态与 risk_notifications（quickstart.md 矩阵场景 1/2/3/4/5/7/8/9/11）

### Tests for User Story 1（先写，确认失败）

- [x] T007 [P] [US1] 新建 tests/Integration/Reconciliation/FuturesReconciliationTest.php（`#[Group('redis')]` + ChecksRedisAvailability + RefreshDatabase；setUp/tearDown 按逻辑名精确清理 unknown streak 与 dedup 键，tearDown 外部清理先于 parent::tearDown()——Constitution VI）：用例 ① ok-long（legs=[long 0.5] vs orders net=+0.5 → ok 不暂停）② ok-short（short 0.5 vs net=−0.5 → ok）③ drift（交易所 +0.6 vs 账本 +0.5 超容差 → drift 不暂停）
- [x] T008 [P] [US1] 同文件用例 ④ critical-flat（legs=[] vs 账本 +0.5 → critical，实例 paused_by_reconcile，risk_notifications 落 queued）⑤ critical-反向（交易所 −0.5 vs 账本 +0.5，diff=−1.0 → critical）⑥ unknown（getPositions 抛异常 → unknown 不暂停；连跑 3 次触发 unknown_streak 告警；第 4 次正常后 streak 清零）
- [x] T009 [P] [US1] 同文件用例 ⑦ ticker 降级（ticker 抛异常+持仓正常 → 按数量容差分级不阻断，diff_json.price='0'）⑧ breaker 正交（paused_by_breaker 实例 + critical 场景 → 出报告但状态不覆盖）⑨ 幂等（同 period 跑两次 → 报告一行、告警不重复）⑩ diff_json futures 形态断言（含 `market='futures'`、`position.legs[]`、`exchange_signed_qty`、`orders_net_qty`，金额字段恒字符串——data-model.md schema）

### Implementation for User Story 1

- [x] T010 [US1] 在 app/Domain/Reconciliation/ReconciliationService.php 的 `runForInstance()` 加 market 分流：buildAdapter 时顺带取 `exchange_accounts.market`（避免二次查询，research R1）；`futures` → 新私有方法 `reconcileFuturesPosition()` 返回 `[status, diff]` 后汇回主流程共用 upsertReport/resetUnknownStreak/handleCritical；`spot` 路径代码零改动
- [x] T011 [US1] 同文件实现 `reconcileFuturesPosition()`（contracts.md C3 伪码契约）：`$adapter->getPositions($symbol)` 取腿（Throwable → 走既有 recordUnknown 返回，但须带 market 标识：recordUnknown 扩可选 `market` 参数写入 diff_json，满足 contracts C4「unknown 时 diff 含 market」）；签名合成 `Σ(side==='short' ? bcsub('0',size,12) : size)` 用 bcadd 12 位中间精度；`readOrderTotals()` 取 net_qty；`diff = exchangeNet − ordersNet`（bcsub 12 位）；ticker 失败降级 price='0'；`classifyDimension` 单维度分级；diff 数组按 data-model.md futures 形态组装（`market/asset/price/position{exchange_signed_qty,orders_net_qty,diff,status,legs[]}/tolerance/critical_quote`）；保留 writeBalanceSnapshot 调用（保证金权益快照）
- [x] T012 [US1] 跑 T007-T009 全部转绿 + T001 基线用例保持绿（现货零回归 FR-010）——✅ FuturesReconciliationTest 10 passed（72 assertions，7 红转绿 + 3 外部行为巧合绿）；ReconciliationServiceTest 10 passed（49 assertions）现货零回归。（另：I1 修复先行落地——recordUnknown 已带 market 参数默认 spot，现货 unknown 用例补 C4 断言）

**Checkpoint**: US1 独立可用——单向模式 futures 实例对账全链路正确（hedge 多腿在 US3）

---

## Phase 4: User Story 2 - 调度统一接管现货与合约，解除临时护栏 (Priority: P1)

**Goal**: `reconcileableInstanceIds()` 恢复全市场扫描，现货 diff_json 补 `market='spot'` 标识，护栏测试反转，现货行为零回归

**Independent Test**: spot+futures 实例并存跑一次调度两者出报告；ReconciliationServiceTest 全绿（quickstart.md 场景 10 + 回归清单）

**⚠️ 依赖**: 必须先完成 US1——futures 口径未接管前移除护栏会把 futures 实例错送现货口径误暂停

### Tests for User Story 2（先写，确认失败）

- [x] T013 [P] [US2] 在 tests/Integration/Reconciliation/ReconciliationServiceTest.php 反转护栏用例 `test_reconcileable_instance_ids_excludes_futures_market` 为「futures 实例被纳入扫描」：market=futures 的 running 实例出现在 reconcileableInstanceIds 结果中；stopped/error/starting 实例仍排除（无论 spot/futures）
- [x] T014 [P] [US2] 同文件补现货 diff_json 含 `market='spot'` 的断言（若既有用例对 diff 全等断言，先同步更新预期——测试维护非行为变更，research 风险表）

### Implementation for User Story 2

- [x] T015 [US2] 在 app/Domain/Reconciliation/ReconciliationService.php 的 `reconcileableInstanceIds()` 删除 `->where('ea.market', 'spot')`（保留 join 与状态过滤，research R5），并更新方法 docblock：移除护栏说明，改为「全市场扫描，口径按 market 分流」
- [x] T016 [US2] 同文件现货 diff 数组顶层补 `'market' => 'spot'`（唯一现货路径改动，FR-009/C4）
- [x] T017 [US2] 跑 T013/T014 转绿 + ReconciliationServiceTest 与 FuturesReconciliationTest 全量绿（FR-010 终验）——✅ ReconciliationServiceTest 10 passed（56 assertions，含反转后用例 15 断言）；FuturesReconciliationTest 10 passed（72 assertions）零回归。（注：T013 排除组造数遇 tenant+strategy+symbol 唯一约束，改用独立 symbol 规避）

**Checkpoint**: US1+US2 完成——护栏解除，全市场每周期对账覆盖（SC-001/SC-004）

---

## Phase 5: User Story 3 - 持仓方向与双向持仓（hedge）模式语义 (Priority: P2)

**Goal**: BinanceFuturesAdapter 覆写 getPositions 返回 positionRisk 全部非零腿，hedge 多腿签名合成比对正确

**Independent Test**: 单测 mock positionRisk 多腿应答 + 集成测试 fake adapter 注入双腿（quickstart.md 场景 6）

### Tests for User Story 3（先写，确认失败）

- [x] T018 [P] [US3] 在 tests/Domain/Exchange/BinanceFuturesAdapterTest.php 新增 `getPositions` 覆写测试：mock `/fapi/v2/positionRisk` 返回 [零仓行, long 0.5 行, short 0.2 行] → 返回 2 元素数组（跳过零仓），各元素 side/size/entryPrice/markPrice/leverage 映射正确
- [x] T019 [P] [US3] 在 tests/Integration/Reconciliation/FuturesReconciliationTest.php 新增 hedge 合成用例：legs=[long 0.5, short 0.2] vs orders net=+0.3 → ok（FR-008，SC-005）

### Implementation for User Story 3

- [x] T020 [US3] 在 app/Domain/Exchange/Adapters/BinanceFuturesAdapter.php 覆写 `getPositions()`：调既有 `httpSigned('GET','/fapi/v2/positionRisk',['symbol'=>$symbol],weight:5)`，遍历全部行跳过 `positionAmt` 为零者，逐行映射 ExchangePosition；与既有 `getPosition()` 共享行映射逻辑（抽私有方法 `mapPositionRow(array $row, string $symbol): ExchangePosition`，getPosition 改为取首个非零行调同一方法——行为不变）
- [x] T021 [US3] 跑 T018/T019 转绿 + BinanceFuturesAdapterTest 既有用例全绿（getPosition 语义未动）——✅ T018 先红（默认实现恒 1 元素，assertCount(2) 失败）后绿；T019 因 US1 合成逻辑已覆盖而巧合绿；BinanceFuturesAdapterTest 17 passed（50 assertions）零回归；对账目录 21 passed（136 assertions）

**Checkpoint**: 三故事全部独立可用（SC-005 方向语义全覆盖）

---

## Phase 6: Polish & Cross-Cutting

**Purpose**: 端到端验证与文档收口

- [x] T022 全量验证：`php artisan test --group=redis` 全绿（无 group 的全量套件可选跑）；核对 quickstart.md 第一节 11 场景矩阵逐项有用例对应——✅ 91 passed（465 assertions）；11/11 场景覆盖（ok-long/ok-short/drift/critical-flat/critical-反向/hedge/unknown/ticker 降级/breaker 正交/扫描范围/幂等）
- [x] T023 [P] 更新 README.md 技术债表 A5 行：状态改为已销（引用 spec 010 与本批测试），operations.md §9.5 护栏注记同步更新为「已由合约原生对账接管」
- [x] T024 [P] 更新 specs/010-futures-reconciliation/spec.md 状态为 Implemented（附测试统计与提交号）
- [x] T025 按仓库惯例提交（中文要点 body、conventional 风格、临时文件 + `git commit -F`）；如 quickstart.md 第二节 testnet 手动场景有条件执行则补记录，无条件注明跳过原因——✅ 08e152e feat(reconcile) 主提交 + docs 补记提交；testnet 手动场景无条件，已在提交信息与 spec.md 状态行注明跳过

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖，立即开始
- **Foundational (Phase 2)**: 依赖 Phase 1 基线；**阻塞所有故事**（契约未立无法取持仓）
- **US1 (Phase 3)**: 依赖 Phase 2；**阻塞 US2**（futures 口径须先接管）
- **US2 (Phase 4)**: 依赖 US1 完成（硬依赖，护栏不可先拆）
- **US3 (Phase 5)**: 依赖 Phase 2；与 US1/US2 无代码冲突可并行，但建议 US2 后做（同改 ReconciliationService/BinanceFuturesAdapter 测试文件，避免分支冲突）
- **Polish (Phase 6)**: 依赖全部故事

### User Story Dependencies

- **US1 (P1)**: Phase 2 后即可开始，不依赖其他故事
- **US2 (P1)**: **硬依赖 US1**——护栏移除必须以 futures 口径上线为前提（否则 futures 实例回现货口径误杀，正是 b1edb62 要防的事故）
- **US3 (P2)**: 仅依赖 Phase 2 契约；功能上补强 US1 的 hedge 场景，单向模式下 US1 单独成立

### Parallel Opportunities

- Phase 2 内 T002/T003 可并行（不同文件）
- US1 测试 T007/T008/T009 三文件段可并行编写（同文件内不同用例组，实际串行编辑）
- US2 测试 T013/T014 与 US1 实现无文件重叠时可并行准备
- US3 测试 T018（adapter 测试）与 T019（集成测试）可并行
- Polish 内 T023/T024 可并行

### Parallel Example: Foundational

```bash
# 契约测试与契约签名可同时落（不同文件）：
Task: "T002 BinanceFuturesAdapterTest 新增 getPositions 默认实现失败测试"
Task: "T003 ExchangeAdapter.php 新增 getPositions 签名"
# 随后 T004 默认实现 → T005 fake 补齐 → T006 转绿
```

---

## Implementation Strategy

### MVP First（US1 单独交付即有价值——但不可摘护栏）

1. Phase 1 基线 → Phase 2 契约 → Phase 3 US1
2. **STOP and VALIDATE**：FuturesReconciliationTest 全绿 + 现货基线绿
3. 注意：此时 futures 实例仍被护栏排除，US1 价值只体现在手动 `--instance` 调用；**必须接续 US2 才构成本特性完整交付**

### 推荐交付顺序（本特性 US1+US2 为一个交付单元）

1. Phase 1+2 → 契约就绪
2. US1 → 验证（单向对账正确）
3. US2 → 验证（护栏解除、全市场覆盖、现货零回归）→ **可提交交付（SC-001~SC-004 达成）**
4. US3 → 验证（hedge 语义，SC-005）→ 完整交付
5. Phase 6 收口

---

## Notes

- 全程 bcmath 字符串；禁止浮点参与比对（Constitution II）
- 测试 Redis 键清理逻辑名：`tenant:{tenant_id}:reconcile:{instance_id}:unknown` 与 dedup 键（Constitution VI / 坑#24）
- fake adapter 注入优先于真实 HTTP；真实 testnet 场景为可选项（quickstart 第二节）
- 每个 Phase 完成后按 T025 口径提交一次，避免大批量单提交
