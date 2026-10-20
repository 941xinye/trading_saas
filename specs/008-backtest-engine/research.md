# Research: P10 回测引擎 + 绩效分析

**Feature**: specs/008-backtest-engine | **Date**: 2026-10-21

本文件收敛 spec 的全部技术决策（R-001~R-010）。所有 NEEDS CLARIFICATION 已在 specify 阶段按合理默认收敛，本阶段决策均基于仓库既有代码事实（HistoricalPreviewService / StrategyRegistry / StrategyInterface / HistoricalQuery 已读源码）。

---

## R-001 回测驱动核心范式

**Decision**: 新建 `BacktestRunner`（Domain\Backtest\Services），纯同步内存循环：HistoricalQuery 分页拉 K 线 → `StrategyRegistry::make()` 构造策略 → 逐 bar `onBar → shouldOpen/shouldClose → 模拟成交 onFill` → EquityCurveBuilder 记录净值。

**Rationale**: HistoricalPreviewService（spec 006）已验证该范式（分页 + 驱动 + 简易净值），但其为 DSL 专属且内嵌 DSL 特化逻辑（pendingSignal 元数据读取）。BacktestRunner 将其泛化：策略构造收敛到 Registry 唯一入口，驱动循环对四种策略统一，撮合/绩效职责外移到独立组件。

**Alternatives considered**: ①复用 HistoricalPreviewService 扩展四策略——拒绝：DSL 特化逻辑（DslPreviewResult/pendingSignal）污染通用域；②改造 StrategyRunner 支持历史模式——拒绝：StrategyRunner 耦合 market:feed 协程生命周期（restoreState/Redis 状态），回测需纯净内存循环（FR-004）。

---

## R-002 FR-003「共用同一解释器」的落地机制

**Decision**: BacktestRunner 内**禁止** `new SpotGridStrategy()` 等直接构造；唯一入口 `StrategyRegistry::make($code, $instanceId, $params, $context)`。等价性守护测试（BacktestNoBypassTest）静态扫描 Domain\Backtest 目录，断言零具体策略类 `new` 引用（对齐 spec 006 DslNoDynamicEvalTest 静态扫描范式）。

**Rationale**: Registry::make 是实跑（market:feed）与回测的共同收敛点——make 内部 match 到同一批策略类，天然保证「同一解释器」。DslStrategy 的第 4 参 parser 差异由 Registry 内部消化（已核实 StrategyRegistry.php L44-49），Runner 无需感知。

**Alternatives considered**: ①约定俗成靠 review——拒绝：FR-003 是架构红线，需测试守护；②抽 BacktestStrategyFactory 再包一层——拒绝：多一层无新职责的间接层，Registry 已是工厂。

---

## R-003 撮合模型

**Decision**: `MatchingEngine`：买入成交价 = bar.close × (1 + slippage_bps/10000)，卖出 = bar.close × (1 − bps/10000)；手续费 = 成交价 × qty × fee_rate，bcmath 12 位中间精度、8 位落账。开仓从资金扣 (notional + fee)，平仓回款 (notional − fee)。futures 方向语义：qty 带方向（多+/空-），保证金简化按 notional/leverage 占用。

**Rationale**: bar 级撮合（收盘价成交）是 spec Assumptions 已定口径，与 HistoricalPreviewService 的「按 bar.close 成交」一致但增加滑点/手续费（US4 核心增量）。futures_grid 的杠杆/方向语义复用策略自身 positionSnapshot（策略 onFill 内部已处理多空入账，Runner 只需提供含 fee 的 ExchangeOrder）。

**Alternatives considered**: ①盘口/tick 级撮合——拒绝：无数据源（P8 仅存 bar），spec 已标注为边界；②开盘价成交——拒绝：决策点在 bar close，close 价成交语义最贴合。

---

## R-004 绩效指标计算口径

**Decision**: `PerformanceCalculator` 输入净值曲线点序列 + 模拟交易列表，单遍计算：
- 总收益率 = (期末净值 − 初始资金)/初始资金（bcmath）
- 年化 = 总收益率 × 365/天数（bcmath；<1 年区间直接线性折算，口径写入报告）
- 最大回撤 = max((历史峰值 − 谷值)/峰值)（bcmath 遍历）
- Sharpe = 日收益率序列均值/标准差 × √365（**float**，无风险利率 0）
- 胜率 = 盈利平仓数/总平仓数；盈亏比 = 平均盈利/平均亏损（bcmath）

