# 技术债 A5 销账验收报告

**特性**: 合约原生账实对账（`specs/010-futures-reconciliation`）
**验收日期**: 2026-10-22
**验收结论**: **PASS（自动化层）** — 5 项成功标准全部达成；testnet 手动场景因无凭据/网络条件未执行，列为已知边界
**实施提交**: `08e152e` feat(reconcile): 合约原生账实对账（spec 010，销技术债 A5）；`ad6defc` docs 补记

---

## 1. 验收对象与依据

| 项 | 内容 |
|---|---|
| 技术债 | README 技术债表 A5：合约实例被现货口径对账排除，合约原生对账未做 |
| 前置护栏 | `b1edb62` 曾以 `reconcileableInstanceIds` 市场过滤排除合约实例，防误判 critical 误暂停 |
| 验收依据 | [spec.md](../specs/010-futures-reconciliation/spec.md) SC-001~SC-005、[quickstart.md](../specs/010-futures-reconciliation/quickstart.md) 11 场景矩阵、[contracts.md](../specs/010-futures-reconciliation/contracts.md) C1-C4 |
| 任务清单 | [tasks.md](../specs/010-futures-reconciliation/tasks.md) T001-T025 全部勾选 |

## 2. 交付物

| 层 | 文件 | 变更 |
|---|---|---|
| 契约 | [ExchangeAdapter.php](../app/Domain/Exchange/Contracts/ExchangeAdapter.php) | 新增 `getPositions(string $symbol): array`（多腿，仅非零腿） |
| 契约 | [AbstractExchangeAdapter.php](../app/Domain/Exchange/Adapters/AbstractExchangeAdapter.php) | 默认实现包装 `getPosition`，既有适配器零回归 |
| 适配器 | [BinanceFuturesAdapter.php](../app/Domain/Exchange/Adapters/BinanceFuturesAdapter.php) | 覆写 `getPositions` 逐腿返回；抽 `mapPositionRow` 与 `getPosition` 共享 |
| 编排 | [ReconciliationService.php](../app/Domain/Reconciliation/ReconciliationService.php) | `runForInstance` 按 market 分流；新增 `reconcileFuturesPosition`；`recordUnknown` 带 market；护栏移除；现货 diff 补 `market='spot'` |
| 测试 | [FuturesReconciliationTest.php](../tests/Integration/Reconciliation/FuturesReconciliationTest.php) | 新增 11 用例（合约口径全链路） |
| 测试 | [ReconciliationServiceTest.php](../tests/Integration/Reconciliation/ReconciliationServiceTest.php) | 护栏用例反转；unknown 补 C4 断言 |
| 测试 | [BinanceFuturesAdapterTest.php](../tests/Domain/Exchange/BinanceFuturesAdapterTest.php) | 新增 getPositions 覆写用例 |
| 文档 | [README.md](../README.md) / [operations.md](../docs/operations.md) | A5 标记已销；§9.5 对账范围注记更新 |

提交规模：21 文件，+1524 / −30。

## 3. 成功标准核对

| 标准 | 判定 | 证据 |
|---|---|---|
| SC-001 合约实例对账覆盖率 100% | PASS | `reconcileableInstanceIds` 全市场扫描（join 保留、状态过滤保留）；`test_reconcileable_instance_ids_includes_futures_market` 断言 futures running 纳入、stopped/error/starting 排除 |
| SC-002 误暂停归零 | PASS | futures 走 `getPositions` 持仓口径，不再用 `getBalance.assets[base]`；容差内差异 `test_ok_long` / `test_ok_short` / `test_ok_hedge` 均判 ok 且不暂停 |
| SC-003 差异检出 ≤ 1 周期 | PASS | 对账粒度不变（默认 5m 桶）；critical 当周期写 `reconciliation_reports` + `risk_notifications`（`test_critical_when_exchange_flat_but_ledger_holds_pauses_and_alerts`） |
| SC-004 现货回归 100% | PASS | `ReconciliationServiceTest` 10 passed 零失败；现货 diff 仅新增 `market` 字段 |
| SC-005 方向语义正确率 100% | PASS | 做空（−0.5 vs −0.5）、无仓（legs=[] vs +0.5）、方向反转（−0.5 vs +0.5）、hedge 多腿（long 0.5 + short 0.2 → +0.3）四类全绿 |

## 4. quickstart 11 场景矩阵逐项核对

