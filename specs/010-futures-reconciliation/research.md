# Research: 合约原生账实对账（A5）

**Date**: 2026-10-22 | **Spec**: [spec.md](spec.md) | **Plan**: [plan.md](plan.md)

spec 澄清已解决口径层问题（Q1=A 单维度、Q2=A 合成净持仓）。本文件记录设计层的 5 项关键决策，全部基于既有代码事实（P5.5 ReconciliationService、spec 005 BinanceFuturesAdapter、strategy_orders 表结构），无未决 NEEDS CLARIFICATION。

## R1. market 分流点放在哪

- **Decision**: `ReconciliationService::runForInstance()` 内部按 `exchange_accounts.market` 分流：`spot` 走既有路径零改动；`futures` 走新私有方法 `reconcileFuturesPosition()`，产出 `[status, diff]` 后汇回主流程共用 `upsertReport / resetUnknownStreak / handleCritical`。
- **Rationale**: 比对之后的全部行为（报告幂等、暂停、告警去重、unknown 计数）与现货 100% 同构；分流点越靠上，现货路径被触碰越少，FR-010 零回归越可证。buildAdapter 已查 exchange_accounts，market 顺带返回，不增查询。
- **Alternatives considered**: ① 新建 `FuturesReconciliationService` 类——报告/暂停/告警逻辑被迫复制或再抽一层共享基类，复杂度无收益；② 在 `ReconcileRunCommand` 分流——命令层将感知两套口径，违反「扫描逻辑在 Service」的既有分层。

## R2. 适配器契约如何支持多腿（hedge mode）

- **Decision**: `ExchangeAdapter` 新增 `getPositions(string $symbol): ExchangePosition[]`；`AbstractExchangeAdapter` 提供默认实现（`getPosition()` 非 null 包成单元素数组，否则空数组）；`BinanceFuturesAdapter` 覆写：`/fapi/v2/positionRisk` 返回数组中全部 `positionAmt != 0` 的行各映射为一个 `ExchangePosition`。
- **Rationale**: 既有 `getPosition()` 语义被 spec 005 仓位风控与 StrategyRunner 消费（取第一非零腿），改其语义回归风险大；PHP 接口加方法本会破坏全部实现类，默认实现下沉 Abstract 基类后 5 个既有适配器（binance spot/futures、okx、bybit、bitget）零改动兼容。
- **Alternatives considered**: ① 改 `getPosition` 返回合成净持仓——破坏单向模式消费方对腿明细（entryPrice/leverage/liquidationPrice）的依赖；② 对账直接用 `getPosition`——hedge mode 下只拿到第一腿，净持仓算错，违反 FR-008。

## R3. 账本侧口径：成交净额单维度（spec 澄清 Q1=A 的落地）

- **Decision**: futures 账本侧 = `readOrderTotals($instanceId)['net_qty']`（`strategy_orders` 中 `status='filled'` 按 side 分组 SUM(filled_qty)，buy−sell 签名）。策略持仓快照（Redis 每 bar / DB 60s 双写）不参与分级，仅可作为 diff 明细记录。
- **Rationale**: 现货网格的 grids 快照是账本权威（含每格成本），但 futures_grid 快照 60s 双写存在滞后窗口，对账时点会与最新成交错开造成周期性 drift 误报；`strategy_orders` 是成交回报落库的权威流水，签名净额在算术上恒等于应有净持仓（单向与 hedge 均成立）。`readOrderTotals` 现货已在用，futures 订单同为 side∈{buy,sell} + filled_qty，reduceOnly 平仓单方向相反自然负贡献，零改动复用。
- **Alternatives considered**: ① 双维度（快照 vs 交易所 + 快照 vs 净额）——需为快照设计滞后降级规则（时间戳过期跳过），复杂度高且检测收益被仓位级风控（spec 005）覆盖；② 快照仅记录不分级——保留为 diff 明细的可选增强，不阻塞本期。

## R4. 方向语义直接复用 classifyDimension

- **Decision**: futures 差值 `diff = exchangeSignedNet − ordersNet`（实际 − 账本）传入既有 `classifyDimension($diffQty, $price, $tolerance, $criticalQuote)`，方向判定、容差、critical_quote 阈值全部复用；等值参考价仍取 `getTicker(symbol)->lastPrice`，失败降级 price='0' 仅按数量分级。
- **Rationale**: classifyDimension 的语义「diff<0（实际少于账本）→ critical（资金安全方向）；diff>0 → drift」在合约语境精确成立：交易所持仓少于账本 = 仓位莫名消失（强平/手动平仓/漏记成交）→ critical；交易所多仓 = 账本外建仓 → drift。签名坐标下做空持仓（双方皆负）差值同样在零点附近，分级逻辑无需感知方向。binance positionAmt 自带符号，但 DTO 的 `size` 已被 ltrim 为绝对值、方向在 `side` 字段，合成时按 `side==='short' ? -size : +size` 还原符号。
- **Alternatives considered**: ① 为 futures 写独立分级函数——两套分级语义漂移风险，违反「复用既有状态机」的 spec 要求（FR-004）。

## R5. 护栏移除与扫描范围恢复

- **Decision**: `reconcileableInstanceIds()` 删除 `->where('ea.market', 'spot')` 约束（保留 join 与状态过滤）；既有护栏测试 `test_reconcileable_instance_ids_excludes_futures_market` 反转为「futures 实例被纳入扫描，stopped/error/starting 仍排除」。`balance_snapshots` 写入保留（futures 的 getBalance 返回保证金权益，快照仍有运营价值）。
- **Rationale**: 护栏注释已声明「合约原生对账落地后解除」；新口径接管后 futures 实例必须回到覆盖内（FR-001/SC-001）。保留 join 是因为 market 过滤虽不再需要，但扫描排序与账户有效性校验依赖同一 join，移除 where 是最小 diff。
- **Alternatives considered**: ① 保留护栏 + 新增 futures 扫描方法——两个扫描入口，调度需调两次，无收益；② 连带去掉 join——无谓扩大 diff。

## 风险与缓解

| 风险 | 等级 | 缓解 |
|------|------|------|
| 现货 diff_json 增加 `market='spot'` 字段使既有全等断言测试变红 | 低 | implement 时先核对 ReconciliationServiceTest 断言方式；若全等断言则同步更新预期（属测试维护非行为变更） |
| hedge mode 真实账户难在测试网构造 | 中 | 集成测试用 fake adapter 注入多腿应答覆盖；真实 testnet 验证列 quickstart 可选项 |
| positionRisk 接口 weight=5，实例多时限流压力 | 低 | 与现货 getBalance(weight=5) 同量级；RateLimiter 按 exchange:endpoint 维度既有 |
| futures 适配器未实现 getPositions 覆写（未来新交易所） | 低 | Abstract 默认实现兜底（单腿语义）；新适配器上线时按 contracts.md 契约补覆写 |
