# Tasks: P10 回测引擎 + 绩效分析

**Input**: Design documents from `/specs/008-backtest-engine/`

**Prerequisites**: plan.md ✅, spec.md ✅, research.md ✅, data-model.md ✅, contracts/ ✅, quickstart.md ✅

**Tests**: 包含（仓库惯例：每个 spec 均含 Unit/Integration/Feature/POC 四层验证，对齐 spec 005/006/007）

**Organization**: 按 US 组织；WS→Phase 映射：WS1=Phase 2 Foundational，WS2+WS3=Phase 3 US1，WS5=Phase 4 US2，WS4=Phase 5 US3，US4 撮合假设贯穿 Phase 3（DTO/引擎）+ Phase 6（页面假设区），WS6=Phase 7 US5，WS7=Phase 8 Polish。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 不同文件、无未完成任务依赖，可并行
- **[Story]**: US1~US5 映射 spec.md 用户故事
- 所有路径相对仓库根

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: spec 008 工作区准备

- [x] T001 确认 `.specify/feature.json` 指向 `specs/008-backtest-engine`，回顾 plan.md/research.md 决策清单（R-001~R-010）
- [x] T002 [P] 创建 `app/Domain/Backtest/` 目录骨架（Contracts/ Dto/ Services/ Jobs/ Console/ Models/ Exceptions/ 七个子目录，各放 `.gitkeep`）
- [x] T003 [P] 创建 `tests/Unit/Backtest/`、`tests/Integration/Backtest/`、`tests/Feature/Backtest/` 目录与 `.gitkeep`（坑 28：空目录必须有占位否则 PHPUnit 12 exit 2）

---

## Phase 2: Foundational (Blocking Prerequisites) — WS1 数据层

**Purpose**: 三张表 + Model + config，所有 US 的前置

**⚠️ CRITICAL**: 本阶段完成前不得开始任何 US

- [x] T004 [P] 创建 migration `database/migrations/xxxx_xx_xx_xxxxxx_create_backtest_runs_table.php`：按 data-model.md 表 1 全字段（tenant_id FK / strategy_instance_id **nullable** FK / strategy_code varchar(32) / params_snapshot json NOT NULL / assumptions json NOT NULL / symbol index / interval / from_utc/to_utc datetime / status enum('pending','running','completed','failed') default 'pending' index / progress_pct tinyint default 0 / error_message text nullable / started_at/finished_at nullable / timestamps）
- [x] T005 [P] 创建 migration `database/migrations/xxxx_xx_xx_xxxxxx_create_backtest_reports_table.php`：按 data-model.md 表 2（backtest_run_id FK **unique** 1:1 / tenant_id FK index / initial_capital decimal(20,8) / final_equity decimal(20,8) / total_return_pct decimal(20,8) / annualized_return_pct decimal(20,8) / sharpe_ratio decimal(20,8) **nullable** / max_drawdown_pct decimal(20,8) default 0 / win_rate_pct decimal(20,8) **nullable** / profit_factor decimal(20,8) **nullable** / trade_count int unsigned default 0 / total_fee decimal(20,8) default 0 / bar_count int unsigned / data_coverage_pct decimal(5,2) / equity_curve json NOT NULL）
- [x] T006 [P] 创建 migration `database/migrations/xxxx_xx_xx_xxxxxx_create_backtest_simulated_trades_table.php`：按 data-model.md 表 3（backtest_run_id FK / tenant_id FK / seq int unsigned / index(run_id, seq) / bar_open_time bigint / side varchar(8) / qty decimal(30,12) / price decimal(30,12) / fee decimal(20,8) / realized_pnl decimal(20,8) / **仅 created_at 无 updated_at**）
- [x] T007 [P] 创建 `app/Domain/Backtest/Models/BacktestRun.php`：hasOne BacktestReport / hasMany BacktestSimulatedTrade；status cast 为 backed enum `BacktestRunStatus`（同文件创建 enum，**一文件一类**放 `app/Domain/Backtest/Contracts/BacktestRunStatus.php`）；params_snapshot/assumptions cast array；金额字段 cast `decimal:8`（读出 string，对齐 payment_invoices 先例）；挂 TenantScope 全局作用域
- [x] T008 [P] 创建 `app/Domain/Backtest/Models/BacktestReport.php`：belongsTo BacktestRun；equity_curve cast array；TenantScope
- [x] T009 [P] 创建 `app/Domain/Backtest/Models/BacktestSimulatedTrade.php`：belongsTo BacktestRun；`public $timestamps = false` + 手工 created_at（append-only）；TenantScope
- [x] T010 创建 `config/backtest.php`：`max_range_days=365` / `min_coverage_pct=80` / `stale_after_sec=240` / `lock_ttl_sec=120` / `concurrency={free:1,pro:2,enterprise:4}` / `defaults={capital:'1000',fee_rate:'0.001',slippage_bps:5}` / `strategies=['spot_grid','spot_dca','futures_grid','custom_dsl']`
- [x] T011 运行 `php artisan migrate` 验证三表落库（sqlite :memory: 测试连接 + 本机 MySQL 双跑），状态机 enum 与 default 正确
- [x] T012 [P] Unit 测试 `tests/Unit/Backtest/BacktestModelTest.php`：三 Model 关联/cast/TenantScope/decimal string 读出/simulated_trades 无 updated_at