| # | 场景 | 用例 | 结果 |
|---|---|---|---|
| 1 | ok（long） | `test_ok_long_when_exchange_position_matches_orders` | PASS |
| 2 | ok（short） | `test_ok_short_when_both_sides_negative` | PASS |
| 3 | drift | `test_drift_when_exchange_has_more_position_does_not_pause` | PASS |
| 4 | critical（强平） | `test_critical_when_exchange_flat_but_ledger_holds_pauses_and_alerts` | PASS |
| 5 | critical（方向反） | `test_critical_when_exchange_position_reversed_pauses` | PASS |
| 6 | hedge 合成 | `test_ok_hedge_mode_long_and_short_legs_net_to_match_orders` + `test_get_positions_override_returns_all_non_zero_legs` | PASS |
| 7 | unknown | `test_unknown_on_positions_failure_streaks_alerts_and_recovers` | PASS |
| 8 | ticker 降级 | `test_ticker_failure_degrades_to_qty_dimension` | PASS |
| 9 | breaker 正交 | `test_critical_does_not_override_breaker_paused_instance`（合约 + 现货两份） | PASS |
| 10 | 扫描范围 | `test_reconcileable_instance_ids_includes_futures_market` | PASS |
| 11 | 幂等 | `test_rerun_same_period_is_idempotent_single_row_and_no_duplicate_alert` | PASS |

覆盖 11/11，无缺口。

## 5. 测试证据（WSL Ubuntu，SQLite + 真实 Redis）

| 命令 | 结果 |
|---|---|
| `php artisan test --group=redis` | **91 passed (465 assertions)** |
| `php artisan test tests/Integration/Reconciliation/` | **21 passed (136 assertions)** — 合约 11 + 现货 10 |
| `php artisan test tests/Domain/Exchange/BinanceFuturesAdapterTest.php` | **17 passed (50 assertions)** |

测试先行记录：T007-T009 先红后绿；T013/T014 先红（`Undefined array key "market"` / `Failed asserting that an array contains <futures id>`）后绿；T018 先红（默认实现恒 1 元素，`assertCount(2)` 失败）后绿。

## 6. 回归与约束审计

| 检查项 | 结论 |
|---|---|
| 现货对账零回归（FR-010） | PASS — ReconciliationServiceTest 10/10；现货 diff 仅多 `market` 键 |
| `getPosition` 单向语义未动 | PASS — 抽 `mapPositionRow` 后行为等价，既有 4 用例全绿 |
| 其他适配器（OKX/Bybit/Bitget/Binance 现货） | PASS — 走默认 `getPositions` 包装，接口扩展零破坏 |
| fake 与直接实现接口的桩 | PASS — FakeExchangeAdapter / RecordingAdapter / 两处匿名类同步补齐 |
| 铁律：只报告 + 暂停 | PASS — 无任何改账/补单/平仓代码路径；critical 仅置 `paused_by_reconcile` 与告警 |
| breaker 正交 | PASS — `paused_by_breaker` 不被覆盖，仅出报告与告警 |
| bcmath 字符串金额 | PASS — 合成用 bcadd/bcsub 12 位；diff_json 金额字段恒字符串（形态断言覆盖） |
| UTC | PASS — period_key `20261015T0805` 由 UTC 秒桶生成 |

## 7. 已知边界与后续

1. **testnet 手动场景未执行**（quickstart §二）：本机无 testnet 凭据与稳定外网，以自动化层为准；待海外节点按 runbook 补测。
2. **hedge 覆写仅 BinanceFuturesAdapter**：其余交易所暂无合约适配器；新增合约适配器时须实现 `getPositions` 多腿语义（默认实现只返单腿）。
3. **调度命令层未新增单测**：`reconcile:run` 复用既有入口（`--instance` / 全量），扫描范围由 `reconcileableInstanceIds` 的单测覆盖。
4. **主网实跑收益待观察**：真实强平/漏单场景下的 drift/critical 检出率需上线后统计。

## 8. 提交与回滚

| 提交 | 说明 |
|---|---|
| `08e152e` | 特性实施（契约 + 编排 + 适配器 + 测试 + 文档） |
| `ad6defc` | spec.md 状态回填 Implemented + tasks.md T022-T025 勾选 |

回滚方式：`git revert ad6defc 08e152e`（逆序）。回滚后 `b1edb62` 的现货护栏自动恢复，合约实例重新被排除，无数据迁移风险（`reconciliation_reports` 表结构未变，仅 `diff_json` 增加 `market` 键）。
