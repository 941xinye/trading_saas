# Data Model: 自定义 DSL 策略引擎（P7）

> spec 006 plan 阶段数据模型设计。**本期不新增数据库表、不新增 migration**：DSL 脚本存于既有 `strategy_instances.params['dsl_script']`，运行状态纳入既有 `position_snapshot`（新增 `dsl` 扩展键）与既有 Redis 状态键。新增物为编译期 AST 模型、运行时上下文/结果模型与配置块。

## 1. 总览

| 数据 | 载体 | 说明 |
|---|---|---|
| DSL 脚本（源文本） | `strategy_instances.params.dsl_script`（现有 `params` cast `array`） | 实例级快照语义（spec Assumptions） |
| 编译产物（AST） | 内存（装载期构建，不落库） | `DslProgram`，不可变 |
| 运行状态扩展 | `position_snapshot.dsl`（既有 JSON 列） | schema_version 升至 2 |
| Redis 状态 | 既有键 `tenant:{tid}:strategy:{iid}:state` | 不新增键 |
| 校验诊断 | 内存（编辑器/装载期返回） | `DslDiagnostic[]` |
| 配置 | `config/strategy.php`（新增 `dsl` 块）/ `config/plans.php` | 见 §6 |

> 无新表理由：spec Key Entities 的「DSL 策略实例」明确挂在既有 `strategy_instances`；脚本+参数+状态可完整表达于既有列，新增表反而引入无必要的双写与迁移面。

## 2. 编译期 AST 模型（不可变）

### 2.1 DslProgram

```json
{
  "version": 1,
  "sourceHash": "sha1(raw_script)",
  "meta": { "name": "MA 金叉开多", "symbol": "BTCUSDT", "interval": "1m", "direction": "long", "trigger": "on_bar" },
  "filters": [RuleNode],
  "entries": [RuleNode],
  "exits":   [RuleNode],
  "risk": DslRisk
}
```

| 字段 | 类型 | 约束 |
|---|---|---|
| `version` | int | MUST 为 1（未来版本走 schemaVersion 演进） |
| `sourceHash` | string | `sha1(原始脚本字节)`；恢复时比对 `compiled_hash` |
| `meta.name` | string | 非空，≤ 64 |
| `meta.symbol` | string | 非空，与实例 symbol 一致 |
| `meta.interval` | string | 非空，∈ 平台支持周期 |
| `meta.direction` | string | `long` \| `short` \| `both` |
| `meta.trigger` | string | `on_bar` \| `on_tick` \| `on_fill`，默认 `on_bar` |
| `filters`/`entries`/`exits` | RuleNode[] | 各段 ≤ `max_rules_per_section`；`entries`/`exits` 非空 |

### 2.2 RuleNode

```json
{
  "action": "open_long",
  "condition": ConditionNode,
  "qty": "0.00100000",
  "qtyPct": null,
  "note": "金叉开多"
}
```

| 字段 | 类型 | 约束 |
|---|---|---|
| `action` | string | ∈ `open_long\|open_short\|close_long\|close_short\|noop` |
| `condition` | ConditionNode | `action != noop` 时必填；`noop` 允许缺省（视为 `{op:true}`） |
| `qty` | string\|null | bcmath 字符串；与 `qtyPct` **二选一**（开/平仓动作适用） |
| `qtyPct` | string\|null | 分数（如 `"0.30"`）；与 `qty` 二选一。语义**按动作族**：开仓 = 分配资金名义额比例，base qty = `bcdiv(bcmul(capital, pct, 8), refPrice, 8)`；平仓 = 当前持仓 base 数量比例，qty = `bcmul(positionQty, pct, 8)` |
| `note` | string\|null | 可选备注 |

### 2.3 ConditionNode（按 `type` 判别）

