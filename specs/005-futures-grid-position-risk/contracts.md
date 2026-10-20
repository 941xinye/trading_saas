# Contracts: 合约网格策略 + 仓位级风控

> spec 005 plan 阶段接口契约。复用现有 `ExchangeAdapter` / `StrategyInterface` / `RiskGuard` 接口面，仅新增仓位风控相关契约；`BinanceFuturesAdapter` 补全交易接口时不修改 `ExchangeAdapter` 接口（opts 扩展 leverage/reduceOnly 已就位）。

## 1. ExchangeAdapter 接口（复用，无修改）

现有 `ExchangeAdapter` 接口已包含合约所需全部方法，本期不新增接口方法：

| 方法 | 合约用途 | opts 扩展 |
|---|---|---|
| `getTicker(symbol)` | 获取最新价（minNotional 对齐用） | — |
| `getKline(symbol, interval, limit)` | 历史 K 线（启动时指标计算） | — |
| `getBalance()` | 合约账户余额（walletBalance / availableBalance） | — |
| `getPosition(symbol)` | 持仓查询（entryPrice / liquidationPrice / leverage） | — |
| `placeOrder(symbol, side, type, qty, price, stopPrice, opts)` | 开仓/平仓/减仓 | `opts['leverage']` / `opts['reduceOnly']` / `opts['clientOrderId']` |
| `cancelOrder(symbol, orderId, clientOrderId)` | 撤单 | — |
| `getOrder(symbol, orderId)` | 查单 | — |
| `getOrderByClientOrderId(symbol, clientOrderId)` | 崩溃恢复核单 | — |
| `subscribeKlineStream(symbol, interval, onBar, onTick)` | K 线流（合约与现货同频道格式） | — |
| `subscribeLiquidationStream(onLiquidation)` | 强平流（已实现） | — |

**BinanceFuturesAdapter 需补全的方法**（当前抛 LogicException）：
- `subscribeKlineStream` — 复用现货 WS 循环范式，URL 切到 `futures_ws_url`
- `getTicker` — `GET /fapi/v1/ticker/price`
- `getKline` — `GET /fapi/v1/klines`（响应结构与现货一致）
- `getBalance` — `GET /fapi/v2/account`，映射 `totalWalletBalance` / `availableBalance`
- `getPosition` — `GET /fapi/v2/positionRisk`，映射 `ExchangePosition` DTO
- `placeOrder` — `POST /fapi/v1/order`，支持 `leverage` / `reduceOnly`
- `cancelOrder` — `DELETE /fapi/v1/order`
- `getOrder` — `GET /fapi/v1/order`
- `getOrderByClientOrderId` — `GET /fapi/v1/order?origClientOrderId=`
- `symbolFilters` — `GET /fapi/v1/exchangeInfo`（合约 LOT_SIZE / PRICE_FILTER / MIN_NOTIONAL）
- `alignToStep` — 复用现货实现（继承自 AbstractExchangeAdapter）

**新增私有方法**（不进接口）：
- `setLeverage(symbol, leverage): void` — `POST /fapi/v1/leverage`
- `setMarginType(symbol, marginType): void` — `POST /fapi/v1/marginType`（ISOLATED）
- `getLeverageBracket(symbol): array` — `GET /fapi/v1/leverageBracket`，缓存 MMR 分档

## 2. ExchangePosition DTO（复用，无修改）

现有 `ExchangePosition` 已覆盖合约持仓全部字段：

```php
readonly class ExchangePosition {
    public string $symbol;
    public string $side;              // long|short
    public string $size;              // 持仓数量（base）
    public string $entryPrice;        // 开仓均价
    public string $markPrice;         // 标记价
    public ?string $liquidationPrice; // 强平价（交易所权威值）
    public int $leverage;             // 杠杆
    public string $unrealizedPnl;     // 未实现盈亏
    public string $margin;            // 占用保证金
}
```

无持仓时 `getPosition()` 返回 null。

## 3. 新增：LiquidationPriceCalculator

合约强平价与保证金率纯函数计算器。

