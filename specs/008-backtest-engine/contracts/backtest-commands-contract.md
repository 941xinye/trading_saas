# Contract: 回测命令族契约

**Feature**: specs/008-backtest-engine | **依据**: research.md R-006/R-008

## 1. `php artisan backtest:run`（运维/CLI 发起）

```text
backtest:run
    {--tenant= : 租户 id（必填）}
    {--instance= : 策略实例 id（与 --dsl 二选一）}
    {--dsl= : inline DSL 脚本文件路径（与 --instance 二选一）}
    {--strategy= : 策略码（instance 模式从实例读，可省略）}
    {--symbol=BTCUSDT}
    {--interval=5m}
    {--from= : UTC Y-m-d（必填）}
    {--to= : UTC Y-m-d（必填）}
    {--capital=1000}
    {--fee-rate=0.001}
    {--slippage-bps=5}
    {--sync : 同步执行（默认 dispatch 到 backtest 队列）}
```

- 成功输出 run id + 状态；--sync 模式完成时打印指标摘要
- 校验失败（白名单/区间超限/单飞冲突）→ exit code 2 + 可读错误（对齐 ArchiveRunCommand 先例）
- 单飞冲突 exit code 3（BacktestConcurrencyException）

## 2. `php artisan backtest:reap-stale`（崩溃任务收割）

- 扫描：`status=running AND updated_at < now() − config('backtest.stale_after_sec', 240)`
- 动作：标 failed，error_message='reaped: worker timeout or crash'
- 调度：routes/console.php 每 5 分钟 + withoutOverlapping（对齐 reconcile:run 先例）
- 幂等：终态行不触碰；无匹配行输出 0 行 affected

## 3. `php artisan poc:verify-backtest`（离线断言，WS7）

断言族（编号对应 SC）：

| 组 | 断言 | 对应 SC/FR |
|---|---|---|
| A 撮合 | 买价=close×(1+bps)、卖价反向、fee 精度、qty=0 拒绝 | contracts/performance |
| B 驱动 | spot_grid 固定 K 线 fixture 回测产出信号序列与预期一致；Registry 构造路径断言 | FR-002/003 |
| C 绩效 | 黄金向量 5 场景（见绩效契约表） | R-004 |
| D 等价性 | testnet 样本 fixture：决策序列 100% 一致 + 净值误差 <1% | SC-002 |
| E 生命周期 | pending→running→completed；异常→failed；reap-stale 收割 | FR-007/R-008 |
| F 并发 | 同实例第二任务拒绝（exit 3）；租户超限拒绝 | FR-009 |
| G 隔离 | 双租户回测互不可见（runs/reports/trades 三表） | FR-010/SC-006 |
| H 性能 | 合成 105k bars（1 年 5m）单策略回测 < 30s | SC-001 |
| I 红线 | RecordingAdapter 注入回放路径 → 断言交易所方法零调用 | FR-004/SC-005 |
| J 报告 | 完成后再读报告零重算（查询计数断言）；假设区完整 | SC-003/FR-005 |

预期输出：`ALL CHECKS PASSED (N/N)`；一次性租户 `poc-verify-backtest-a/b` 结束自动清理（对齐 poc:verify-payment 先例）。

## 4. 队列接线

- RunBacktestJob：queue=`backtest`；tries=1（失败即 failed，不自动重试——重试语义=重新发起新 run）
- Job handle()：TenantContext::initialize(tenantId) → finally clear（对齐 AiAnalyzeJob）
- 完成写库**单事务**：report 行 + trades 批量 insert + run 标 completed（R-008 无半完成态）
- supervisor：`deploy/supervisor/worker-backtest.conf.example`（`--queue=backtest --tries=1`，autorestart）
