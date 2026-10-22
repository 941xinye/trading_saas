# Contracts: 合约原生账实对账（A5）

**Date**: 2026-10-22 | **Plan**: [plan.md](plan.md) | **Data Model**: [data-model.md](data-model.md)

本特性不暴露 HTTP API；契约面为 ① 交易所适配器 PHP 契约扩展 ② `reconcile:run` 命令行为契约 ③ diff_json 报告 schema。

## C1. ExchangeAdapter::getPositions（契约扩展）

```php
interface ExchangeAdapter
{
    /**
     * 拉取指定交易对的全部非零持仓腿（合约场景）。
     *
     * 单向持仓模式返回 0 或 1 个元素；双向（hedge）模式可返回 2 个元素。
     * 现货适配器恒返回空数组。
     *
     * @return ExchangePosition[] 仅含非零持仓腿；无持仓返回 []
     * @throws Throwable 取数失败（网络/凭据/限流）——调用方按 unknown 处理
     */
    public function getPositions(string $symbol): array;
}
```

**兼容性**：`AbstractExchangeAdapter` 提供默认实现——

```text
$position = $this->getPosition($symbol);
return $position === null ? [] : [$position];
```

既有 5 个适配器（binance spot/futures、okx、bybit、bitget）零改动获得兼容。`BinanceFuturesAdapter` 覆写为遍历 `/fapi/v2/positionRisk` 全部 `positionAmt != 0` 的行（复用既有 `getPosition` 的行映射逻辑，抽私有方法共享）。未来新合约适配器 MUST 覆写本方法以支持 hedge mode；仅依赖默认实现时单向模式正确、双向模式只取第一腿（缺陷已知，上线新适配器时按本契约补覆写）。

**DTO 不变**：`ExchangePosition.size` 为绝对值、方向在 `side`；签名还原由消费方负责（`side==='short' ? -size : +size`）。

**不改既有 `getPosition()`**：语义（第一非零腿 / null）与全部消费方（spec 005 仓位风控、StrategyRunner）保持不变。

## C2. reconcile:run 行为契约

| 项 | 契约 |
|----|------|
| 扫描范围 | `strategy_instances.status ∈ {running, paused_by_breaker, paused_by_reconcile}`，不限 market（b1edb62 护栏移除） |
| 口径路由 | `exchange_accounts.market = spot` → 既有现货口径（零行为变化）；`= futures` → 持仓口径（C3） |
| 失败语义 | futures 取持仓异常 → unknown + streak（阈值/告警/清零与现货同规则）；取 ticker 失败 → price='0' 降级分级 |
| 暂停语义 | critical 且实例为 running → `paused_by_reconcile` + 告警；`paused_by_breaker` 实例只出报告不改状态 |
| 幂等 | `(tenant_id, strategy_instance_id, period_key)` 覆盖式 upsert；告警 `DedupCooldown`（15m 默认） |
| 铁律 | 任何分级结果下 MUST NOT 自动改账、补单、平仓、改杠杆 |
| CLI | `php artisan reconcile:run [--instance=N] [--period=5m]` 参数语义不变 |

## C3. futures 比对口径（伪码契约）

```text
legs        = adapter.getPositions(symbol)          # Throwable → unknown
exchangeNet = Σ legs: side==='short' ? -size : +size  # bcmath，12 位中间精度
ordersNet   = readOrderTotals(instanceId).net_qty     # filled 单 buy−sell
diff        = exchangeNet − ordersNet
price       = adapter.getTicker(symbol).lastPrice     # Throwable → '0' 降级
status      = classifyDimension(diff, price, tolerance, critical_quote)
# diff < 0（交易所少于账本，含 flat vs 账本有仓）→ critical
# diff > 0（交易所多仓）且超容差 → drift
# |diff| ≤ qty 容差 或 等值 ≤ quote 容差 → ok；等值 ≥ critical_quote → critical
```

- 空 legs（`[]`）= 交易所无持仓（'0'），合法输入，不是 unknown
- 做空场景双方皆负：diff 在零点附近 → 正常 ok/drift/critical 判定，无需方向特判
- 杠杆/强平价/保证金/未实现盈亏不参与比对，仅 legs 明细记录

## C4. diff_json schema（futures）

见 [data-model.md](data-model.md)「diff_json 形态」。契约要点：

- 顶层 `market: "futures"` 必含（spot 侧补 `"market": "spot"`）
- `position.legs[]` 恒为数组（无持仓为空数组）
- 金额/数量字段恒为字符串（bcmath 约定）
- unknown 时 diff 为 `{"error": string, "market": "futures"|"spot"}`

## 验收映射（FR → 契约）

| FR | 契约条目 |
|----|----------|
| FR-001 扫描覆盖 | C2 扫描范围 |
| FR-002 持仓口径 | C2 口径路由 + C3 |
| FR-003 账本单维度 | C3 ordersNet |
| FR-004 分级复用 | C3 classifyDimension |
| FR-005 暂停/铁律 | C2 暂停语义 + 铁律 |
| FR-006 unknown 规则 | C2 失败语义 |
| FR-007 ticker 降级 | C2 失败语义 |
| FR-008 hedge 合成 | C1 多腿 + C3 签名合成 |
| FR-009 报告明细 | C4 |
| FR-010 现货零回归 | C2 口径路由 + C4 spot 仅补 market |
| FR-011 周期语义 | C2 幂等 + CLI |
