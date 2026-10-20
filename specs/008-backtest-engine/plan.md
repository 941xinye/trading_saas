# Implementation Plan: P10 回测引擎 + 绩效分析

**Branch**: `008-backtest-engine` | **Date**: 2026-10-21 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/008-backtest-engine/spec.md`

## Summary

为四种已实装策略（spot_grid / spot_dca / futures_grid / custom_dsl）提供历史回测能力：从 `HistoricalQuery`（spec 004，冷热路由归并）分页回放 K 线，经 `StrategyRegistry::make()` 构造策略对象逐 bar 驱动（onBar → shouldOpen/shouldClose → 模拟成交 onFill），**与实跑共用同一策略类与指标计算路径（FR-003 架构红线）**，产出含手续费/滑点假设的净值曲线与绩效指标（Sharpe/最大回撤/胜率/盈亏比），任务与报告持久化、异步队列执行、Filament 绩效页自助查看。验收目标：1 年 5m K 线（~105k bars）回测 < 30s（SC-001）；与实跑决策序列 100% 一致、净值误差 < 1%（SC-002）。

技术路径（自 research.md 全部决策已收敛）：
- 驱动核心 = **BacktestRunner**（新 Domain\Backtest）：纯同步内存循环，复用 HistoricalPreviewService 的分页范式但泛化到四策略（不再 DSL 专属）
- 策略构造 = **StrategyRegistry::make()** 唯一入口（禁止直接 new 具体策略类，守护 FR-003）
- 撮合 = **MatchingEngine**（bar.close × (1±slippage_bps) 成交；fee = notional × fee_rate；bcmath 12 位）
- 绩效 = **PerformanceCalculator**（净值曲线按 bar close 标记估值；指标 bcmath 计算，Sharpe 用均值/标准差需浮点仅用于展示指标，不参与资金结算）
- 异步 = 独立 `backtest` 队列 worker（Queueable 范式对齐 AiAnalyzeJob），与行情协程进程物理隔离（constitution III）
- 一致性验收 = **BacktestEquivalenceTest**：实跑记录样本（testnet 成交样本固化 JSON fixture）与回测对比决策序列 + 净值误差（SC-002 落地）

## Technical Context

**Language/Version**: PHP 8.4 / 8.5（与仓库基线一致）

**Primary Dependencies**: 零新增 Composer 依赖（红线）。复用 Laravel 13 + Eloquent + Queue + Filament v5 + bcmath + spatie 无新增

**Storage**: MySQL 8.4（backtest_runs / backtest_reports / backtest_simulated_trades 三新表，decimal(20,8) 金额、decimal(30,12) 净值曲线点）；ClickHouse 历史 K 线经 HistoricalQuery 读取（不直查 CH）；Redis 仅用于同实例单飞锁（非持久状态）

**Testing**: PHPUnit 12（tests/{Unit,Integration,Feature}/Backtest/，新子目录）；poc:verify-backtest 离线断言（对齐 spec 005/007 范式）

**Target Platform**: Linux 生产节点（WSL2 开发）；队列 worker 长驻（supervisor autorestart）

**Project Type**: Laravel monolith 内嵌 Domain（与 Billing/Risk/Liquidation/Archive 平级新域 Domain\Backtest）

**Performance Goals**: SC-001：1 年 5m K 线（~105k bars）单策略回测端到端 < 30s；报告查看加载 < 2s（SC-003，零重算）

**Constraints**: 全程零真实交易所调用（FR-004，含行情）；回测不进 market:feed 协程进程（constitution III）；决策路径零复制（FR-003）；金额 bcmath 字符串（constitution II）；租户隔离（FR-010）

**Scale/Scope**: 区间上限 1 年（config，初值）；K 线覆盖率下限 80%（config）；同实例单飞、租户并发上限按套餐差异化（free=1/pro=2/enterprise=4，config）；4 种策略 × 双时间粒度（回测周期=实例周期，不再聚合）

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 原则 | 检查点 | 结论 |
|---|---|---|
| I. SDD 优先 | 本 spec 五阶段流程，plan 在 specify 后 | PASS |
| II. 金融精度 | 资金结算/手续费/滑点/数量 bcmath 字符串；Sharpe 等展示指标允许 float（不结算）；UTC；回测 cid 模板 `bt_{run_id}_{seq}` | PASS |
| III. 协程隔离 | 回测 worker 独立队列 `backtest`，绝不经 market:feed；无 WS/无 hook 需求 | PASS |
| IV. 崩溃恢复幂等 | 任务生命周期状态机：worker 崩溃→running 任务经 `backtest:reap-stale` 调度命令标 failed（不悬挂）；回测只读不写策略状态，无需状态恢复 | PASS |
| V. 计费语义 | FR-011 回测不计配额（spec 已决策）；不触碰 QuotaGuard；`strategy.implemented` 白名单门控复用 PlanService（未知策略码抛异常不降级） | PASS |
| VI. 测试隔离 | Backtest 测试建独立租户/实例 fixture，tearDown 精确清理单飞锁键；完整路径走 Domain 公开方法 | PASS |
| VII. PSR-4 对齐 | 一文件一类；Filament Page/Widget 覆写前核对父类形态；Redis zAdd/eval 签名按 REDIS_CLIENT | PASS |

**Gate 结论**：7/7 PASS，无违例需 justify。

## Project Structure

### Documentation (this feature)

```text
specs/008-backtest-engine/
├── plan.md              # 本文件
├── research.md          # Phase 0（R-001~R-010 决策）
├── data-model.md        # Phase 1（3 新表 + 状态机）
├── contracts/           # Phase 1（3 契约：Runner/绩效口径/命令族）
│   ├── backtest-runner-contract.md
│   ├── performance-metrics-contract.md
│   └── backtest-commands-contract.md
├── quickstart.md        # Phase 1（九步验证 + SC 对照表）
└── tasks.md             # /speckit-tasks 产出
```

### Source Code (repository root)

```text
app/Domain/Backtest/                    # 新域（与 Billing/Risk 平级）
├── Contracts/
│   └── SimulatedMatcher.php            # 撮合器接口（可替换更精细撮合，本期单实现）
├── Dto/
│   ├── BacktestRunRequest.php          # 发起参数（instanceId 或 inline DSL + 区间 + 假设）
│   ├── MatchingAssumptions.php         # fee_rate/slippage_bps/initial_capital/成交口径 readonly
│   ├── SimulatedTrade.php              # 单笔模拟成交 readonly
│   ├── EquityPoint.php                 # 净值曲线点 readonly
│   └── PerformanceReport.php           # 指标集 readonly
├── Services/
│   ├── BacktestRunner.php              # 核心驱动（分页回放 + Registry 构造 + 逐 bar 驱动 + 撮合）
│   ├── MatchingEngine.php              # bar.close×(1±bps) + fee 计算（bcmath）
│   ├── PerformanceCalculator.php       # 净值曲线 + 7 项指标计算
│   ├── EquityCurveBuilder.php          # realized + 持仓按 close 估值（复用 preview 范式泛化）
│   └── BacktestRunService.php          # 任务生命周期（创建/单飞/状态推进/参数快照）
├── Jobs/
│   └── RunBacktestJob.php              # backtest 队列（tenant context initialize/finally clear）
├── Console/
│   ├── PocVerifyBacktestCommand.php    # poc:verify-backtest 离线断言
│   ├── BacktestRunCommand.php          # backtest:run（运维/CLI 发起）
│   └── ReapStaleBacktestsCommand.php   # backtest:reap-stale（崩溃任务收割，调度）
├── Models/                             # Eloquent（BacktestRun/BacktestReport/SimulatedTrade 记录）
└── Exceptions/
    ├── BacktestDataCoverageException.php   # 覆盖率 < 阈值
    ├── BacktestConcurrencyException.php    # 同实例单飞冲突
    └── BacktestStrategyNotImplemented.php  # 白名单拒绝