**Checkpoint**: 数据层就绪，US 实现可开始

---

## Phase 3: User Story 1 - 对策略发起回测并获得绩效报告 (Priority: P1) 🎯 MVP — WS2+WS3

**Goal**: 同步内存 BacktestRunner 驱动四策略回放，产出含假设的净值曲线与 9 项指标报告（先支撑 CLI --sync 路径）

**Independent Test**: `backtest:run --sync` 对固定 K 线 fixture 的 spot_grid 实例产出完整报告；断言决策序列、净值点、9 指标数值与预期一致，交易所零调用

### Tests for User Story 1

- [x] T013 [P] [US1] Unit 测试 `tests/Unit/Backtest/MatchingEngineTest.php`：buy=close×(1+bps/10000)、sell=close×(1−bps/10000)、fee=price×qty×rate（bcmath 8 位落账断言用 bccomp）、qty=0/负抛 InvalidArgumentException、极端 bps=100 边界
- [x] T014 [P] [US1] Unit 测试 `tests/Unit/Backtest/PerformanceCalculatorTest.php`：绩效契约黄金向量 5 场景（单调上涨 drawdown=0 / [1000,1200,900,1100] drawdown=25% / 两胜一负 win_rate+profit_factor / 零方差 sharpe=null / 手续费侵蚀 total_fee）+ 零交易 sharpe/win_rate null 语义
- [x] T015 [P] [US1] Unit 测试 `tests/Unit/Backtest/EquityCurveBuilderTest.php`：净值=initial+realizedPnl+持仓估值（多/空方向 ±），读 positionSnapshot 路径
- [x] T016 [P] [US1] 架构守护测试 `tests/Unit/Backtest/BacktestNoBypassTest.php`：静态扫描 `app/Domain/Backtest/` 目录源码，断言零 `new SpotGridStrategy|new SpotDcaStrategy|new FuturesGridStrategy|new DslStrategy` 引用（FR-003 红线，对齐 spec 006 DslNoDynamicEvalTest 范式）
- [x] T017 [P] [US1] 架构守护测试 `tests/Unit/Backtest/BacktestNoExchangeCallTest.php`：静态扫描断言 Domain\Backtest 零 ExchangeAdapter/ExchangeManager 引用（FR-004 红线）
- [x] T018 [US1] Integration 测试 `tests/Integration/Backtest/BacktestRunnerTest.php`：固定 5m K 线 fixture（tests/fixtures/backtest/spot_grid_bars.json）驱动 spot_grid 实例回测，断言信号时点序列与预期一致、净值点数量=bar 数、coverage=100%