**Rationale**: 资金结算全 bcmath（constitution II）；Sharpe 需开方，bcmath 无 sqrt，且 Sharpe 是展示指标不参与结算，用 float 合理（plan 风险 5 已注明）。单遍计算保 SC-001 性能。

**Alternatives considered**: ①引入 math 库算高精度 Sharpe——拒绝：零新增依赖红线 + 展示指标无需；②年化用复利——拒绝：线性折算口径简单透明，与主流回测工具一致，报告注明即可。

---

## R-005 数据模型：三表拆分

**Decision**:
- `backtest_runs`：任务主表（tenant_id FK / instance_id nullable FK / strategy_code / params_snapshot JSON / assumptions JSON / interval / from_utc / to_utc / status enum(pending,running,completed,failed) / progress_pct / error_message / unique (instance_id, status=running 部分唯一——见 R-007)）
- `backtest_reports`：run_id unique FK / 7 指标列 / bar_count / trade_count / data_coverage_pct / assumptions_json（冗余快照保可复现）
- `backtest_simulated_trades`：run_id FK / bar_open_time / side / qty / price / fee / realized_pnl / seq（run 内顺序）；**不落 strategy_orders**（FR 边界）

净值曲线：report 表 `equity_curve` JSON 列（[ [barTime, equity], ... ]，105k 点约 3MB，MySQL JSON 可承受；CH 归档留后续）。

**Rationale**: 任务/报告/交易明细三表职责清晰（对齐 payment_invoices/payment_webhook_events 拆分先例）；equity_curve 存 JSON 而非逐行表——查询场景只有整体读取（绘图），无点查询需求，行表会放大写入 105k 次。

**Alternatives considered**: ①equity_curve 逐行表——拒绝：写入放大且无点查询；②报告存 CH——拒绝：报告是租户级业务数据（tenant_id 维度），CH 是无租户公共数据层（spec 004 R-008 已定边界）。

---

## R-006 异步执行与队列隔离

**Decision**: `RunBacktestJob`（ShouldQueue，queue=`backtest`）；supervisor 新增 worker-backtest.conf.example（`--queue=backtest`，对齐 worker-ai/worker-default 先例）；Job 内 TenantContext::initialize + finally clear（对齐 AiAnalyzeJob 先例）；config/queue.php 无需改（Laravel 队列名动态）。

**Rationale**: constitution III——重负载绝不进 market:feed；独立队列使 backtest 与 ai/notifications/orders 互不阻塞（回测 30s 级 vs AI 30s 级，混队列会互相排队）。

**Alternatives considered**: ①复用 default 队列——拒绝：orders 队列是交易关键路径，回测会阻塞下单；②Octane 内同步跑——拒绝：30s 阻塞请求不可接受。

---

## R-007 并发控制：单飞 + 租户上限

**Decision**: 同实例单飞——Redis 锁 `tenant:{tid}:backtest:lock:{iid}` SET NX EX（TTL=回测上限 120s，超时兜底）+ 创建任务前检查。租户并发上限——按套餐读 config(backtest.concurrency.{plan})：free=1/pro=2/enterprise=4；超限抛 BacktestConcurrencyException（**不降级排队**，明确错误信息提示稍后重试）。单飞锁在 Job finally 释放（Lua token 释放对齐 DEK 锁先例）。

**Rationale**: FR-009 明确要求单飞+租户上限；Redis SET NX 是仓库成熟范式（坑 18/单飞锁多处先例）。不排队是简化决策——排队语义（FIFO/优先级）复杂度高且本期无需求。

**Alternatives considered**: ①DB 状态约束实现单飞——部分唯一索引（MySQL 不支持条件索引，generated column 方案复杂）拒绝；Redis 锁足够（锁失效兜底=reap-stale 收割 running 任务）；②排队——拒绝，见上。

---

## R-008 崩溃恢复：reap-stale 而非状态恢复

**Decision**: worker 崩溃时 running 任务不会悬挂——`backtest:reap-stale` 命令（调度每 5m，withoutOverlapping）：扫描 running 且 updated_at < now − 2×TTL 的任务 → 标 failed（error_message='worker timeout/crash'）。回测本身**无状态恢复需求**（只读历史 + 内存计算 + 完成才落库，半途无脏写）。

