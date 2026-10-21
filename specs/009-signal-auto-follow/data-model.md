# Data Model: 信号自动跟单（Retro-spec）

**Feature**: `009-signal-auto-follow` | **Date**: 2026-10-21

## 1. signal_subscriptions（扩展）

迁移：`database/migrations/2026_10_21_000009_add_auto_execution_to_signal_subscriptions_table.php`

| 字段 | 类型 | 默认 | 说明 |
|---|---|---|---|
| `auto_execute` | boolean | false | 自动跟单总开关 |
| `execution_instance_id` | unsignedBigInteger nullable | null | 执行实例；FK → `strategy_instances.id`，`nullOnDelete` |
| `execution_notional` | decimal(20,8) nullable | null | 单笔名义额（USDT）；空 = 用 `signal.execution.default_notional` |
| `execution_min_confidence` | unsignedTinyInteger | 0 | 最低置信度 0–100；0 = 不过滤 |
| `execution_daily_limit` | unsignedSmallInteger | 20 | 实例当日 `sig_` 订单上限；0 = 不限 |
| `execution_config_version` | unsignedInteger | 1 | 执行配置版本号；执行相关字段变更时模型自动 +1，用于入队与执行间隔版本比对 |

索引：`idx_ss_tenant_auto_execute (tenant_id, auto_execute)`

模型 `App\Models\SignalSubscription`：
- `fillable` 含上述 5 字段（`execution_config_version` 不入 fillable，防止批量赋值）
- casts：`auto_execute => boolean`、`execution_notional => decimal:8`、`execution_min_confidence => integer`、`execution_config_version => integer`
- `booted()` 监听 `updating`：`auto_execute` / `execution_instance_id` / `execution_notional` / `execution_min_confidence` / `execution_daily_limit` 任一变更即版本 +1

**约束语义**：
- `auto_execute=1` 时 `execution_instance_id` 必须 > 0（Filament 必填；Job 二次防御）
- `execution_instance_id` 置空后 Job 直接跳过（FR-011）
- `execution_notional` 不做正数数据库约束，由执行服务校验 > 0

## 2. signals（只读依赖）

| 字段 | 用途 |
|---|---|
| `tenant_id` | 租户隔离；Job 与实例必须一致 |
| `symbol` | 与实例交易对大小写不敏感比对 |
| `direction` | 仅 `long` / `short` 可自动执行 |
| `confidence` | 与 `execution_min_confidence` 比较 |
| `entry_price` | 参考价首选；为空回退 ticker |
| `triggered_at` | TTL 计算基准（默认 15 分钟） |

## 3. strategy_instances（只读依赖）

| 字段 | 用途 |
|---|---|
| `tenant_id` | 租户隔离 |
| `symbol` | 交易对一致性 |
| `status` | 必须为 `running` |
| `allocated_capital` | 名义额上限（>0 时生效） |
| `leverage` | 透传给 `OrderExecutor` |
| `exchange_account_id` | 解析账户与适配器 |

## 4. strategy_orders（执行结果 + 幂等锚点）

| 字段 | 用途 |
|---|---|
| `tenant_id` + `client_order_id` | 唯一键，幂等锚点 |
| `strategy_instance_id` | 日上限按实例统计 |
| `status` | pending/submitted/partial/filled 视为有效占用；failed 可重放 |
| `quantity` / `avg_fill_price` / `fee` | 成交回写 |
| `notional` | 名义额快照：下单按 `qty × 参考价`，成交后回写 `filled_qty × avg_fill_price`；日资金总额上限按此汇总 |
| `created_at` | 当日计数边界（`now()->startOfDay()`） |

**幂等键**：`sig_{signalId}_{instanceId}`

- 该键同时服务人工一键下单与自动跟单，保证「同一信号在同一实例最多成功执行一次」
- 预检：若已存在且状态 ∈ {pending, submitted, partial, filled} → 冲突拒绝
- 落库冲突：`OrderExecutor::insertPendingRow()` 捕获唯一键错误；活跃状态返回 null（幂等跳过），pending/failed 复用既有行

## 5. 配置项（config/signal.php）

| 键 | 默认 | 作用 |
|---|---|---|
| `signal.execution.ttl_minutes` | 15 | 信号可执行窗口 |
| `signal.execution.default_notional` | `'100'` | 未配置订阅名义额时的默认值 |
| `signal.execution.max_notional` | `'10000'` | 单笔名义额硬上限 |
| `signal.execution.daily_notional_cap` | `'0'`（env `SIGNAL_EXECUTION_DAILY_NOTIONAL_CAP`） | 租户单日自动跟单资金总额上限；0 = 不限；人工一键下单同计入 |

## 6. 状态流

```text
订阅配置
  auto_execute=0 ──> 入队判定失败（结束）
  auto_execute=1 + instance>0 + long/short + confidence>=min
        │
        ▼
  AutoExecuteSignalJob（orders, tries=1）
        │
        ├─ 订阅 inactive / auto_execute=0 ──> skip(info)
        ├─ instance 缺失 ──> skip(warning)
        ├─ 信号缺失 / 方向非法 ──> skip(info)
        ├─ 置信度不足 ──> skip(info)
        ├─ 实例非 running ──> skip(info)
        ├─ 日上限达到 ──> skip(warning)
        │
        ▼
  SignalExecutionService
        ├─ 急停 / 跨租户 / 交易对 / TTL / 名义额 / 现货禁空 / 凭据 ──> reject(warning)
        ├─ cid 已生效 ──> conflict(warning)
        ├─ OrderExecutor 返回 null ──> conflict(warning)
        ├─ 订单 status=failed ──> reject(warning)
        └─ 成功 ──> info + 订单回写
```

## 7. 审计表 signal_auto_follow_audits（已实现）

迁移：`database/migrations/2026_10_21_000014_create_signal_auto_follow_audits_table.php`

append-only，记录自动跟单每个决策节点；写失败仅 warning，不影响主链路。
回放：`php artisan signal:auto-follow-trace {signalId} [--tenant=]` 输出审计链 + 关联订单。

| 字段 | 类型 | 说明 |
|---|---|---|
| `tenant_id` | bigint index | 租户 |
| `signal_id` | bigint index | 信号 |
| `subscription_id` | bigint index | 订阅 |
| `instance_id` | bigint nullable | 执行实例 |
| `decision` | varchar(16) | queued/skipped/rejected/executed/failed |
| `reason` | varchar(255) nullable | 拒绝或跳过原因 |
| `client_order_id` | varchar(64) nullable | 幂等键 |
| `notional` | decimal(20,8) nullable | 名义额 |
| `reference_price` | decimal(20,12) nullable | 参考价及来源 |
| `created_at` | datetime | append-only |
