# Data Model: 合约原生账实对账（A5）

**Date**: 2026-10-22 | **Plan**: [plan.md](plan.md)

**无新表、无迁移**。本特性复用既有存储，仅扩展 `reconciliation_reports.diff_json` 的内容形态（JSON 列，无 schema 变更）。

## 涉及实体（既有）

### reconciliation_reports（复用）

每实例每周期一行，`(tenant_id, strategy_instance_id, period_key)` 幂等覆盖。

| 字段 | 说明 | 本期变化 |
|------|------|----------|
| status | ok / drift / critical / unknown | futures 同四级，语义不变 |
| diff_json | 比对明细 JSON | futures 采用新形态（下述）；现货仅补 `market` 标识 |
| period_key | UTC 周期桶（如 20261022T0805） | 不变 |

### strategy_orders（复用，futures 账本权威数据源）

| 字段 | 对账用途 |
|------|----------|
| side | buy=+ / sell=− 签名依据（enum，已含 reduceOnly 平仓单反向语义） |
| filled_qty | 成交量，SUM 分组汇总 |
| status | 仅 `filled` 计入（在途/部分成交按既有现货口径：partial 不计，与现货一致） |

> 注：现货 `readOrderTotals` 仅取 `status='filled'`。futures 沿用同一查询，行为一致；partial 成交的处理口径与现货保持同规则（不在本期改动）。

### ExchangePosition（DTO，无持久化）

适配器返回的腿级持仓：`symbol / side(long|short) / size(绝对值) / entryPrice / markPrice / liquidationPrice / leverage / unrealizedPnl / margin`。对账仅消费 `side` 与 `size` 做签名合成；其余字段原样进 diff 明细供人工核查。

## diff_json 形态

### futures（新增）

```json
{
  "market": "futures",
  "asset": "BTC",
  "price": "67432.5",
  "position": {
    "exchange_signed_qty": "0.50000000",
    "orders_net_qty": "0.50000000",
    "diff": "0.000000000000",
    "status": "ok",
    "legs": [
      {
        "side": "long",
        "size": "0.5",
        "entry_price": "66000.0",
        "mark_price": "67432.5",
        "leverage": 5,
        "liquidation_price": "52000.0",
        "unrealized_pnl": "716.25"
      }
    ]
  },
  "tolerance": { "qty": "0.000001", "quote": "5" },
  "critical_quote": "50"
}
```

字段规则：

- `exchange_signed_qty`：多腿签名合成，`Σ(side==='short' ? -size : +size)`，bcmath 8 位展示
- `orders_net_qty`：`readOrderTotals` 的 buy−sell 签名净额
- `diff`：`exchange_signed_qty − orders_net_qty`（实际 − 账本，12 位）
- `status`：`classifyDimension(diff, price, tolerance, critical_quote)` 结果
- hedge mode 下 `legs` 数组有多元素；无持仓时 `legs=[]` 且 `exchange_signed_qty='0'`
- unknown 形态与现货一致（`{"error": "..."}`），但 MUST 补 `"market": "futures"` 标识便于检索（contracts C4）；现货 unknown 路径同步补 `"market": "spot"`（recordUnknown 扩可选 market 参数，缺省 spot 兼容）

### spot（不变，仅补标识）

既有结构（`base_qty` / `orders_net_qty` / `cost_basis` 三维）保持，顶层补 `"market": "spot"`。若既有测试对 diff 全等断言，同步更新预期（测试维护，非行为变更）。

## 数据流

```text
reconcile:run（每 5m 调度）
  └─ ReconciliationService::reconcileableInstanceIds()
       └─ strategy_instances ⋈ exchange_accounts（running/paused_*，全 market）
  └─ 逐实例 runForInstance()
       ├─ buildAdapter()（解密凭据 → ExchangeManager::adapter(code, market)）
       ├─ market = spot  → 既有路径（getBalance + 快照账本/成交净额双维度）
       └─ market = futures → reconcileFuturesPosition()
            ├─ adapter->getPositions(symbol)     ← 失败 → unknown + streak
            ├─ signedNet = Σ ±size               ← bcmath
            ├─ adapter->getTicker(symbol)         ← 失败降级 price='0'
            ├─ ordersNet = readOrderTotals()
            ├─ status = classifyDimension(signedNet − ordersNet, price, …)
            └─ writeBalanceSnapshot()（保证金权益快照，保留）
       ├─ upsertReport()（幂等覆盖）
       ├─ resetUnknownStreak()
       └─ status=critical → handleCritical()（pause + notify，不覆盖 breaker）
```

## 验证规则（来自 FR）

- 签名合成必须用 bcmath（`bcadd/bcsub`，12 位中间精度），禁止浮点（Constitution II）
- `getPositions` 返回空数组 = 无持仓 = 交易所侧 '0'，不是取数失败（区别于异常 → unknown）
- `period_key` 生成、容差读取、critical_quote 读取与现货共用同一 config 节（`reconcile.*`）