```php
namespace App\Domain\Risk\Contracts;

interface LiquidationPriceCalculator
{
    /**
     * 预估强平价（逐仓模式简化公式）。
     *
     * @param string $entryPrice       开仓均价
     * @param int    $leverage         杠杆
     * @param string $maintMarginRate  维持保证金率（如 '0.0040'）
     * @param string $side             long|short
     * @return string                  强平价（bcmath 字符串）
     */
    public function estimate(
        string $entryPrice,
        int $leverage,
        string $maintMarginRate,
        string $side,
    ): string;

    /**
     * 计算当前保证金率（维持保证金 / 仓位名义价值）。
     *
     * @param string $entryPrice
     * @param string $markPrice
     * @param string $maintMarginRate
     * @return string 保证金率（0~1，越小越危险）
     */
    public function marginRatio(
        string $entryPrice,
        string $markPrice,
        string $maintMarginRate,
    ): string;
}
```

**实现**：`App\Domain\Risk\Breaker\IsolatedLiquidationPriceCalculator`

公式（见 research.md §2）：
- 多头：`entryPrice × (1 - 1/leverage + maintMarginRate)`
- 空头：`entryPrice × (1 + 1/leverage - maintMarginRate)`
- 保证金率：`maintMarginRate × entryPrice / markPrice`

## 4. 新增：PositionRiskMonitor

仓位级风控监控器，在 StrategyRunner 的 onBar 回调中调用。

```php
namespace App\Domain\Risk\Contracts;

use App\Domain\Exchange\Contracts\ExchangePosition;

interface PositionRiskMonitor
{
    /**
     * 评估仓位风险并执行必要动作。
     *
     * @param int             $tenantId
     * @param int             $instanceId
     * @param string          $symbol
     * @param ExchangePosition|null $position 交易所持仓（无持仓为 null）
     * @param string          $markPrice 当前标记价
     * @return PositionRiskDecision|null  有动作时返回决策，无动作返回 null
     */
    public function evaluate(
        int $tenantId,
        int $instanceId,
        string $symbol,
        ?ExchangePosition $position,
        string $markPrice,
    ): ?PositionRiskDecision;
}
```

**实现**：`App\Domain\Risk\Position\PositionRiskMonitor`

内部逻辑：
1. 无持仓 → 返回 null
2. 计算 `marginRatio = maintMarginRate × entryPrice / markPrice`
3. 比对三档阈值：
   - `marginRatio > warning` → null（无动作）
   - `warning ≥ marginRatio > action` → 发通知（15min 去重），返回 `warning` 决策
   - `action ≥ marginRatio > emergency` → 减仓（reduceOnly 市价单，比例 `reduce_ratio`），返回 `reduce` 决策
   - `marginRatio ≤ emergency` → 全平（reduceOnly 市价单），返回 `emergency_close` 决策
4. 减仓/全平前获取单飞锁，失败返回 `skipped` 决策
5. 动作结果写入 `position_risk_events` 表

## 5. 新增：PositionRiskDecision DTO

```php
namespace App\Domain\Risk\Contracts;

readonly class PositionRiskDecision
{
    public function __construct(
        public string $actionType,     // warning|reduce|emergency_close|skipped
        public string $reason,         // margin_ratio_warning|margin_ratio_action|...
        public string $marginRatio,    // 触发时保证金率
        public ?string $orderQty = null,    // 减仓/全平数量
        public ?bool $reduceOnly = null,    // 减仓单标记
    ) {}
}
```

## 6. StrategyInterface 扩展点

现有 `StrategyInterface` 不新增方法。`FuturesGridStrategy` 实现以下现有方法：

| 方法 | 合约语义 |
|---|---|
| `onBar(ExchangeKline $bar)` | 计算格索引，生成开多/开空/平多/平空信号 |
| `onFill(ExchangeOrder $order)` | 更新 position 快照（side/size/entryPrice/leverage/liquidationPrice） |
| `shouldOpen(StrategyContext)` | 返回开仓信号（开多=buy、开空=sell），含 `reduceOnly=false` |
| `shouldClose(StrategyContext)` | 返回平仓/减仓信号（平多=sell、平空=buy），含 `reduceOnly=true` |
| `attachClientOrderId(cid, side, qty)` | 绑定 pendingSignal 与 cid（与现货同范式） |
| `currentPositionQty()` | 返回当前持仓 size（带方向符号：多正空负） |
| `pendingClientOrderIds()` | 崩溃恢复 pending 单列表（实现 RecoverablePendingOrders） |
| `applyRecoveredOrder(ExchangeOrder)` | 崩溃恢复核单后入账 |