```json
{ "type": "comparison", "op": "gt",  "left": OperandNode, "right": OperandNode }
{ "type": "cross",      "op": "cross_above", "left": OperandNode, "right": OperandNode }
{ "type": "logical",    "op": "and",  "operands": [ConditionNode, ...] }
{ "type": "logical",    "op": "not",  "operand": ConditionNode }
{ "type": "always" }
```

| type | op 取值 | 子节点 |
|---|---|---|
| `comparison` | `gt\|gte\|lt\|lte\|eq\|ne` | `left`/`right`: OperandNode |
| `cross` | `cross_above\|cross_below` | `left`/`right`: OperandNode |
| `logical` | `and\|or` | `operands`: ConditionNode[]（≥1） |
| `logical` | `not` | `operand`: ConditionNode |
| `always` | — | 无 |

### 2.4 OperandNode（按 `type` 判别）

```json
{ "type": "indicator", "indicator": "ma", "period": 7 }
{ "type": "indicator", "indicator": "rsi", "period": 14 }
{ "type": "indicator", "indicator": "macd", "source": "hist" }
{ "type": "indicator", "indicator": "bollinger", "band": "upper", "period": 20, "std_dev": "2" }
{ "type": "indicator", "indicator": "atr", "period": 14 }
{ "type": "price", "price": "close" }
{ "type": "const", "const": "123.45" }
```

| type | 字段 | 约束 |
|---|---|---|
| `indicator` | `indicator` ∈ `ma\|rsi\|macd\|bollinger\|atr`；`period` 正整数(1..`indicator_window_max`)；`macd.source` ∈ `dif\|dea\|hist`；`bollinger.band` ∈ `upper\|middle\|lower`，`std_dev` 字符串 | 未知指标/枚举即拒 |
| `price` | `price` ∈ `open\|high\|low\|close\|last` | `last` 取最新 ticker/tick |
| `const` | `const` 为数字字符串 | 仅信号域比较 |

### 2.5 DslRisk（risk 段映射）

| 键 | 类型 | 默认 | 语义 |
|---|---|---|---|
| `max_position_pct` | string | null | 开仓聚合上限（分配资金分数）；超出即拒/截断 |
| `cooldown_bars` | int | 0 | 上次信号后冷却 bar 数 |
| `stop_loss_pct` | string | null | 相对持仓开仓价的止损分数（触发合成平仓） |
| `take_profit_pct` | string | null | 相对持仓开仓价的止盈分数 |
| `max_entries_per_bar` | int | 1 | 单 bar 最多开仓信号数 |

> risk 段为**白名单映射**：未知键即拒（FR-002，禁止静默）；数值范围在编译期校验（周期/分式/非负）。

## 3. 诊断与异常模型

### 3.1 DslDiagnostic

```json
{ "level": "error", "line": 12, "field": "entry.0.when.op", "message": "未知运算符 'gtt'" }
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `level` | string | `error` \| `warning` |
| `line` | int | 源脚本行号（1 起） |
| `field` | string | 定位路径（顶层键 + 索引 + 子键） |
| `message` | string | 人类可读中文信息 |

### 3.2 DslValidationException

携带 `DslDiagnostic[]`；在**装载/保存/试装载期**抛出，`error` 级诊断 ⇒ 拒绝编译（FR-005）。编辑器仅收集 `warning` 时不阻断。

## 4. 运行时模型

### 4.1 DslEvalContext（解释器输入）

| 字段 | 类型 | 来源 |
|---|---|---|
| `tenantId` | int | 实例 |
| `instanceId` | int | 实例 |
| `trigger` | string | `meta.trigger`（当前被驱动的回调） |
| `bar` | ExchangeKline\|null | onBar/onTick 传入 |
| `ticker` | ExchangeTicker\|null | onTick/`last` 取值 |
| `recentBars` | ExchangeKline[] | `StrategyContext.recentBars` |
| `position` | ExchangePosition\|null | 现货本期为 null（仅快照用） |
| `indicatorSnapshot` | array | 预计算（§5） |
| `allocatedCapital` | string | 实例 `allocated_capital`（bcmath） |
| `positionSnapshot` | array\|null | 内存态持仓 |

### 4.2 DslEvalResult / DslSignal（解释器输出）

```json
{
  "signals": [
    { "section": "entry", "ruleIndex": 0, "action": "open_long", "qty": "0.00100000", "qtyPct": null, "note": null }
  ],
  "costSteps": 42
}
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `signals[].section` | string | `filter` \| `entry` \| `exit` |
| `signals[].action` | string | 动作枚举 |
| `signals[].qty` | string\|null | bcmath 字符串（`qty_pct` 已换算） |
| `signals[].qtyPct` | string\|null | 原始分数（换算前） |
| `costSteps` | int | 本次求值步数（对 `eval_step_budget` 校验） |