### Implementation for User Story 1

- [x] T019 [P] [US1] 创建 DTO `app/Domain/Backtest/Dto/MatchingAssumptions.php`（readonly：feeRate/slippageBps/initialCapital/narrative）+ `SimulatedTrade.php`（readonly：seq/barOpenTime/side/qty/price/fee/realizedPnl）+ `EquityPoint.php`（readonly：barTime/equity 字符串）+ `BacktestRunRequest.php`（tenantId/strategyCode/params/symbol/interval/fromUtc/toUtc/assumptions/pageSize?）+ `BacktestRunResult.php`（barCount/trades/equityPoints/coveragePct）+ `PerformanceReport.php`（readonly 9 指标 + barCount + coveragePct）
- [x] T020 [P] [US1] 创建异常 `app/Domain/Backtest/Exceptions/BacktestDataCoverageException.php` + `BacktestConcurrencyException.php` + `BacktestStrategyNotImplemented.php`（一文件一类）
- [x] T021 [US1] 实现 `app/Domain/Backtest/Services/MatchingEngine.php`（实现 Contracts\SimulatedMatcher 接口，接口文件同步创建）：撮合公式按 contracts/backtest-runner-contract.md §2；bcmath 12 位中间 8 位落账
- [x] T022 [US1] 实现 `app/Domain/Backtest/Services/EquityCurveBuilder.php`：mark() 净值计算按 contracts §3（initial+realizedPnl+持仓按 close 估值，多空方向）；points() 返回全序列
- [x] T023 [US1] 实现 `app/Domain/Backtest/Services/BacktestRunner.php`：行为契约 5 条（contracts §1）——Registry::make 唯一构造、分页回放循环（pageSize=config archive.query_page_size）、onBar→shouldOpen/shouldClose（关联数组）→matcher.fill→onFill（含 fee ExchangeOrder）→equity.mark、覆盖率校验抛 BacktestDataCoverageException、instanceId=0 空 Context 不调 restoreState；onProgress() 进度回调注入点
- [x] T024 [US1] 实现 `app/Domain/Backtest/Services/PerformanceCalculator.php`：绩效契约全部 9 指标（单遍遍历）；资金 bcmath、Sharpe float；零值 null 语义（sharpe 零方差/win_rate 零平仓/profit_factor 零亏损）
- [x] T025 [US1] 创建 `app/Domain/Backtest/Console/BacktestRunCommand.php`（`backtest:run`）：参数族按 contracts/backtest-commands-contract.md §1；--sync 模式走 Runner→Calculator→**单事务**落库（report+trades+run completed）；校验失败 exit 2、策略白名单拒绝抛 BacktestStrategyNotImplemented
- [x] T026 [US1] 注册命令 + 跑通 T018 集成路径：`backtest:run --sync` 端到端产出报告行，断言交易所零调用（RecordingAdapter 注入 HistoricalQuery 层旁证）

**Checkpoint**: US1 可独立交付——CLI sync 回测产出完整报告（MVP）

---

## Phase 4: User Story 2 - 回测与实跑语义一致性 (Priority: P1) — WS5

**Goal**: SC-002 落地——testnet 实跑样本 fixture 双对比（决策序列 100% + 净值误差 <1%）

**Independent Test**: `php artisan test --filter=BacktestEquivalenceTest` 全绿

### Tests for User Story 2

- [x] T027 [US2] 准备 fixture `tests/fixtures/backtest/testnet_spot_grid_sample.json`：自 spec 005 testnet 实跑记录提取（bar 序列 + 实际成交序列 + 实例参数）；样本不足以 RecordingAdapter 驱动序列替代（research R-009 降级路径，fixture 内注明来源）
- [x] T028 [US2] Integration 测试 `tests/Integration/Backtest/BacktestEquivalenceTest.php`：①同区间同参数回测，断言开平仓方向/bar 时点序列与 fixture 成交序列 100% 一致；②实跑净值（成交序列重算）vs 回测净值（含滑点手续费）误差 <1% 且方向可解释（回测含费 ≤ 实跑毛收益）；③DSL 策略 preview vs backtest 信号序列一致性（spec US2 场景 2，复用 spec 006 fixture）

