# Implementation Plan: 合约网格策略 + 仓位级风控

> spec 005 plan 阶段实施计划。沿用 P1–P5 + spec 001-004 的 SDD 流程：测试先行、一子项一提交、文档同步。

## 1. 工作流拆解

按依赖关系分为 6 个工作流（US 映射），建议顺序执行（WS1→WS6）：

```
WS1: 数据层 + 配置（FR-001/002/010/011）
  └─ WS2: BinanceFuturesAdapter 交易接口补全（FR-005）
       └─ WS3: 强平价计算器 + 仓位风控监控器（FR-003/004/007/008/012）
            └─ WS4: FuturesGridStrategy 实现（FR-001/006）
                 └─ WS5: StrategyRunner/OrderExecutor 接线 + 熔断语义（FR-007/008/009）
                      └─ WS6: 售卖门控回归 + 事件通知 + 冒烟验证（FR-009/010 + SC-001~007）
```

## 2. 各工作流范围

### WS1: 数据层 + 配置

| 产出 | 文件 |
|---|---|
| migration: strategy_instances status 枚举追加 `paused_by_position_risk` | `database/migrations/*_add_paused_by_position_risk_to_strategy_instances.php` |
| migration: strategy_orders 新增 leverage / reduce_only 列 | `database/migrations/*_add_leverage_and_reduce_only_to_strategy_orders.php` |
| migration: position_risk_events 表 | `database/migrations/*_create_position_risk_events_table.php` |
| config/risk.php 新增 position 块 | `config/risk.php` |
| config/exchange.php 新增 futures 块 | `config/exchange.php` |
| config/strategy.php implemented 加入 futures_grid | `config/strategy.php` |
| config/plans.php pro/enterprise 加入 futures_grid | `config/plans.php` |
| .env.example 新增 RISK_POSITION_* / EXCHANGE_BINANCE_FUTURES_* | `.env.example` |

**验收**：`php artisan migrate` 成功；`config('risk.position')` / `config('exchange.exchanges.binance.futures')` 可读。

### WS2: BinanceFuturesAdapter 交易接口补全

| 产出 | 文件 |
|---|---|
| 补全 subscribeKlineStream（fstream WS，kline 频道） | `app/Domain/Exchange/Adapters/BinanceFuturesAdapter.php` |
| 补全 getTicker / getKline / getBalance / getPosition | 同上 |
| 补全 placeOrder（支持 leverage/reduceOnly） | 同上 |
| 补全 cancelOrder / getOrder / getOrderByClientOrderId | 同上 |
| 补全 symbolFilters（exchangeInfo） | 同上 |
| 新增私有 setLeverage / setMarginType / getLeverageBracket | 同上 |
| 错误码翻译扩展（-2026/-2027/-4028/-4067/-4046） | `app/Domain/Exchange/Adapters/BinanceFuturesAdapter.php::translateErrorCode` |

**复用**：`BinanceRequestSigner`（已有）、`AbstractExchangeAdapter::httpSigned/httpGet`（已有）、WS 重连范式（现货已有）。

**验收**：`tests/Domain/Exchange/BinanceFuturesAdapterTest` 覆盖 REST 行情/账户/下单/撤单/查单 + WS kline + 杠杆设置 + 错误码翻译。

### WS3: 强平价计算器 + 仓位风控监控器

| 产出 | 文件 |
|---|---|
| `LiquidationPriceCalculator` 接口 + `IsolatedLiquidationPriceCalculator` 实现 | `app/Domain/Risk/Contracts/LiquidationPriceCalculator.php` + `app/Domain/Risk/Breaker/IsolatedLiquidationPriceCalculator.php` |
| `PositionRiskDecision` DTO | `app/Domain/Risk/Contracts/PositionRiskDecision.php` |
| `PositionRiskMonitor` 接口 + 实现 | `app/Domain/Risk/Contracts/PositionRiskMonitor.php` + `app/Domain/Risk/Position/PositionRiskMonitor.php` |
| `PositionRiskAlert` 事件 | `app/Events/PositionRiskAlert.php` |
| `position_risk_events` 表写入（PositionRiskEventRepository） | `app/Domain/Risk/Position/PositionRiskEventRepository.php` |

**核心逻辑**：
- 强平价公式（多/空）+ 保证金率计算（bcmath）
- 三档阈值判定（warning/action/emergency）
- 单飞锁（Redis SET NX EX）
- 15min 通知去重

**验收**：
- Unit：强平价公式边界用例（1x/5x/10x/20x 杠杆，多/空，各 MMR）
- Unit：保证金率随 markPrice 移动的单调性
- Integration (@redis)：三档动作触发 + 单飞锁 + 去重

### WS4: FuturesGridStrategy 实现

| 产出 | 文件 |
|---|---|
| `FuturesGridStrategy` 类（onBar/onFill/shouldOpen/shouldClose/state/hydrate） | `app/Domain/Strategy/Futures/FuturesGridStrategy.php` |
| `StrategyRegistry` 注册 futures_grid | `app/Domain/Strategy/StrategyRegistry.php` |
| 崩溃恢复 getPosition 核对逻辑 | `app/Domain/Strategy/Futures/FuturesGridStrategy.php` |

