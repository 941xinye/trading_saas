# Implementation Plan: 信号自动跟单（Retro-spec）

**Branch**: `009-signal-auto-follow` | **Date**: 2026-10-21 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/009-signal-auto-follow/spec.md`

**性质**: 回溯规划。本文描述**已交付**实现的技术形态，并给出缺口的后续实施路径；不代表需要重写现有代码。

## Summary

在 `SignalPipeline` 落库信号后增加一个轻量入队判定（`SignalAutoTrader`），命中订阅配置即投递 `AutoExecuteSignalJob` 到 `orders` 队列；Job 重读订阅与实例做二次校验后，复用 `SignalExecutionService` 完成急停、租户/交易对、TTL、名义额、现货禁空、凭据、幂等等全部安全校验，并最终经 `OrderExecutor::place()` 先落库后下单。

**关键架构决策**：
- 自动跟单是「触发层新增」，不是「执行层新增」——下单逻辑零复制
- 前置判定不查库（只用已加载订阅行），把真实校验延后到 Job，降低 `ai` 队列耗时
- `tries=1` + `client_order_id` 幂等 + DB unique，三重防重复下单
- 订阅级频率/金额配置落在 `signal_subscriptions`，不新建配置表

## Technical Context

**Language/Version**: PHP 8.4 / 8.5（仓库基线）

**Primary Dependencies**: Laravel 13（Queue / Eloquent / DB）、Filament v5、bcmath、Sanctum（既有）；零新增依赖

**Storage**: MySQL 8.4 —— `signal_subscriptions` 增 5 列 + 1 索引；`strategy_orders` 作为幂等与日上限计数来源；Redis 仅用于平台急停标志

**Testing**: PHPUnit 12（`tests/Feature/Signal/`、`tests/Feature/Filament/`）

**Target Platform**: Linux 生产节点；`orders` 队列独立 worker（`queue:work redis --queue=ai,notifications,orders`）

**Project Type**: Laravel monolith 内嵌 Domain（`App\Domain\Signal`）

**Constraints**:
- 金额/数量 bcmath 字符串（constitution II）
- 自动执行不得进入行情协程进程（constitution III）
- 熔断/急停只停开仓，平仓放行（constitution V）
- 测试使用独立租户 ID，tearDown 清理 TenantContext（constitution VI）

**Scale/Scope**: 单订阅单实例；信号频率受分析周期约束；本期不含组合级资金分配

## Constitution Check

*GATE: retro 复核（as-built）*

| 原则 | 检查点 | 结论 |
|---|---|---|
| I. SDD 优先 | 原实现先于 spec；本文件为用户要求的 retro 例外，缺口另立增量 spec | PASS（有记录例外） |
| II. 金融精度 | 名义额/数量/价格全部 bcmath；`client_order_id` 使用 `sig_{signal}_{instance}` 业务幂等模板 | PASS |
| III. 协程隔离 | 入队发生在 Pipeline 内但不触交易所；下单在 `orders` 普通 worker | PASS |
| IV. 崩溃恢复幂等 | `tries=1` 防自动重试；DB unique + 状态预检防重复；`failed` 行允许同 cid 重放 | PASS |
| V. 计费/风控边界 | 复用 `OrderExecutor` 的权益/日配额闸门；急停先于一切；现货禁空；neutral 不落库 | PASS |
| VI. 测试隔离 | 测试自建租户/账户/实例；`TenantContext::clear()` 于 tearDown；`QuotaGuard` mock | PASS |
| VII. PSR-4 对齐 | 一文件一类；Job 通过 `onQueue()` 设置队列（避免重复声明 `$queue`） | PASS |

**Gate 结论**：7/7 PASS；I 的例外已显式记录。

## Project Structure

### Documentation (this feature)

```text
specs/009-signal-auto-follow/
├── spec.md          # 行为规格 + 缺口登记
├── plan.md          # 本文件（as-built 技术形态 + 缺口路径）
├── tasks.md         # 已完成/未完成任务清单
└── data-model.md    # 字段、约束、状态流
```

### Source Code (as-built)

```text
app/Domain/Signal/SignalPipeline.php        # 落库/通知后 try/catch 调用 autoTrader->maybeQueue
app/Domain/Signal/SignalAutoTrader.php      # 前置判定（纯内存）+ 投递 Job
app/Domain/Signal/SignalExecutionService.php# 全部执行期安全校验 + OrderExecutor 调用
app/Jobs/AutoExecuteSignalJob.php           # orders 队列；二次校验；日上限；日志；tries=1
app/Models/SignalSubscription.php           # fillable / casts
app/Filament/Admin/Resources/SignalSubscriptionResource.php  # 配置表单
app/Http/Controllers/Api/V1/SignalSubscriptionController.php # 写接口仍为骨架（缺口）
config/signal.php                           # ttl_minutes / default_notional / max_notional
database/migrations/2026_10_21_000009_add_auto_execution_to_signal_subscriptions_table.php

