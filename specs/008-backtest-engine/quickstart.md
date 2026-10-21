# Quickstart: P10 回测引擎 + 绩效分析

**Feature**: specs/008-backtest-engine | 分三层：**离线断言（本机全跑）** + **集成（Redis/MySQL 可选）** + **等价性样本（fixture 离线）**。

## 前置条件

- PHP 8.4+ / Composer；MySQL 8.4（3 张新表，`php artisan migrate`）
- Redis 7+（单飞锁）：F 组并发断言需 Redis 在线；不可达时命令会在该组失败，用 `--only=A,B,C,D,E,G,H,I,J` 排除 F 后再跑（其余组不依赖 Redis）
- **无需 ClickHouse**：HistoricalQuery 在 CH 未配置时走 MySQL 热数据层（spec 004 既有路由语义）；等价性/性能断言用 fixture 与合成 K 线
- **无需交易所凭据、无需外网**（FR-004：回测零交易所调用）

## 1. 一键离线断言

```bash
php artisan poc:verify-backtest
```

预期：`ALL CHECKS PASSED`（A~J 十组，见 contracts/backtest-commands-contract.md §3）。

## 2. 单元测试

```bash
# 撮合公式 + 假设 DTO + 绩效黄金向量（含 5 场景）
php artisan test tests/Unit/Backtest
```

预期：全 PASS 无 skipped。

## 3. 集成测试

```bash
# 四策略回测跑通 / 覆盖率 / 生命周期 / 等价性（SC-002 fixture 对比）
php artisan test tests/Integration/Backtest

# 单飞与租户并发（需 Redis）
php artisan test --group=redis tests/Integration/Backtest/BacktestConcurrencyTest
```

## 4. 手工端到端（CLI，sync 模式）

```bash
# ① 造数：向 kline_bars 写入 90 天 5m 合成 K 线（或复用已有数据）
# ② 发起（同步模式，直接看结果；策略/交易对/周期为位置参数）
php artisan backtest:run spot_grid BTCUSDT 5m --tenant=1 \
    --from=2026-07-01 --to=2026-10-01 \
    --params='{"lower_price":"100","upper_price":"120","grid_count":10,"qty_per_grid":"0.1"}' \
    --capital=1000 --fee-rate=0.001 --slippage=5 --sync

# ③ 查看报告
php artisan tinker --execute="App\Domain\Backtest\Models\BacktestReport::latest()->first()->toArray()"
```

预期：run 状态 completed；报告 9 指标齐全 + equity_curve 非空 + assumptions 快照可见。

## 5. 异步 + 崩溃恢复演练

```bash
# ① 启动 backtest worker
php artisan queue:work --queue=backtest --tries=1

# ② dispatch 一个长区间回测，运行中 kill -9 worker
# ③ 等 backtest:reap-stale 调度（≤5m）或手工执行：
php artisan backtest:reap-stale
```

预期：被收割 run 状态 failed + error_message 含 'reaped'；无悬挂 running。

## 6. 等价性验证（SC-002 核心）

```bash
php artisan test --filter=BacktestEquivalenceTest
```

预期：决策序列一致率 100%；净值误差 < 1% 且方向可解释（回测含费 ≤ 实跑毛收益）。

## 7. Filament 回测绩效页点检（需 UI 会话）

1. 租户用户登录 `/admin` →「策略」组 →「回测绩效」页（slug `backtest-performance`）；
2. 发起回测（选实例+区间）→ 列表出现 pending/running 行（10s 轮询进度）；
3. 完成后打开详情：净值曲线图 + 指标卡 + 假设区 + 交易明细四区齐全（US5）；
4. 报告二次打开：加载 < 2s（SC-003）；
5. 换第二个租户登录：列表/详情互不可见（SC-006）。

## 8. 性能验收（SC-001）

```bash
php artisan poc:verify-backtest --only=H
```

合成 1 年 5m K 线（~105,000 根）单策略端到端回测。预期：< 30s（输出实测耗时；超标时按 research.md R-010 优化项处理）。

## 9. 验收清单（SC-001~006 对照）

| SC | 验证方式 | 状态 |
|---|---|---|
| SC-001 1 年 5m < 30s | §8 H 组断言 | 本期离线 ✅ |
| SC-002 决策 100% 一致 + 净值 <1% | §6 BacktestEquivalenceTest | 本期 ✅（fixture 口径） |
| SC-003 报告查看 < 2s 零重算 | §7-4 + J 组查询计数断言 | 本期 ✅ |
| SC-004 30 天回测全流程 ≤ 2 分钟 | §4 手工计时 | 本期 ✅ |
| SC-005 交易所零调用 | I 组 RecordingAdapter 断言 + Runner 依赖面静态扫描 | 本期 ✅ |
| SC-006 双租户零串扰 | §7-5 + G 组断言 | 本期 ✅ |
| （真实链路）与 testnet 长期实跑净值对照 | 依赖海外节点实跑数据积累 | 待节点 ⏳（同 runbook 矩阵） |

## 10. 已知边界

- 撮合为 bar 级（close±bps），不建模盘口深度/部分成交（spec Assumptions）
- futures_grid 未含资金费率影响（历史 funding 无数据源，报告标注）
- Sharpe 为 float 展示指标（结算全 bcmath，口径见绩效契约）
- 年化按线性折算；profit_factor 零亏损记 null
- 回测不计配额（FR-011）；同实例单飞、超限拒绝不排队（R-007）
- 区间上限 1 年、覆盖率阈值 80%（config/backtest.php 可调）