**决策逻辑**（见 research.md §3.2）：
- 无持仓 + 下行 → 开多（BUY, reduceOnly=false）
- 无持仓 + 上行 → 开空（SELL, reduceOnly=false）
- 持多 + 上行 → 平多（SELL, reduceOnly=true）
- 持空 + 下行 → 平空（BUY, reduceOnly=true）
- 持多 + 下行 → 加多（BUY, reduceOnly=false）
- 持空 + 上行 → 加空（SELL, reduceOnly=false）

**状态快照**（schema_version=2）：`grid_step` / `last_index` / `position{side,size,entry_price,leverage,liquidation_price,maint_margin_rate}` / `pending_signal`

**验收**：
- Unit：6 种开平仓决策用例 + state/hydrate 往返 + 崩溃恢复 pending 单
- Integration (RecordingAdapter)：端到端 onBar→shouldOpen→placeOrder→onFill

### WS5: StrategyRunner / OrderExecutor 接线 + 熔断语义

| 产出 | 文件 |
|---|---|
| `OrderExecutor.place()` 透传 reduceOnly/leverage + 落库 | `app/Domain/Strategy/OrderExecutor.php` |
| `StrategyRunner` onBar 回调接入 PositionRiskMonitor | `app/Domain/Strategy/StrategyRunner.php` |
| `StrategyRunner` 检测 `paused_by_position_risk` 状态 | 同上 |
| `RiskGuard.check('close')` 合约语义确认（无改动，仅测试） | `tests/Integration/Risk/FuturesBreakerSemanticsTest.php` |

**关键约束**：
- getPosition 调用节流（30s），有持仓才调用
- 减仓/全平单走 OrderExecutor（含计费闸门+熔断放行）
- `paused_by_position_risk` 状态下 shouldOpen 返回 null

**验收**：
- Integration：仓位紧急状态下开仓被拒、平仓放行
- Integration：减仓单 reduceOnly=true 透传到 adapter
- Integration：合约下单走计费闸门（配额耗尽拦截、受理结算、拒绝冲正）

### WS6: 售卖门控回归 + 事件通知 + 冒烟验证

| 产出 | 文件 |
|---|---|
| `NotifyPositionRiskAlert` Listener | `app/Listeners/NotifyPositionRiskAlert.php` |
| 售卖门控回归测试（PlanStrategyGateTest 扩展） | `tests/Integration/Billing/PlanStrategyGateTest.php` |
| 白名单结构性断言（implemented 与 plans 交集） | `tests/Feature/StrategyWhitelistIntegrityTest.php` |
| `poc:verify-futures` 离线断言命令 | `app/Console/Commands/VerifyFutures.php` |
| `poc:futures-testnet-setup` 装配命令 | `app/Console/Commands/FuturesTestnetSetup.php` |

**验收**：
- 白名单结构性断言：任意套餐 strategies ⊆ config('strategy.implemented')
- pro 租户可创建 futures_grid，free 被拒
- `poc:verify-futures` 离线断言全绿（强平价/决策/风控/门控）

## 3. 测试矩阵

| 层 | 文件 | 分组 | 覆盖 FR |
|---|---|---|---|
| Unit | `LiquidationPriceCalculatorTest` | default | FR-003/011 |
| Unit | `FuturesGridStrategyTest` | default | FR-001/006 |
| Unit | `FuturesGridStateHydrationTest` | default | FR-006 |
| Integration | `BinanceFuturesAdapterTest` | default | FR-005 |
| Integration | `PositionRiskMonitorTest` | redis | FR-003/004/012 |
| Integration | `FuturesOrderExecutorTest` | default | FR-009 |
| Integration | `FuturesBreakerSemanticsTest` | default | FR-007/008 |
| Integration | `FuturesRiskEndToEndTest` | default | FR-001~008 |
| Feature | `StrategyWhitelistIntegrityTest` | default | FR-010 |
| Feature | `FuturesGatewayTest` | default | FR-010 |
| Smoke | `poc:verify-futures` | smoke | SC-001~007 |

## 4. 关键风险与缓解

| 风险 | 缓解 |
|---|---|
| BinanceFuturesAdapter 改动面大（9 个方法） | 复用现货范式，逐方法 TDD；错误码翻译独立测试 |
| 强平价与交易所偏差 | 以 positionRisk.liquidationPrice 为准，平台预估仅预警 |
| getPosition 阻塞 REST 拖慢 onBar | 30s 节流 + 仅持仓时调用；后续可拆独立协程 |
| 减仓与网格平仓并发 | Redis 单飞锁 TTL 30s |
| testnet 与主网行为差异 | testnet 验证链路，强平逻辑靠离线断言 |
| 售卖门控回归遗漏 | 结构性断言白名单 ⊆ implemented |

## 5. 提交节奏

按工作流粒度提交，每个提交包含：实现 + 测试 + 文档同步（README 技术债索引）。

1. `feat(spec-005): WS1 数据层 + 配置`
2. `feat(spec-005): WS2 BinanceFuturesAdapter 交易接口补全`
3. `feat(spec-005): WS3 强平价计算器 + 仓位风控监控器`
4. `feat(spec-005): WS4 FuturesGridStrategy 实现`
5. `feat(spec-005): WS5 Runner/Executor 接线 + 熔断语义`
6. `feat(spec-005): WS6 售卖门控回归 + 事件通知 + 冒烟命令`
