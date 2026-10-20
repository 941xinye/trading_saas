# Data Model: P10 回测引擎 + 绩效分析

**Feature**: specs/008-backtest-engine | **Date**: 2026-10-21 | **依据**: research.md R-005

## 表 1：backtest_runs（回测任务）

| 列 | 类型 | 约束 | 说明 |
|---|---|---|---|
| id | bigint unsigned | PK | |
| tenant_id | bigint unsigned | FK tenants.id, index | 租户隔离（FR-010） |
| strategy_instance_id | bigint unsigned | FK strategy_instances.id, **nullable**, index | null = inline DSL 回测（非实例） |
| strategy_code | varchar(32) | | spot_grid/spot_dca/futures_grid/custom_dsl；写入前过 strategy.implemented 白名单 |
| params_snapshot | json | NOT NULL | 发起时刻完整参数快照（含 dsl_script 全文；Edge Case：实例后续变更/删除不影响） |
| assumptions | json | NOT NULL | MatchingAssumptions 快照（fee_rate/slippage_bps/initial_capital/口径说明） |
| symbol | varchar(32) | index | |
| interval | varchar(8) | | 1m/5m/15m/1h（与实例周期一致） |
| from_utc | datetime | | UTC，区间 [from, to) |
| to_utc | datetime | | UTC；检查 (to − from) ≤ config 上限（默认 1 年） |
| status | enum('pending','running','completed','failed') | NOT NULL default 'pending', index | 状态机见下 |
| progress_pct | tinyint unsigned | NOT NULL default 0 | 进度（Job 内按 bar 推进更新，节流每 5% 一写） |
| error_message | text | nullable | failed 时可读错误（覆盖率不足/并发拒绝/worker 崩溃 reap） |
| started_at | datetime | nullable | |
| finished_at | datetime | nullable | |
| created_at / updated_at | timestamps | | reap-stale 依赖 updated_at 判活 |

**状态机**：

```text
pending ──(Job 拉起)──> running ──(Runner 完成 + 单事务落库)──> completed
                          │
                          ├──(异常/校验失败)──> failed（error_message 必填）
                          └──(worker 崩溃，updated_at 停滞)──[backtest:reap-stale]──> failed
```

- 状态只进不退；completed/failed 为终态，reap-stale 不触碰终态行
- 终态不可逆；同一 run 无重试（重新发起 = 新 run 行，历史报告不覆盖，FR-008/US3）

## 表 2：backtest_reports（回测报告，与 run 1:1）

| 列 | 类型 | 约束 | 说明 |
|---|---|---|---|
| id | bigint unsigned | PK | |
| backtest_run_id | bigint unsigned | FK backtest_runs.id, **unique** | 1:1（completed 时单事务写入） |
| tenant_id | bigint unsigned | FK, index | 冗余保查询隔离简单（不 join runs） |
| initial_capital | decimal(20,8) | NOT NULL | 快照自 assumptions |
| final_equity | decimal(20,8) | NOT NULL | 期末净值 |
| total_return_pct | decimal(20,8) | NOT NULL | 总收益率 %（bcmath 8 位） |
| annualized_return_pct | decimal(20,8) | NOT NULL | 年化 %（线性折算口径） |
| sharpe_ratio | decimal(20,8) | nullable | float 计算落 decimal；零交易/零方差时 null（Edge：无交易标注非除零） |
| max_drawdown_pct | decimal(20,8) | NOT NULL default 0 | 最大回撤 % |
| win_rate_pct | decimal(20,8) | nullable | 胜率 %；零平仓 null |
| profit_factor | decimal(20,8) | nullable | 盈亏比；零亏损平仓 null（口径注明） |
| trade_count | int unsigned | NOT NULL default 0 | 模拟成交笔数 |
| total_fee | decimal(20,8) | NOT NULL default 0 | 手续费总额 |
| bar_count | int unsigned | NOT NULL | 实际回放 bar 数 |
| data_coverage_pct | decimal(5,2) | NOT NULL | 数据覆盖率（实际 bar 数/区间理论 bar 数 ×100；< 阈值不阻断但标注，FR-013） |
| equity_curve | json | NOT NULL | [[barTime_ms, equity_str], ...]；字符串保值（CH 序列化先例 output_format_json_quote_decimals） |
| created_at / updated_at | timestamps | | |

## 表 3：backtest_simulated_trades（模拟交易明细）

| 列 | 类型 | 约束 | 说明 |
|---|---|---|---|
| id | bigint unsigned | PK | |
| backtest_run_id | bigint unsigned | FK backtest_runs.id, index (run_id, seq) | |
| tenant_id | bigint unsigned | FK, index | 冗余隔离 |
| seq | int unsigned | NOT NULL | run 内顺序号（等价性对比锚点） |
| bar_open_time | bigint | NOT NULL | UTC ms（对齐 kline_bars 先例） |
| side | varchar(8) | NOT NULL | buy/sell |
| qty | decimal(30,12) | NOT NULL | bcmath 字符串落库 |
| price | decimal(30,12) | NOT NULL | 含滑点成交价 |
| fee | decimal(20,8) | NOT NULL | 本笔手续费 |
| realized_pnl | decimal(20,8) | NOT NULL | 平仓实现盈亏（开仓为 0） |
| created_at | timestamp | | 无 updated_at（append-only） |

**与既有表边界**：**不写** strategy_orders / signals（FR：与实盘记录无关）；kline_bars 只读（经 HistoricalQuery）。

## Eloquent Model

- `BacktestRun`（hasOne BacktestReport / hasMany BacktestSimulatedTrade；`status` cast 为 backed enum；`params_snapshot`/`assumptions` cast array；金额字段 cast `decimal:8` 读出 string——对齐 payment_invoices 先例）
- `BacktestReport`（belongsTo BacktestRun；`equity_curve` cast array）
- `BacktestSimulatedTrade`（belongsTo BacktestRun；无 updated_at）
- 三 Model 均 `TenantScope` 全局作用域（对齐既有租户模型先例）

## 验证规则（自 FR）

- from_utc < to_utc；(to − from) ≤ config('backtest.max_range_days', 365)
- interval ∈ config('archive.kline_intervals' 子集)；symbol 大写规范化
- initial_capital > 0；fee_rate ∈ [0, 0.01]；slippage_bps ∈ [0, 100]
- strategy_code ∈ config('strategy.implemented') 交集四策略白名单（FR-001）
- inline DSL 回测：params_snapshot 必含 dsl_script 且过 DslValidator（复用 spec 006 校验器）