## 5. 指标快照与窗口

- 每 bar 计算一次：窗口长度 = `max(operand period) + 1`，上限 `indicator_window_max`（默认 150）；数据源 `recentBars`。
- 复用 `IndicatorCalculator::compute()` 输出：`close` / `ma[p]{v,prev}` / `rsi` / `macd{dif,dea,hist,hist_prev}` / `boll{upper,middle,lower}` / `atr`。
- NaN → null ⇒ 对应条件判 false（不触发）。
- `cross` 的 prev：`ma`/`macd.hist` 复用内置 prev；其余操作数用 shifted snapshot（去掉最后一根再算一次，每 bar 至多一次）。

## 6. 配置扩展

### 6.1 config/strategy.php 新增 dsl 块（保留既有 dsl_schema，不静默丢弃）

```php
'dsl' => [
    'max_script_bytes'       => 8192,
    'max_lines'              => 200,
    'max_depth'              => 8,
    'max_rules_per_section'  => 20,
    'max_indicators'         => 8,
    'eval_step_budget'       => 500,
    'indicator_window_max'   => 150,
    'allowed_triggers'       => ['on_bar', 'on_tick', 'on_fill'],
    'allowed_actions'        => ['open_long', 'open_short', 'close_long', 'close_short', 'noop'],
    'allowed_indicators'     => ['ma', 'rsi', 'macd', 'bollinger', 'atr'],
],
```

> 既有 `strategies.custom_dsl.dsl_schema` 保留为「面向客/文档」的镜像；`dsl.allowed_*` 为解释器硬闸门，两者取值 MUST 一致，MUST NOT 静默丢弃 dsl_schema。

### 6.2 config/strategy.php 白名单回归

```php
'implemented' => ['spot_grid', 'spot_dca', 'futures_grid', 'custom_dsl'],
```

### 6.3 config/plans.php 套餐白名单回归

| 套餐 | 变更 |
|---|---|
| `free` | 不变：`[spot_grid, spot_dca]`（不含 custom_dsl） |
| `pro` | 追加：`[spot_grid, spot_dca, futures_grid, custom_dsl]` |
| `enterprise` | 追加：同上 |

## 7. Redis 结构（复用既有键）

```
Key: tenant:{tid}:strategy:{iid}:state
Type: String (JSON，沿用 StrategyState)
新增（在 position_snapshot.dsl 内）:
  compiled_hash       = sha1(script)
  last_signal_bar     = <bar openTime>
  cooldown_remaining  = <int>
  pending_signal      = { section, action, qty, client_order_id } | null
  recovered           = true|false   (恢复幂等一次性标志，constitution IV)
```

## 8. 迁移与部署顺序（无 migration）

1. `config/strategy.php`：新增 `dsl` 块 + `implemented` 追加 `custom_dsl`
2. `config/plans.php`：pro/enterprise 追加 `custom_dsl`
3. 代码层：填充 `DslParser` / `DslStrategy` + 新增 AST/解释器/预览服务
4. 结构性断言测试：任一套餐 strategies ⊆ `config('strategy.implemented')`

> 无 schema 变更、无数据回填；既有实例不受影响（`custom_dsl` 此前不可运行/售卖）。