**Rationale**: constitution IV 要求崩溃幂等，但回测是「全有或全无」语义（完成才写 reports/trades，runs 行创建即 pending），无需双写恢复；reap-stale 是最小充分方案。reports/trades 与 run 状态更新同事务（completed 时一次提交），无半完成态。

**Alternatives considered**: ①断点续跑——拒绝：回测 30s 级，重跑成本低于续跑复杂度；②running 任务心跳更新——拒绝：无必要（TTL 兜底足够）。

---

## R-009 等价性验收（SC-002）落地方案

**Decision**: `BacktestEquivalenceTest`（Integration）：
1. **决策序列对比**：spec 005 testnet 实跑样本（fixture JSON：bar 序列 + 实际成交序列）→ 同区间同参数回测 → 断言开平仓方向/时点序列 100% 一致
2. **净值误差对比**：实跑净值（由成交序列重算）vs 回测净值（含滑点/手续费假设）→ 断言误差 < 1% 且差异方向可解释（回测含费用应 ≤ 实跑毛收益）
3. 样本不足时降级：用 RecordingAdapter 驱动实跑模拟（离线合成 bar 序列经 StrategyRunner 语义驱动）产出「实跑」基线

**Rationale**: SC-002 是本 spec 的命脉；testnet 样本是最强证据（真实环境产出），fixture 化使测试离线可重复（对齐 spec 001/002 签名测试黄金向量先例）。

**Alternatives considered**: ①仅对比 DSL 策略（preview 已有等价性）——拒绝：FR 覆盖四策略，spot_grid 是最常用策略必须有样本；②实时连 testnet 做对比——拒绝：smoke 组语义，CI/离线不可跑。

---

## R-010 性能达标（SC-001）路径

**Decision**: 30s/105k bars 的预算分解：HistoricalQuery 分页（105 页 × ~50ms CH/MySQL 查询 ≈ 6s）+ 驱动循环（105k × ~150μs/bar：onBar 指标计算 + 撮合 + 净值 ≈ 16s）+ 绩效单遍计算（<1s）+ 落库（<2s）≈ 25s，留 20% 余量。POC 用**合成 K 线生成器**（内存生成 105k 根标准 bar，不经 DB）先测纯驱动性能；若超标，优化项按序：①IndicatorCalculator 增量计算（滑动窗口复用）②撮合/净值内联减少对象分配 ③pageSize 调大。

**Rationale**: 先测后优（POC 范式）：合成 K 线基线在 WS7 poc:verify-backtest 内断言 SC-001，避免为性能过早设计。指标增量计算是已知优化（IndicatorSnapshotBuilder 已是窗口化），作为备案不预实施。

**Alternatives considered**: ①预聚合指标存中间表——拒绝：引入回测专属数据路径，违反 FR-003 精神；②多进程并行——拒绝：单任务内并行复杂度远超收益（多任务是 worker 并发天然覆盖）。

---

## 决策汇总

| # | 决策 | 关键依据 |
|---|---|---|
| R-001 | BacktestRunner 独立纯内存驱动器 | HistoricalPreviewService 范式泛化 |
| R-002 | Registry::make 唯一构造入口 + 静态扫描守护 | FR-003 架构红线测试化 |
| R-003 | bar.close±bps 撮合 + fee bcmath | spec 撮合口径 + 多空语义复用策略 onFill |
| R-004 | 结算 bcmath / Sharpe float 展示 / 年化线性 | constitution II 边界厘清 |
| R-005 | runs/reports/simulated_trades 三表 + equity_curve JSON | 职责拆分 + 写入放大规避 |
| R-006 | backtest 独立队列 + supervisor 样板 | constitution III 队列隔离 |
| R-007 | Redis 单飞锁 + 套餐并发上限，超限拒非排队 | FR-009 + 仓库锁范式 |
| R-008 | reap-stale 收割 + 完成单事务 | constitution IV 最小充分 |
| R-009 | testnet fixture 决策序列 + 净值双对比 | SC-002 离线可重复 |
| R-010 | 预算分解 + 合成 K 线先测后优 | SC-001 POC 范式 |
