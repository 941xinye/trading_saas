# Research: 合约网格策略 + 仓位级风控

> spec 005 plan 阶段研究。目标：确认 Binance USDT 本位永续合约 API 面、强平价计算公式、仓位风控范式，为 data-model / contracts / plan 提供决策依据。

## 1. Binance USDT-M 永续合约 API 面

### 1.1 基础信息

| 项 | 值 |
|---|---|
| REST Base（主网） | `https://fapi.binance.com` |
| REST Base（testnet） | `https://testnet.binancefuture.com` |
| WS Base（主网） | `wss://fstream.binance.com/ws` |
| WS Base（testnet） | `wss://stream.binancefuture.com/ws` |
| 签名算法 | HMAC-SHA256（与现货相同，复用 `BinanceRequestSigner`） |
| 持仓模式 | 单向（one-way）/ 双向（hedge），默认单向 |
| 保证金模式 | 逐仓（isolated）/ 全仓（cross），默认逐仓 |

### 1.2 关键端点

| 方法 | 路径 | 用途 | 权重 |
|---|---|---|---|
| GET | `/fapi/v1/exchangeInfo` | 交易规则 + 合约规格（LOT_SIZE / PRICE_FILTER / MIN_NOTIONAL / 杠杆档位） | 1 |
| GET | `/fapi/v1/ticker/price` | 最新价 | 1 |
| GET | `/fapi/v1/klines` | 历史 K 线（与现货同结构） | 5 |
| GET | `/fapi/v2/account` | 账户余额（含 availableBalance / totalWalletBalance / totalMarginBalance） | 5 |
| GET | `/fapi/v2/positionRisk` | 持仓查询（含 entryPrice / markPrice / liquidationPrice / leverage / marginType / positionAmt） | 5 |
| POST | `/fapi/v1/order` | 下单（支持 `leverage` / `reduceOnly` / `newClientOrderId`） | 1 |
| DELETE | `/fapi/v1/order` | 撤单 | 1 |
| GET | `/fapi/v1/order` | 查单（orderId 或 origClientOrderId） | 1 |
| POST | `/fapi/v1/leverage` | 设置杠杆（`symbol` + `leverage`） | 1 |
| POST | `/fapi/v1/marginType` | 设置保证金模式（`ISOLATED` / `CROSSED`） | 1 |
| GET | `/fapi/v1/leverageBracket` | 杠杆档位与维持保证金率分档 | 1 |

### 1.3 下单参数差异（vs 现货）

| 参数 | 现货 | 合约 |
|---|---|---|
| `side` | BUY / SELL | BUY / SELL（语义不变，但配合 reduceOnly 决定开/平仓） |
| `type` | MARKET / LIMIT / STOP_LOSS / TAKE_PROFIT | MARKET / LIMIT / STOP / TAKE_PROFIT / STOP_MARKET / TAKE_PROFIT_MARKET |
| `quantity` | base 数量 | 合约张数（与现货同为 base 数量，USDT 本位） |
| `leverage` | 无 | 可选，不传则用当前仓位杠杆 |
| `reduceOnly` | 无 | `true` 表示仅减仓/平仓，不增加持仓 |
| `newClientOrderId` | 支持 | 支持（≤36 字符，[A-Za-z0-9._-]） |
| `positionSide` | 无 | 双向持仓模式下必填 `LONG` / `SHORT`；单向模式不传 |

**单向模式开平仓判定**（首期只支持单向）：
- 无持仓时：BUY = 开多，SELL = 开空
- 持多仓时：BUY = 加多，SELL = 减多/平多（reduceOnly=true 强制只减仓）
- 持空仓时：SELL = 加空，BUY = 减空/平空（reduceOnly=true 强制只减仓）

### 1.4 WS K 线流

与现货完全相同的频道格式：`<symbol>@kline_<interval>`，帧结构含 `k.x`（confirm 标志，true=bar 关闭）。testnet 端点 `stream.binancefuture.com` 可达。

### 1.5 关键错误码

| 码 | 含义 | 处理 |
|---|---|---|
| -2026 | REDUCE_ONLY_ORDER_TYPE_NOT_SUPPORTED | reduceOnly 与订单类型不兼容，降级为非 reduceOnly 或换类型 |
| -2027 | MAX_LEVERAGE_RATIO | 超过当前杠杆允许的最大名义价值，需降杠杆或减仓 |
| -4028 | INVALID_LEVERAGE | 杠杆值无效（不在档位范围内） |
| -4067 | 持仓模式切换失败（有未成交订单/持仓） | 重试前先撤单/平仓 |
| -4046 | NO_NEED_TO_CHANGE_MARGIN_TYPE | 已是目标保证金模式，幂等忽略 |