### Implementation for User Story 2

- [x] T029 [US2] 等价性差异修复：若 T028 暴露回测与实跑决策分叉，修 BacktestRunner 驱动语义（禁止改策略类本身——分叉修在驱动层；若确为策略 bug 则另起修复 commit 并回跑实跑基线）
- [x] T030 [P] [US2] 四策略覆盖扩展 `tests/Integration/Backtest/BacktestRunnerFourStrategiesTest.php`：spot_dca/futures_grid/custom_dsl 各一份 fixture 回测跑通（futures 断言报告标注「未含资金费率影响」）

**Checkpoint**: US2 可独立验证——等价性测试守护 FR-003 长期有效

---

## Phase 5: User Story 3 - 回测任务持久化与异步执行 (Priority: P2) — WS4

**Goal**: backtest 独立队列异步执行、单飞/租户并发上限、reap-stale 崩溃收割

**Independent Test**: dispatch 回测→worker 执行→completed；worker kill -9→reap-stale 标 failed 不悬挂；同实例第二任务拒绝

### Tests for User Story 3

- [x] T031 [P] [US3] Integration 测试 `tests/Integration/Backtest/BacktestLifecycleTest.php`：pending→running→completed 状态推进；异常路径（覆盖率不足）→failed+error_message；完成单事务断言（report+trades+run 同事务，模拟 trades insert 异常验证回滚无半完成态）
- [x] T032 [P] [US3] Integration 测试 `tests/Integration/Backtest/BacktestConcurrencyTest.php`（@group redis）：同实例单飞锁冲突拒绝（BacktestConcurrencyException）；租户并发上限 free=1 第二任务拒绝；锁 TTL 释放后可再发起（setUp/tearDown 精确清锁键 `tenant:*:backtest:lock:*`，坑 25）
- [x] T033 [P] [US3] Integration 测试 `tests/Integration/Backtest/ReapStaleBacktestsTest.php`：构造 running 且 updated_at 过期任务→`backtest:reap-stale` 标 failed（error 含 'reaped'）；终态行不触碰；无匹配输出 0 affected

### Implementation for User Story 3

- [x] T034 [US3] 实现 `app/Domain/Backtest/Services/BacktestRunService.php`：任务创建（参数快照含 dsl_script 全文、区间上限校验、策略白名单过 PlanService）+ 单飞锁（Redis SET NX EX120，Lua token 释放对齐 DEK 锁先例）+ 租户并发检查（config concurrency 按 plan 读，超限拒非排队）
- [x] T035 [US3] 实现 `app/Domain/Backtest/Jobs/RunBacktestJob.php`：queue=`backtest`、tries=1；handle()=TenantContext::initialize→finally clear（对齐 AiAnalyzeJob）；包装 Runner+Calculator；进度节流写 progress_pct（每 5%）；完成单事务落库；异常→run failed
- [x] T036 [US3] `BacktestRunCommand` 接入 dispatch 模式（默认）+ 单飞/并发检查前置；--sync 保留
- [x] T037 [P] [US3] 实现 `app/Domain/Backtest/Console/ReapStaleBacktestsCommand.php`（`backtest:reap-stale`）：扫描 running+updated_at<now−stale_after_sec→failed；routes/console.php 注册每 5 分钟+withoutOverlapping
- [x] T038 [P] [US3] 创建 `deploy/supervisor/worker-backtest.conf.example`（`--queue=backtest --tries=1`，autorestart，对齐 worker-ai 先例）+ runbook 补一节

**Checkpoint**: US3 完整——异步执行可靠、崩溃不悬挂、并发受控

---

