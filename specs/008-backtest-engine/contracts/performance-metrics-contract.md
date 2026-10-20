# Contract: 绩效指标计算口径契约

**Feature**: specs/008-backtest-engine | **依据**: research.md R-004

本契约是绩效数字的**唯一权威口径**。PerformanceCalculator 实现、报告展示、等价性断言三方 MUST 一致；改口径 = 改本契约 + 全量回归。

## 输入

- `initialCapital`（bcmath 字符串）
- `EquityPoint[]`（barTime ms + equity 字符串，按时间升序）
- `SimulatedTrade[]`（含 realizedPnl/fee）
- 区间天数 `days` = (toUtc − fromUtc) / 86400

## 指标口径

| 指标 | 公式 | 精度 | 零值语义 |
|---|---|---|---|
| final_equity | 末点 equity | bcmath 8 | — |
| total_return_pct | (final − initial) / initial × 100 | bcmath 8 | initial=0 前置校验拦截 |
| annualized_return_pct | total_return_pct × 365 / days | bcmath 8 | days<1 按实际天数折算（口径注明） |
| max_drawdown_pct | max over t: (peak_t − equity_t) / peak_t × 100（peak 为截至 t 的历史峰值） | bcmath 8 | 单调上涨 = 0 |
| sharpe_ratio | mean(dailyReturns) / stddev(dailyReturns) × √365，日收益 = 相邻日末 equity 差/前日 equity | **float** 落 decimal:8 | 零交易或零方差 → **null**（不除零） |
| win_rate_pct | 盈利平仓数 / 总平仓数 × 100（平仓 = realizedPnl ≠ 0 的 trade） | bcmath 8 | 零平仓 → **null** |
| profit_factor | Σ盈利平仓 pnl / \|Σ亏损平仓 pnl\| | bcmath 8 | 零亏损 → **null**（口径注明「无穷大记 null」） |
| trade_count | count(trades) | int | 0 合法（零交易策略报告正常产出） |
| total_fee | Σ trades.fee | bcmath 8 | — |

## 计算规则

1. **单遍遍历**（SC-001）：净值曲线指标（return/drawdown/Sharpe 日收益序列）一遍算出，不多次遍历
2. 资金相关（return/fee/pnl）全 bcmath；Sharpe 的均值/方差用 float（constitution II 豁免：展示指标不结算）
3. 日收益序列构造：equity 点按 UTC 日分组取每日末点；区间内不满一日按一段计
4. 输出 `PerformanceReport` readonly DTO：以上 9 字段 + `barCount` + `dataCoveragePct`

## 黄金测试向量（Unit 必含）

| 场景 | 输入 | 期望 |
|---|---|---|
| 单调上涨 10 点 | initial 1000，equity 线性到 1100 | return=10%，drawdown=0，win_rate=null（无平仓） |
| 已知回撤序列 | [1000, 1200, 900, 1100] | drawdown = (1200−900)/1200 = 25% |
| 两胜一负 | trades pnl [+100, +50, −60] | win_rate=66.66666667%，profit_factor=150/60=2.5 |
| 零方差 | 日收益全同值 | sharpe=null |
| 手续费侵蚀 | 10 笔 fee 各 1 | total_fee=10，final 相应减少 |