### 1.6 testnet 可达性

Binance Futures testnet（`testnet.binancefuture.com`）独立于现货 testnet，需要单独注册 API key（https://testnet.binancefuture.com）。本机 WSL 出口对 testnet 端点可达（坑 #23 仅主网 fstream 不可达）。

## 2. 强平价计算公式

### 2.1 逐仓模式简化公式

Binance USDT 本位永续合约逐仓模式下，强平价简化公式（小额持仓，忽略手续费与资金费率）：

```
多头强平价 = entryPrice × (1 - 1/leverage + maintMarginRate)
空头强平价 = entryPrice × (1 + 1/leverage - maintMarginRate)
```

其中 `maintMarginRate`（维持保证金率）按仓位名义价值分档：

| 名义价值区间（USDT） | BTC MMR | ETH MMR |
|---|---|---|
| 0 – 50,000 | 0.40% | 0.50% |
| 50,000 – 250,000 | 0.50% | 0.55% |
| 250,000 – 1,000,000 | 1.00% | 1.00% |
| 1,000,000 – 5,000,000 | 1.50% | 1.50% |

> 完整分档表从 `GET /fapi/v1/leverageBracket` 动态获取，平台侧缓存（24h TTL）。首期使用最小档位 MMR 做保守估算（宁可提前预警）。

### 2.2 保证金率计算

```
保证金率 = (维持保证金) / (仓位名义价值)
        = (maintMarginRate × entryPrice × size) / (markPrice × size)
        = maintMarginRate × entryPrice / markPrice
```

当保证金率 ≤ 0 时触发强平（即 markPrice 触及强平价）。

**预警/行动阈值**（config 可配，默认）：
- 预警阈值：保证金率 ≤ 50%（即 markPrice 距强平价还有 50% 空间）→ 发通知
- 行动阈值：保证金率 ≤ 20%（即 markPrice 距强平价还有 20% 空间）→ 自动减仓
- 紧急阈值：保证金率 ≤ 10% → 市价全平

> 阈值方向：保证金率越低越危险。上述百分比是"剩余安全空间"的比例，不是 MMR 本身。

### 2.3 平台侧预估 vs 交易所实际

- 平台侧用简化公式 + 最小档位 MMR 做保守估算（强平价会比交易所实际更接近当前价，即更早预警）。
- 交易所 `positionRisk.liquidationPrice` 为权威值，每次持仓查询时同步覆盖平台估算。
- 差异容忍：平台预估强平价与交易所值偏差 > 5% 时记 warning 日志，但仍以交易所值为准做风控判断。

## 3. 合约网格 vs 现货网格 差异分析

### 3.1 核心差异

| 维度 | 现货网格 | 合约网格 |
|---|---|---|
| 持仓方向 | 仅多头（买入 base） | 多空双向（做多侧开多、做空侧开空） |
| 资金占用 | 全额（investment_amount） | 保证金 = 名义价值 / 杠杆 |
| 网格信号 | 下行买入、上行卖出止盈 | 下行做多、上行做空（双向开仓）；反向穿越时平仓 |
| 平仓语义 | 卖出 = 止盈 | 平仓 = reduceOnly 反向单，释放保证金 + 已实现盈亏 |
| 强平风险 | 无 | 有，需仓位级风控 |
| 数量对齐 | LOT_SIZE stepSize | 同（合约 stepSize），但名义价值受 minNotional + 杠杆约束 |

### 3.2 合约网格决策逻辑

```
价格下行（index 减小）：
  - 当前无持仓 → 开多（BUY）
  - 持空仓 → 平空（BUY + reduceOnly）
  - 持多仓 → 加多（BUY）

价格上行（index 增大）：
  - 当前无持仓 → 开空（SELL）
  - 持多仓 → 平多（SELL + reduceOnly）
  - 持空仓 → 加空（SELL）
```

每格数量 = (margin / grid_count) / price × leverage（保证金放大）。

### 3.3 崩溃恢复差异

- 现货：grids 数组持有 qty/cost，恢复后核实 pending 买单。
- 合约：position 快照含 side/size/entryPrice/leverage/liquidationPrice，恢复后需向交易所 `getPosition` 核对真实持仓，不一致以交易所为准并记 drift。

## 4. 仓位级风控设计

### 4.1 与账户级风控的关系

| 维度 | 账户级（现有 RiskGuard） | 仓位级（新增） |
|---|---|---|
| 触发依据 | 已实现盈亏（单笔/日内/回撤/连亏） | 未实现浮亏（保证金率逼近强平） |
| 动作 | 熔断：停开新仓 | 减仓 / 市价平仓 |
| 持久化 | Redis Hash（实例级） | Redis Hash（仓位级，key 含 symbol+side） |
| 审计 | circuit_breaker_events | position_risk_events（新增） |
| 通知 | BreakerTripped 事件 → NotifyBreakerTrip | PositionRiskAlert 事件 → 复用 NotifyBreakerTrip 链 |

