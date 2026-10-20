# Quickstart: 自定义 DSL 策略引擎（P7）

> spec 006 验证指南。分三层：离线断言（无外部依赖）+ 集成（需 Redis/MySQL）+ 真实链路（行情驱动，依赖 testnet 可达性，坑 #23）。

## 前置条件

- PHP 8.4+ / Composer
- Redis 7+（实例状态/恢复幂等标志）
- MySQL 8.4（复用既有 `strategy_instances` / `strategy_orders`，本期无新表）
- 无需 Binance key 即可跑离线断言；真实链路需 testnet（可选）

## 1. 一条合法脚本（先看这个）

`specs/006-custom-dsl-strategy/contracts.md` §10 的「MA 金叉开多」为本期标准示例，可直接粘进编辑器：

```yaml
version: 1
name: "MA 金叉开多"
symbol: "BTCUSDT"
interval: "1m"
direction: long
trigger: on_bar

filter:
  - action: noop
    when:
      op: gt
      left: { price: close }
      right: { const: "0" }

entry:
  - action: open_long
    when:
      op: cross_above
      left: { indicator: ma, period: 7 }
      right: { indicator: ma, period: 25 }
    qty_pct: "0.30"

exit:
  - action: close_long
    when:
      op: cross_below
      left: { indicator: ma, period: 7 }
      right: { indicator: ma, period: 25 }
    qty_pct: "1.0"

risk:
  max_position_pct: "0.50"
  cooldown_bars: 1
  stop_loss_pct: "0.02"
  take_profit_pct: "0.05"
```

预期：编译成功；`on_bar` 上 MA7 上穿 MA25 → `open_long` 信号（开仓 `qty_pct` 换算 base qty = `bcdiv(bcmul(allocatedCapital, "0.30", 8), bar.close, 8)`）；下穿 → `close_long`（平仓 `qty_pct` 换算 base qty = `bcmul(positionQty, "1.0", 8)`）。

## 2. 离线断言（无外部依赖）

```bash
# 子集解析 + 预扫描拒绝 + 定位诊断
php artisan test tests/Unit/Strategy/Custom/DslYamlParserTest

# 结构校验（顶层键白名单/四段/枚举/范围/超限）
php artisan test tests/Unit/Strategy/Custom/DslCompilerTest

# 沙箱求值（比较/交叉/逻辑/恒真 + NaN→null + step budget）
php artisan test tests/Unit/Strategy/Custom/DslInterpreterTest

# DslStrategy 生命周期 + state(v2) 往返 + v1→v2 迁移
php artisan test tests/Unit/Strategy/Custom/DslStrategyLifecycleTest tests/Unit/Strategy/Custom/DslStateMigrationTest

# 精度边界（qty_pct 极小/极大/尾数）
php artisan test tests/Unit/Strategy/Custom/DslPrecisionTest
```

预期：全部 PASS，无 skipped。

## 3. 集成测试

```bash
# 沙箱红线语料库（eval/exec/文件/网络/反射/未知原语，parse + runtime 双处拒绝）
php artisan test tests/Integration/Strategy/DslSandboxRedlineCorpusTest

# 端到端下单闭环（RecordingAdapter：onBar→shouldOpen→place→onFill）
php artisan test tests/Integration/Strategy/DslOrderExecutorTest

# 内建策略等价性（SpotDca / SpotGrid parity）
php artisan test tests/Integration/Strategy/DslEquivalenceTest

# 预览一致性（预览信号序列 == 实跑信号序列）
php artisan test tests/Integration/Strategy/DslPreviewConsistencyTest

# 崩溃恢复（需 Redis）
php artisan test --group=redis tests/Integration/Strategy/DslCrashRecoveryTest

# 计费/熔断语义（配额零调用、OPEN 下平仓放行）（需 Redis）
php artisan test --group=redis tests/Integration/Strategy/DslBillingBreakerTest
```

预期：Redis 在线全绿；离线时 `@redis` 组 skipped，其余 PASS。

## 4. 门控与编辑器

```bash
# 白名单结构性断言（任一套餐 strategies ⊆ implemented）
php artisan test tests/Feature/StrategyWhitelistIntegrityTest

# pro/enterprise 可创建 DSL、free 被拒
php artisan test tests/Feature/Billing/DslPlanGateTest

# 编辑器即时校验（含错误 DSL 返回 行/字段 定位）
php artisan test tests/Feature/DslEditorValidationTest
```

## 5. POC 离线全链路断言

```bash
# 一键：解析 + 沙箱 + 生命周期 + 等价性 + 预览一致性 + 门控 + 计费
php artisan poc:verify-dsl
```

预期：`ALL CHECKS PASSED`。

## 6. 真实链路（需 testnet 可达）

```bash
# 建一个 DSL 实例（脚本取自 §1）
php artisan tinker
>>> $s = App\Models\Strategy::where('code','custom_dsl')->first();
>>> App\Models\StrategyInstance::create([... 'params' => ['dsl_script' => <§1 脚本>] ...]);

# 启动行情调度（长驻，独立协程）
php artisan market:feed
```

观察日志：装载期编译成功；MA 金叉时产出 `open_long` 信号并下单；成交回报更新持仓快照。

## 7. 崩溃恢复验证

```bash
php artisan market:feed &
kill -9 %1
php artisan market:feed
```

预期：`position_snapshot.dsl`（`compiled_hash`/`last_signal_bar`/`cooldown_remaining`）正确重放；pending 单核实无重复下单（`clientOrderId` 幂等，constitution IV）。

## 8. 验收清单（对照 SC-001~010）

| SC | 验证方式 | 状态 |
|---|---|---|
| SC-001 全周期闭环 | `DslOrderExecutorTest` | ⬜ |
| SC-002 语义等价 | `DslEquivalenceTest`（SpotDca/SpotGrid） | ⬜ |
| SC-003 沙箱红线 | `DslSandboxRedlineCorpusTest` | ⬜ |
| SC-004 非法 DSL 拒绝 | `DslYamlParserTest`/`DslCompilerTest` | ⬜ |
| SC-005 幂等与恢复 | `DslCrashRecoveryTest` | ⬜ |
| SC-006 预览一致性 | `DslPreviewConsistencyTest` | ⬜ |
| SC-007 计费/风控 | `DslBillingBreakerTest` | ⬜ |
| SC-008 门控回归 | `StrategyWhitelistIntegrityTest` | ⬜ |
| SC-009 性能上界 | `DslInterpreterTest` step budget 用例 | ⬜ |
| SC-010 精度回归 | `DslPrecisionTest` 边界用例 | ⬜ |

## 9. 已知边界

- 载体仅 YAML；TOML 不在本期（决策基线 ①）。
- YAML 为受限子集：不支持锚点/别名/tag/多文档/flow/Tab 缩进（拒绝并定位）。
- 条件为结构化映射，无字符串表达式语法。
- 首期仅现货（`custom_dsl.category=spot`）；合约 DSL 不在本期。
- 预览为轻量实现：复用 P8 `HistoricalQuery`，单 DSL/单标的/单周期，**不模拟滑点与手续费**；绩效分析（Sharpe/回撤）归 P10。
- 等价口径以 (触发 bar, side, qty) 为准，排除撮合/滑点差异。
- DSL 脚本为实例级快照；租户级共享/引用不在本期。
- `custom_dsl` 加入 pro/enterprise 套餐白名单，free 排除。
- 指标/信号用 float 域；流向 `OrderExecutor` 的 qty/price 一律 bcmath 字符串（constitution II）。