## Phase 6: User Story 4 - 撮合假设可配置并显式标注 (Priority: P2)

**Goal**: 假设参数全链路可配置 + 报告假设区 + 未建模因素标注

**Independent Test**: 两组 fee_rate 回测同策略同区间，报告收益差异与费率一致；报告含完整假设区

### Tests for User Story 4

- [x] T039 [P] [US4] Integration 测试 `tests/Integration/Backtest/BacktestAssumptionsTest.php`：fee_rate 0.001 vs 0.002 双跑，断言 total_fee 倍率与收益差一致；报告 assumptions JSON 快照与发起参数一致；futures_grid 报告含 funding 免责标注；默认参数来源 config/backtest.php

### Implementation for User Story 4

- [x] T040 [US4] MatchingAssumptions 校验规则落地（initial_capital>0、fee_rate∈[0,0.01]、slippage_bps∈[0,100]）于 BacktestRunRequest 构造层；narrative 文案生成（口径说明：bar 收盘价±bps、未建模项列表）

**Checkpoint**: US4 完整（页面假设区展示在 Phase 7 落地）

---

## Phase 7: User Story 5 - Filament 绩效页 (Priority: P3) — WS6

**Goal**: Tenant 面板绩效页：发起/列表/进度轮询/详情四区

**Independent Test**: 双租户各自回测互不可见（SC-006）；报告二次打开零重算（SC-003）

### Tests for User Story 5

- [x] T041 [P] [US5] Feature 测试 `tests/Feature/Backtest/BacktestPerformancePageTest.php`：页面渲染、列表仅本租户数据（双租户 fixture）、详情四区（曲线/指标卡/假设区/交易明细）齐全、发起表单校验（区间上限/白名单）
- [x] T042 [P] [US5] Feature 测试 `tests/Feature/Backtest/BacktestReportReloadTest.php`：完成报告二次查看查询计数断言（零重算，SC-003；直接读 reports/trades 表）

### Implementation for User Story 5

- [x] T043 [US5] 创建 `app/Filament/Admin/Pages/BacktestPerformancePage.php`（仓库无独立 Tenant 面板，租户后台即 Admin，对齐计费页先例）：列表（状态/策略/区间/收益率/进度 10s 轮询）+ 发起表单（实例或 inline DSL 选择、区间、假设参数）+ 详情视图；挂 Admin 导航
- [x] T044 [P] [US5] 创建 `app/Filament/Admin/Widgets/EquityCurveWidget.php`：净值曲线图（Filament Chart 组件，equity_curve JSON 直接喂数据）

**Checkpoint**: US5 完整——全部 US 交付

---

## Phase 8: Polish & Cross-Cutting Concerns — WS7

**Purpose**: POC 断言族 + quickstart 验证 + 文档同步