tests/Feature/Signal/SignalAutoFollowTest.php
tests/Feature/Filament/SignalSubscriptionAutoFollowFormTest.php
```

**Structure Decision**: 触发逻辑放 Domain\Signal（与 Pipeline/AutoTrader 同域），Job 放 `app/Jobs`（既有队列 Job 约定），配置 UI 复用已有 Filament Resource，不新建页面。

## 执行链路（as-built）

```text
SignalPipeline::handleSubscription()
  ├─ 分析/落库 signals
  ├─ SignalDispatcher 通知（失败不影响落库）
  └─ try { SignalAutoTrader::maybeQueue($persisted, $subscription) }
          ├─ isEligible? auto_execute=1 / instance>0 / long|short / confidence>=min
          └─ AutoExecuteSignalJob::dispatch(tenantId, signalId, subscriptionId)->onQueue('orders')

AutoExecuteSignalJob::handle()
  ├─ TenantContext::initialize(tenantId)
  ├─ 重读订阅（id+tenant+active=1）→ auto_execute=1
  ├─ 重读信号（tenant 作用域）→ long|short → 置信度
  ├─ 重读实例（tenant 作用域）→ status=running
  ├─ dailyLimitReached?（sig_ 前缀 + 有效状态 + 当日）
  ├─ notional = execution_notional ?? config default
  ├─ SignalExecutionService::execute()
  │    ├─ PlatformKillSwitch
  │    ├─ 同租户 / 方向 / 同交易对 / running / TTL / 名义额
  │    ├─ 账户存在 / 凭据解密 / 现货禁空
  │    ├─ 幂等预检 sig_{signal}_{instance}
  │    ├─ 参考价 entry_price ?? ticker
  │    └─ OrderExecutor::place()（权益/配额 → 对齐 → 落库 → 下单）
  └─ finally TenantContext::clear()
```

## 缺口实施路径（后续迭代建议）

| 优先级 | 缺口 | 建议落点 |
|---|---|---|
| ✅ 已完成 | 订阅 REST 写接口（FR-026） | `SignalSubscriptionController` 三写方法 + 租户作用域 + Resource 回显；安全测试已补（急停/跨租户/TTL/现货做空/日上限 0/幂等重放） |
| P1 | 队列故障降级测试 | 补「入队异常不影响信号落库」用例（当前仅代码 try/catch 保障） |
| P1 | 单日资金总额上限（FR-027） | `config/signal.php` 增 `execution.daily_notional_cap`；Job 内按当日 `sig_` 订单名义额求和（需在 `strategy_orders` 记录 notional 或由 qty×price 估算，优先新列） |
| P1 | 审计与回放（FR-028） | 新表 `signal_auto_follow_audits`（signal/subscription/instance/decision/reason/cid），Job 每次分支落一行 |
| P2 | 配置版本号（FR-029） | 订阅加 `execution_config_version`，入队携带版本，Job 比对不一致即跳过 |
| P2 | 成交通知（FR-030） | 复用 `SignalDispatcher` 渠道，新增「自动跟单已执行」模板 |

## 风险与备注

- **日上限计数口径**：当前按实例统计全部 `sig_` 订单（人工 + 自动共用），因此人工执行会占用自动跟单额度；这是有意为之（防止绕过频率上限），需在 UI 提示
- **名义额校验位置**：单笔上限在 `SignalExecutionService`，不在 Job；若未来新增批量执行入口，必须继续复用该服务
- **入场价回退**：无 `entry_price` 时取 ticker，可能带来滑点偏差；不影响安全边界，但在审计中应记录参考价来源