两者正交：账户熔断期间仓位风控仍可触发平仓（平仓永远放行）；仓位紧急状态不影响账户熔断状态。

### 4.2 风控动作执行链

```
onBar / onTick → PositionRiskMonitor.evaluate(position, markPrice)
  ├─ 保证金率 > 预警阈值 → 无动作
  ├─ 保证金率 ≤ 预警阈值 → 发通知（15min 去重）
  ├─ 保证金率 ≤ 行动阈值 → 自动减仓（reduceOnly 市价单，减 50% 仓位）
  └─ 保证金率 ≤ 紧急阈值 → 市价全平（reduceOnly）
```

**单飞约束**：减仓/平仓动作执行前获取 Redis 锁（`tenant:{tid}:position_risk:{iid}:lock`，TTL 30s），防止与网格自身平仓信号并发重复下单。锁释放后该仓位下一 bar 才能再次触发风控动作。

### 4.3 阈值配置

按交易对可配置，存 `config/risk.php` 的 `position` 块：

```php
'position' => [
    'warning_margin_ratio' => 0.50,   // 保证金率 ≤ 50% → 预警
    'action_margin_ratio'  => 0.20,   // 保证金率 ≤ 20% → 减仓 50%
    'emergency_margin_ratio' => 0.10, // 保证金率 ≤ 10% → 全平
    'reduce_ratio' => 0.50,           // 减仓比例
    'lock_ttl' => 30,                 // 单飞锁 TTL（秒）
    'default_maintenance_margin_rate' => [
        'BTCUSDT' => '0.004',
        'ETHUSDT' => '0.005',
    ],
],
```

## 5. 计费接线

合约下单复用现有 `OrderExecutor.place()` 流程：
- `freezeDaily($tenantId, 'order_placed', $clientOrderId)` —— 与现货共用同一计量事件类型
- 受理结算 / 拒绝冲正 —— 语义完全一致
- 无需新增计量事件类型，合约与现货下单共享日配额池（`orders_per_day`）

> 决策理由：合约与现货下单同属"交易所下单动作"，共享配额池符合用户心智（"今日还能下多少单"）；若需合约独立配额，留待后续 plans 配置扩展。

## 6. 售卖门控回归

落地后修改：
1. `config/strategy.php` 的 `implemented` 数组加入 `'futures_grid'`
2. `config/plans.php` 的 pro / enterprise 套餐 `strategies` 加入 `'futures_grid'`（free 套餐不含合约）
3. 新增结构性断言：`PlanStrategyGateTest` 验证白名单中每个策略 code 都在 `config('strategy.implemented')` 中

## 7. 测试策略

| 层 | 内容 | 分组 |
|---|---|---|
| Unit | 强平价公式（多/空/各杠杆/边界）、保证金率计算、阈值判定 | default |
| Unit | FuturesGridStrategy onBar 决策（开多/开空/平多/平空/加多/加空） | default |
| Unit | FuturesGridStrategy state()/hydrate() 崩溃恢复 | default |
| Integration | PositionRiskMonitor 预警/减仓/全平三档动作 + 单飞锁 | redis |
| Integration | FuturesGrid + OrderExecutor + RiskGuard 端到端（RecordingAdapter） | default |
| Integration | 仓位紧急状态下开仓拦截、平仓放行 | default |
| Feature | 售卖门控：pro 可创建 futures_grid、free 被拒；白名单结构性断言 | default |
| Smoke | Binance Futures testnet 真实下单 + 持仓查询 + 杠杆设置 | smoke |

## 8. 风险与未决项

| 项 | 风险 | 缓解 |
|---|---|---|
| 强平价与交易所实际偏差 | 平台简化公式忽略手续费/资金费率 | 以 positionRisk.liquidationPrice 为准，平台预估仅作预警参考 |
| 减仓单成交不及时 | 极端行情下滑点导致减仓后仍穿仓 | 紧急阈值直接全平，不做渐进减仓 |
| 杠杆档位 MMR 分档 | 名义价值跨档后 MMR 跳变 | 从 leverageBracket 动态获取，缓存 24h |
| testnet 与主网行为差异 | testnet 撮合/强平引擎简化 | testnet 验证下单与持仓查询链路，强平逻辑靠离线断言 |
| 减仓与网格平仓并发 | 重复平仓 | Redis 单飞锁 + reduceOnly 幂等 |