**FuturesGridStrategy 特有状态**（positionSnapshot）：
- `position.side` / `position.size` / `position.entry_price` / `position.leverage`
- `position.liquidation_price` / `position.maint_margin_rate`
- `grid_step` / `last_index`（与现货网格同）

**崩溃恢复增强**：hydrate 后向交易所 `getPosition` 核对真实持仓，不一致以交易所为准并记 warning 日志（不自动改账，由对账服务处理）。

## 7. OrderExecutor 扩展

现有 `OrderExecutor.place()` 不修改方法签名，内部根据 `opts['reduceOnly']` / `opts['leverage']` 透传给 `adapter->placeOrder`，并在 `strategy_orders` 落库时写入 `leverage` / `reduce_only` 列。

```php
// place() 内部新增（在 adapter->placeOrder 调用处）：
$order = $adapter->placeOrder($symbol, $side, 'market', $qty, null, null, [
    'clientOrderId' => $clientOrderId,
    'reduceOnly' => (bool) ($opts['reduce_only'] ?? false),
    'leverage' => $opts['leverage'] ?? null,
]);
```

落库新增字段：
```php
'leverage' => $opts['leverage'] ?? null,
'reduce_only' => (bool) ($opts['reduce_only'] ?? false),
```

## 8. StrategyRunner 接线点

`runInstance` 中 onBar 回调扩展：

```php
// 现有：strategy.onBar → shouldOpen/shouldClose → RiskGuard.check → OrderExecutor → onFill
// 新增：onBar 末尾调用 PositionRiskMonitor.evaluate（有减仓/全平决策时执行）

$positionRiskDecision = $this->positionRiskMonitor->evaluate(
    $tenantId, $instanceId, $symbol,
    $adapter->getPosition($symbol),  // 注意：阻塞 REST，仅在有持仓且距上次评估 > N 秒时调用
    $bar->close,
);

if ($positionRiskDecision?->actionType === 'reduce' || $positionRiskDecision?->actionType === 'emergency_close') {
    // 执行减仓/全平单（走 OrderExecutor，reduceOnly=true）
    $this->executePositionRiskClose($positionRiskDecision, ...);
}
```

**关键约束**：`getPosition` 为阻塞 REST，仅在以下条件满足时调用：
- 实例当前有持仓（position_snapshot.side !== 'flat'）
- 距上次 `getPosition` 调用 > 30 秒（避免每 bar 打交易所）
- 不进行情协程的高频路径（与 P5.5 对账同理：阻塞 REST 放独立调度或低频调用）

> 设计决策：仓位风控评估放在 onBar 回调内但低频调用 getPosition（30s 节流），评估本身是纯函数不阻塞；减仓/全平单走现有 OrderExecutor 链路（含计费闸门+风控熔断）。若需更严格的实时性，后续可拆为独立协程订阅 markPrice WS。

## 9. 熔断语义延伸（FR-007 / FR-008）

`RiskGuard.check()` 现有语义：`action='close'` 永远放行。合约场景下：
- 开仓/加仓 → `check('open')`（受熔断拦截）
- 平仓/减仓 → `check('close')`（永远放行）

**仓位紧急状态**（`position_risk_events` 中 `emergency_close` 执行后）：
- 实例 status → `paused_by_position_risk`
- StrategyRunner 的保活循环检测到此状态时，`shouldOpen` 直接返回 null（不产生开仓信号）
- `shouldClose` 仍可触发（人工平仓）

## 10. 事件与通知

复用 `BreakerTripped` 事件的通知链（`NotifyBreakerTrip` Listener + `risk_notifications` 表），仓位风控触发时 dispatch `PositionRiskAlert` 事件：

```php
readonly class PositionRiskAlert
{
    public function __construct(
        public int $tenantId,
        public int $instanceId,
        public string $symbol,
        public string $actionType,      // warning|reduce|emergency_close
        public string $marginRatio,
        public string $markPrice,
        public ?string $liquidationPrice,
    ) {}
}
```

`NotifyPositionRiskAlert` Listener（ShouldQueue, notifications 队列）复用 `RiskNotificationJob` 的渠道分发逻辑，15min 去重（key = `sha1(instanceId|actionType)`）。