- [x] T045 创建 `app/Console/Commands/PocVerifyBacktestCommand.php`（`poc:verify-backtest`）：A~J 十组断言按 contracts/backtest-commands-contract.md §3；一次性租户 poc-verify-backtest-a/b 自动清理；`--only=` 分组过滤支持（**路径偏差**：落 `app/Console/Commands/` 而非 `app/Domain/Backtest/Console/`——Laravel 命令自动发现只扫描 `app/Console/Commands`，放领域目录不会注册）
- [x] T046 [P] 性能断言 H 组：合成 K 线生成器（内存 105k 根 5m bar）端到端回测 < 30s（SC-001；输出实测耗时，超标按 research R-010 优化项处理后再达标）
- [x] T047 全量回归：`php artisan test` 基线 756 passed / 2 failed / 10 skipped / 1 incomplete（2845 assertions）；2 failed 为 `FuturesBreakerSemanticsTest` 既有遗留（spec 004/005 状态机语义：期望 `breaker_open`/`open`，实现 `probe_cooling`/`half_open`；单独运行同样失败，与本 feature 无关）
- [x] T048 quickstart.md 全路径人工走查（§1~§8 逐节可运行）+ 修正文档与实现偏差：§4 CLI 实际签名为位置参数（strategy/symbol/interval）+ `--params`/`--slippage`/`--sync`；§7 页面名「回测绩效」（slug `backtest-performance`，策略组）；前置条件 Redis 语义改为「F 组需 Redis 在线，不可达用 `--only=` 排除」；实测 Unit 35 passed / Integration 25 passed
- [x] T049 [P] README 更新：功能矩阵新增「spec 008 回测引擎」完成区块（对齐 spec 007 区块范式）+ 快速开始 POC 验证段 + P10 路线图行标 ✅ + C 类技术债如有销账同步（无新增 C 类欠债；路线图注明「P4 参数调优建议不在本期」；文档索引同步 specs 006/007/008 与踩坑条数）
- [x] T050 [P] docs/pitfalls.md 新增本轮踩坑 #44–47（命令自动发现目录 / bcmath 截断 vs 契约展示值 / 净值未扣手续费 / 零方差向量构造）+ constitution 无需修订确认（七条原则与 spec 008 无冲突，README 文档索引已注明）

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 Setup**: 无依赖，立即开始
- **Phase 2 Foundational（WS1）**: 依赖 Setup；**阻塞全部 US**
- **Phase 3 US1（WS2+WS3）**: 依赖 Phase 2；🎯 MVP
- **Phase 4 US2（WS5）**: 依赖 Phase 3（Runner 完整才能做等价性对比）
- **Phase 5 US3（WS4）**: 依赖 Phase 3（Job 包装 Runner+Calculator）
- **Phase 6 US4**: 依赖 Phase 3（撮合引擎已存在，仅加校验/标注）；可与 Phase 4/5 并行
- **Phase 7 US5（WS6）**: 依赖 Phase 5（异步链路完整，页面才有任务可展示）
- **Phase 8 Polish（WS7）**: 依赖全部 US

### 关键路径

T004-T012（数据层）→ T019-T026（Runner+绩效+CLI sync，US1 MVP）→ T027-T030（等价性，US2）→ T034-T038（异步，US3）→ T043-T044（页面，US5）→ T045-T050（POC 收口）

### Parallel Opportunities

- Phase 2：T004/T005/T006（三 migration）、T007/T008/T009（三 Model）各自并行
- Phase 3 测试：T013/T014/T015/T016/T017 五个 Unit/守护测试并行
- Phase 3 DTO：T019 与 T020 并行
- Phase 5 测试：T031/T032/T033 并行
- Phase 7：T041/T042 测试并行，T044 Widget 与 T043 页面主体可并行
- Phase 6（US4）整体可与 Phase 4/5 并行（不同文件）

---

## Implementation Strategy

### MVP First（Phase 1+2+3 = WS1+WS2+WS3）

CLI sync 回测产出完整报告即 MVP（US1），可独立验证演示。

### Incremental Delivery

1. WS1 数据层 → 2. WS2+WS3 驱动+绩效（MVP）→ 3. WS5 等价性（质量红线）→ 4. WS4 异步化 → 5. US4 假设标注 → 6. WS6 页面 → 7. WS7 POC 收口；每 WS 一 commit（`feat(spec-008): WS<N> ...`）

### 验证门禁

- 每 Phase 结束跑对应测试组；T047 全量回归是合并前最终门禁
- T016/T017 两个架构守护测试从 Phase 3 起常驻，任何后续改动触发即失败

---

## Notes

- fixture 目录 `tests/fixtures/backtest/`（新建，bar 序列 JSON + testnet 样本）
- 金额断言用 bccomp（bcmath 尾零习惯，记忆约定）
- Redis 相关测试 setUp/tearDown 精确清锁键（坑 25）；@group redis 标注
- Filament 任务覆写父类属性先核对形态（坑 20）；phpunit DataProvider 用属性（坑 27）
- 提交节奏：每 WS 一 commit，对齐 spec 005/006/007