app/Filament/Tenant/Pages/BacktestPerformancePage.php   # 绩效页（列表+详情）
app/Filament/Tenant/Widgets/EquityCurveWidget.php       # 净值曲线图（Livewire 轮询）

database/migrations/
├── xxxx_create_backtest_runs_table.php
├── xxxx_create_backtest_reports_table.php
└── xxxx_create_backtest_simulated_trades_table.php

config/backtest.php                     # 区间上限/覆盖率阈值/并发上限/默认假设

tests/
├── Unit/Backtest/          # MatchingEngine / PerformanceCalculator / 假设 DTO
├── Integration/Backtest/   # 四策略回测跑通 / 覆盖率 / 单飞 / 生命周期 / 等价性
└── Feature/Backtest/       # 绩效页渲染 + 租户隔离 + API（若有）
```

**Structure Decision**: 新 Domain\Backtest 平级域（对齐 Billing/Risk/Liquidation 先例）；策略对象**不**新建回测变体，统一 StrategyRegistry::make() 构造（FR-003 落地关键）；Filament 挂 Tenant 面板（对齐计费页先例）；测试按 tests/{Unit,Integration,Feature}/Backtest/ 新子目录（对齐 spec 007 Payment 先例）。

## 工作流拆解（WS1~WS7）

| WS | 范围 | 关键产出 | 预估任务数 |
|---|---|---|---|
| WS1 数据层 | 3 migration + 3 Model + config/backtest.php + 状态机 | runs/reports/simulated_trades 表；BacktestRun 状态机（pending/running/completed/failed） | ~8 |
| WS2 驱动核心 | MatchingAssumptions/SimulatedTrade DTO + MatchingEngine + EquityCurveBuilder + BacktestRunner（同步内存版，先支撑单策略跑通） | Runner 可对 spot_grid 产出净值曲线 | ~10 |
| WS3 绩效计算 | PerformanceCalculator（7 指标 bcmath/Sharpe float 展示）+ PerformanceReport DTO + 报告持久化 | 报告指标齐全、口径可复现 | ~8 |
| WS4 任务化+异步 | RunBacktestJob + backtest 队列 + 单飞锁 + reap-stale 调度 + 参数快照 | 异步执行、崩溃不悬挂、并发受控 | ~8 |
| WS5 四策略+一致性 | 四策略接入验证 + BacktestEquivalenceTest（实跑样本 fixture 对比 SC-002） | 决策序列 100% 一致、净值误差 <1% | ~10 |
| WS6 Filament 绩效页 | 列表/详情/发起/进度轮询 + 租户隔离 | 双租户零串扰（SC-006） | ~8 |
| WS7 POC+收口 | poc:verify-backtest 断言族 + quickstart 验证 + README 区块 | SC-001~006 全绿 | ~6 |

**测试矩阵**：

| 层 | 覆盖 | 环境依赖 |
|---|---|---|
| Unit | 撮合公式边界（0 qty/极端 bps/多空方向）、绩效指标数学（已知向量的 Sharpe/回撤/胜率）、假设 DTO 校验 | 无 |
| Integration | 四策略 × 固定 K 线 fixture 回测、覆盖率不足标注、单飞冲突、reap-stale、等价性（SC-002） | Redis（单飞）可选 skip |
| Feature | 绩效页渲染、双租户隔离、报告零重算加载 | 无 |
| POC 断言 | SC-001 性能（1 年 5m 合成 K 线 < 30s）、SC-005 交易所零调用（RecordingAdapter 断言零调用） | 离线 |

**风险与缓解**：
1. **SC-001 性能风险**：105k bars × 逐 bar 驱动可能超 30s → 缓解：HistoricalQuery 分页批量取（page 1000）、Runner 纯内存循环、绩效指标一遍遍历计算；POC 用合成 K 线先测性能基线，若超标再优化（批驱动/指标增量计算），不达标前不进 WS6
2. **SC-002 等价性样本可得性**：testnet 实跑成交样本需既有记录 → 缓解：spec 005 已产 testnet 样本，固化为 JSON fixture 入 tests/fixtures；样本不足时以 RecordingAdapter 实跑模拟序列替代（决策序列对比为主，净值对比为辅）
3. **DslStrategy 构造签名差异**：第 4 参 parser 与其余策略不同 → 缓解：统一走 StrategyRegistry::make()（内部已处理差异），Runner 不感知
4. **futures_grid 资金费率**：历史 funding 无数据源 → 缓解：报告标注「未含资金费率影响」（spec Edge Case 已收），撮合不加 funding 项
5. **Sharpe float 与 bcmath 红线**：统计指标（均值/方差）bcmath 无开方 → 缓解：资金结算 bcmath，统计指标仅展示用 float 计算（报告中注明），不违反 constitution II（结算红线）

## 提交节奏

WS1 数据层 → WS2 驱动核心 → WS3 绩效 → WS4 异步 → WS5 一致性 → WS6 页面 → WS7 收口，每 WS 一 commit（`feat(spec-008): WS<N> ...`），对齐 spec 005/006/007 节奏。

## Complexity Tracking

无违例项（Constitution Check 7/7 PASS）。
