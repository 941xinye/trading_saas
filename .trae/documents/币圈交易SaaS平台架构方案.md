# 币圈交易 SaaS 平台架构方案

## Context

工作目录 `e:\Work\数字货币交易Saas平台` 当前为空，从零搭建。用户需求：

- **多租户 SaaS**：租户级数据隔离 + 订阅计费
- **技术栈**：Laravel 11 + Swoole + AI 分析
- **交易所可切换**：默认币安，预留 OKX/Bybit/Bitget
- **7 种策略**：现货网格 / 合约网格 / 现货 DCA / 合约 DCA / 马丁变体 / 滚仓宝 / 自定义 DSL
- **AI 信号订阅**：K 线 bar close → AI 分析 → `{交易对} {指标周期} {开单方向} {入场价格} {出场价格}` 格式通知
- **多渠道通知**：Telegram + 企微 + 邮件 + 站内 WebSocket
- **熔断保护**：单笔/日内/回撤/连亏四维度
- **首页面板**：市场热度 + 余额 + 信号 + 警告 + 多空大户爆仓

用户偏好：POC 先行。本次明确选择「先做架构骨架」——先搭完整目录/数据库表/接口契约，再按 P1-P5 顺序填 POC。

---

## 1. 技术选型决策

### 框架：Laravel 11 + Octane(Swoole) + 独立 Swoole 协程进程

**双进程模型**（关键决策）：

| 进程 | 职责 |
|---|---|
| 进程 A：Laravel Octane (Swoole HTTP) | REST API (Sanctum)、Filament 后台、Queue Worker、Reverb WebSocket Server（用户推送） |
| 进程 B：MarketFeedWorker (artisan market:feed) | 独立 Swoole 协程：交易所 WS 订阅 + K 线聚合 + 大户爆仓流订阅 + 策略调度器 |

两进程通过 Redis（共享状态/事件）+ Laravel Queue（跨进程任务）通信。Octane worker 是 prefork 模型，长连接在 worker 内会阻塞 HTTP，因此独立 Swoole Server 进程更干净。

### 存储

| 存储 | 用途 |
|---|---|
| MySQL 8.4 | 业务主库（租户/策略/订单/信号/风控/计费） |
| Redis 7.x | tick（Sorted Set）、未闭 bar（Hash）、分布式锁、令牌桶、策略状态、熔断状态机、Queue |
| ClickHouse (P4 引入) | 历史 K 线/tick 归档、大户爆仓历史、策略回测；POC 用 MySQL 兜底 |

Redis Key 命名（带 tenant 前缀，跨租户物理隔离；行情订阅类 key 平台级共享，多租户订阅去重）：
```
tenant:{tid}:strategy:{iid}:state       # 策略状态
tenant:{tid}:breaker:{iid}              # 熔断状态机
market:tick:{ex}:{sym}:{iv}             # 行情（平台共享）
market:liquidation:{sym}                # 大户爆仓聚类
```

### WebSocket 分层

| 场景 | 方案 |
|---|---|
| 租户面向推送（通知/信号/面板实时） | Laravel Reverb，私有频道 `private-tenant.{tid}.*` |
| 交易所行情被动订阅 | 进程 B 内 Swoole 协程，写入 Redis |
| 大户爆仓流 | 进程 B 内 Swoole 协程 |

不全部用 Swoole Table 自建 WS Hub：Reverb 复用 Laravel 广播认证体系，租户隔离零成本。

### AI Provider 抽象

```
AIPrompter (Interface)
  └─ AbstractAIPrompter (限流/prompt截断/超时/降级/日志)
      ├─ OpenAIAdapter
      ├─ DeepSeekAdapter
      ├─ QwenAdapter
      └─ LocalOllamaAdapter
```

**硬约束**：LLM 限流、prompt 截断、超时降级到 `RuleEngineFallback`（基于 MA/RSI/MACD/Bollinger 的确定性规则）、静默异常不阻塞主流程。

---

## 2. 多租户隔离

**模型**：单库 + `tenant_id` 行级隔离（沿用用户在 CRM/AI-API 项目已验证的约定）

**硬约束**：
- 所有业务模型挂 `BelongsToTenant` Trait，自动应用 `TenantScope`
- 所有业务表带 `tenant_id` + 复合索引 `(tenant_id, ...)`
- 中间件链：Sanctum → `BootstrapTenancy` → 注入 `TenantContext` → 全局 query scope 自动加 `WHERE tenant_id=?`
- **禁止 `withoutTenant()` 跨租户聚合**；平台级聚合走不带 trait 的 Platform 模型
- 服务层禁止 `static` 方法内缓存（Octane/queue 长驻下配置永不生效 + 测试隔离污染）

### API Key 加密：AES-256-GCM

注意 Laravel 默认 `Crypt::encryptString` 是 CBC+HMAC，非 GCM。需自定义 `AesGcmCrypt`（基于 `sodium_crypto_aead_aes256gcm_*`）。

**密钥层级**：
- KEK（主密钥）：`config/services.exchange.master_key`，从 `.env` 注入，绝不入库
- DEK（数据密钥）：每租户一个，加密存 `tenants.exchange_dek`（用 KEK 加密）
- 租户 API Key：用该租户 DEK 加密存 `exchange_accounts.encrypted_api_*`
- DEK 轮换：版本号 + lazy 重新加密 + 5 分钟解密缓存

---

## 3. 目录结构

```
app/
├── Domain/                              # 领域模块（核心）
│   ├── Tenancy/                         # 多租户基础设施
│   │   ├── BelongsToTenant.php          # Trait
│   │   ├── TenantScope.php
│   │   ├── TenantContext.php            # 单例上下文
│   │   └── BootstrapTenancy.php         # 中间件
│   ├── Exchange/                        # 交易所适配器
│   │   ├── Contracts/{ExchangeAdapter, ExchangeTicker, ExchangeKline, ExchangeOrder, ExchangePosition, ExchangeBalance}.php
│   │   ├── Adapters/{AbstractExchangeAdapter, BinanceSpotAdapter, BinanceFuturesAdapter, OkxAdapter, BybitAdapter, BitgetAdapter}.php
│   │   ├── ExchangeManager.php          # 工厂+路由
│   │   ├── RateLimiter.php              # Redis 令牌桶
│   │   └── ExchangeHealthChecker.php
│   ├── Strategy/                        # 策略引擎
│   │   ├── Contracts/{StrategyInterface, StrategyContext, StrategyState}.php
│   │   ├── Base/AbstractStrategy.php    # 生命周期+状态持久化
│   │   ├── Spot/{SpotGridStrategy, SpotDcaStrategy}.php
│   │   ├── Futures/{FuturesGridStrategy, FuturesDcaStrategy, SmartMartingaleStrategy, TrendRollingStrategy}.php
│   │   ├── Custom/{DslStrategy, DslParser}.php
│   │   ├── StrategyRegistry.php         # 按 code 解析实例
│   │   └── StrategyRunner.php            # 进程B协程调度
│   ├── Signal/                          # AI 信号订阅
│   │   ├── Contracts/{AIPrompter, Signal, SignalDispatcher}.php
│   │   ├── Prompters/{AbstractAIPrompter, OpenAIAdapter, DeepSeekAdapter, QwenAdapter, LocalOllamaAdapter}.php
│   │   ├── BarAggregator.php            # bar 聚合
│   │   ├── RuleEngineFallback.php       # AI 失败兜底
│   │   ├── SignalDispatcher.php         # 多渠道分发实现
│   │   └── PromptBuilder.php
│   ├── Risk/                            # 熔断与风控
│   │   ├── Contracts/{RiskGuard, RiskDecision}.php
│   │   ├── Breaker/{CircuitBreaker, BreakerStateMachine, BreakerMetrics}.php
│   │   ├── Rules/{MaxLossPerTradeRule, MaxDailyLossRule, MaxDrawdownRule, ConsecutiveLossRule}.php
│   │   └── RiskGuard.php                # 编排多个 Rule
│   ├── Dashboard/                       # 首页面板聚合
│   │   ├── DashboardAggregator.php     # 服务层聚合 5 类
│   │   ├── MarketHeatService.php
│   │   ├── BalanceAggregator.php
│   │   ├── SignalFeedService.php
│   │   ├── AlertFeedService.php
│   │   └── LiquidationMapService.php
│   ├── Billing/                         # 订阅计费
│   │   ├── Plans/PlanService.php
│   │   ├── Metering/{MeterEvent, TwoPhaseMeter}.php  # 预冻结+冲正
│   │   └── SubscriptionService.php
│   ├── Notification/                    # 多渠道通知
│   │   ├── Contracts/Channel.php
│   │   ├── Channels/{TelegramChannel, WechatWorkChannel, MailChannel, WebSocketChannel}.php
│   │   ├── Notifier.php                 # 编排+冷却去重
│   │   └── DedupCooldown.php
│   └── Market/                          # 行情数据
│       ├── TickStore.php                # Redis Sorted Set
│       ├── KlineStore.php               # Redis 未闭 bar + MySQL 归档
│       ├── LiquidationStore.php         # 大户爆仓聚类
│       └── FearGreedIndex.php
├── Http/{Middleware, Controllers/Api/V1, Resources}/
├── Filament/{Admin, Platform}/         # 平台超管 + 租户管理后台
├── Console/Commands/
│   ├── MarketFeedCommand.php            # 进程 B 入口
│   ├── StrategySchedulerCommand.php
│   ├── AggregateKlineCommand.php
│   ├── HealthCheckCommand.php           # 复用 AI-API
│   ├── GenerateOpenApiSpecCommand.php   # 复用 CRM
│   └── PerfBenchmarkCommand.php         # 复用 CRM
├── Jobs/{PlaceOrderJob, CancelOrderJob, AiAnalyzeJob, DispatchNotificationJob, CloseBarJob}.php
├── Events/{BarClosed, SignalGenerated, OrderFilled, BreakerTripped}.php
├── Models/                              # Eloquent + BelongsToTenant trait
├── Support/AesGcmCrypt.php
└── Providers/AppServiceProvider.php
config/{exchange, strategy, ai, risk, plans, notifications, tenancy}.php
database/migrations/                     # 见 §4
routes/{api.php (Route::name('api.') 前缀), channels.php (Reverb 授权), web.php}
lang/{en, zh_CN}/                        # i18n 不硬编码
tests/{TestCase.php (setUp setLocale('zh_CN')), Unit, Feature, Perf}/
docs/                                    # 架构/ADR/踩坑
```

---

## 4. 核心数据库表设计

### 4.1 租户与用户

**tenants**（平台级）
| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | |
| name / slug | varchar | slug 唯一 |
| exchange_dek | text | KEK 加密的 DEK |
| status | enum('active','suspended','canceled') | |
| plan_code | varchar(30) | |
| trial_ends_at | timestamp null | |

**users**：`tenant_id` / `email` / `password` / `role`(platform_admin|tenant_admin|trader|viewer)，`uniq(tenant_id, email)`

### 4.2 交易所与账户

**exchanges**（平台级）：`code`(binance|okx|bybit|bitget) / `base_url` / `ws_url` / `markets`(json) / `weight_limits`(json) / `status`(active|degraded|down)

**exchange_accounts**（租户级，挂 BelongsToTenant）：
`tenant_id` / `exchange_id` / `label` / `market`(spot|futures) / `sub_account` / `encrypted_api_key` / `encrypted_api_secret` / `passphrase_encrypted`(OKX) / `dek_version` / `permissions`(json) / `status` / `last_synced_at`
索引：`uniq(tenant_id, exchange_id, label)`

**exchange_health_checks**（平台级，沿用 AI-API）：`exchange_id` / `endpoint` / `latency_ms` / `status` / `error` / `checked_at`

### 4.3 策略

**strategies**（平台级目录）：`code`(spot_grid|futures_grid|spot_dca|futures_dca|smart_martingale|trend_rolling|custom_dsl) / `name` / `category`(spot|futures) / `risk_profile` / `default_params`(json) / `dsl_schema`(json) / `enabled`

**strategy_instances**（租户级，核心运行态）：
`tenant_id` / `strategy_id` / `exchange_account_id` / `symbol` / `interval` / `params`(json) / `allocated_capital` / `leverage` / `status`(stopped|starting|running|paused_by_breaker|error) / `breaker_state`(closed|open|half_open) / `position_snapshot`(json) / `realized_pnl` / `unrealized_pnl` / `max_drawdown_pct` / `consecutive_losses` / `started_at` / `stopped_at`
索引：`uniq(tenant_id, strategy_id, symbol)`、`idx(tenant_id, status)`、`idx(tenant_id, breaker_state)`

**strategy_orders**（租户级）：
`tenant_id` / `strategy_instance_id` / `exchange_order_id` / `client_order_id`(幂等) / `symbol` / `side`(buy|sell) / `type`(market|limit|stop|stop_market|take_profit) / `quantity` / `price` / `stop_price` / `leverage` / `reduce_only` / `status`(pending|submitted|partial|filled|canceled|rejected|failed) / `filled_qty` / `avg_fill_price` / `fee` / `error_message` / `placed_at` / `filled_at`
索引：`uniq(tenant_id, client_order_id)`、`idx(strategy_instance_id, status)`

### 4.4 信号

**signal_subscriptions**（租户级）：`tenant_id` / `name` / `symbols`(json) / `intervals`(json) / `ai_prompter`(openai|deepseek|qwen|local) / `ai_model` / `rule_fallback_enabled` / `channels`(json) / `active`

**signals**（租户级）：
`tenant_id` / `subscription_id` / `symbol` / `interval` / `direction`(long|short|neutral) / `entry_price` / `exit_price` / `stop_loss` / `confidence`(0-100) / `source`(ai|rule_fallback) / `rationale`(text) / `raw_payload`(json) / `triggered_at`
索引：`idx(tenant_id, subscription_id, triggered_at)`、`idx(tenant_id, symbol, triggered_at)`

**signal_notifications**（租户级）：`tenant_id` / `signal_id` / `channel` / `status`(queued|sent|failed|deduped) / `provider_message_id` / `error` / `sent_at`

### 4.5 风控

**risk_rules**（租户级）：`tenant_id` / `strategy_instance_id`(null=租户级默认) / `rule_type`(max_loss_per_trade|max_daily_loss|max_drawdown|consecutive_losses) / `threshold_value` / `threshold_unit`(amount|percent|count) / `cooldown_minutes` / `auto_recover` / `enabled`

**circuit_breaker_events**（租户级，审计）：`tenant_id` / `strategy_instance_id` / `from_state` / `to_state` / `rule_type` / `trigger_value` / `threshold_value` / `action_taken` / `occurred_at`

### 4.6 行情与快照

**kline_bars**（平台级共享，无 tenant_id）：`exchange_code` / `symbol` / `interval` / `open_time` / `close_time` / `open/high/low/close` / `volume` / `quote_volume` / `trades_count`
索引：`uniq(exchange_code, symbol, interval, open_time)`

**balance_snapshots**（租户级）：`tenant_id` / `exchange_account_id` / `snapshot_at` / `total_equity_usdt` / `total_margin_usdt` / `available_usdt` / `unrealized_pnl` / `assets`(json)

**positions**（租户级，缓存交易所返回持仓）：`tenant_id` / `exchange_account_id` / `strategy_instance_id`(null) / `symbol` / `side`(long|short) / `size` / `entry_price` / `mark_price` / `liquidation_price` / `leverage` / `unrealized_pnl` / `margin`

### 4.7 计费（沿用 AI-API 两阶段）

**meter_events**（租户级）：`tenant_id` / `event_type`(ai_call|order_placed|signal_sent) / `quantity` / `frozen_amount` / `settled_amount`(null) / `status`(frozen|settled|reversed) / `ref_id` / `occurred_at`

**subscriptions**（租户级）：`tenant_id` / `plan_code` / `status`(trialing|active|past_due|canceled) / `started_at` / `ends_at`

---

## 5. 核心接口契约

### 5.1 ExchangeAdapter Interface

```php
interface ExchangeAdapter
{
    public function getTicker(string $symbol): ExchangeTicker;
    public function getKline(string $symbol, string $interval, int $limit = 500): array;
    public function getBalance(): ExchangeBalance;
    public function getPosition(string $symbol): ?ExchangePosition;

    public function placeOrder(
        string $symbol, string $side, string $type,
        string $quantity, ?string $price = null, ?string $stopPrice = null,
        array $opts = []  // leverage, reduceOnly, clientOrderId
    ): ExchangeOrder;

    public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool;
    public function getOrder(string $symbol, string $orderId): ExchangeOrder;

    /** 被动订阅 K 线 stream，bar 关闭时回调 */
    public function subscribeKlineStream(
        string $symbol, string $interval,
        callable $onBar, ?callable $onTick = null
    ): string;  // subscriptionId

    public function unsubscribeStream(string $subscriptionId): void;

    /** 订阅大户爆仓流（binance forceOrder） */
    public function subscribeLiquidationStream(callable $onLiquidation): string;
}
```

`AbstractExchangeAdapter` 内置：签名生成、Redis 令牌桶限流、HTTP/Swoole 协程化、可降级异常 vs 硬失败异常分类（硬失败不计入熔断计数）。

### 5.2 StrategyInterface

```php
interface StrategyInterface
{
    public function __construct(int $instanceId, array $params, StrategyContext $context);

    public function onTick(ExchangeTicker $ticker): void;       // 高频，仅更新内存态
    public function onBar(ExchangeKline $bar): void;            // 低频，决策点
    public function onFill(ExchangeOrder $order): void;         // 订单成交回调

    public function shouldOpen(StrategyContext $ctx): ?array;   // [side, qty, price] 或 null
    public function shouldClose(StrategyContext $ctx): ?array;

    public function getState(): StrategyState;                  // 持久化到 Redis
    public function restoreState(StrategyState $state): void;   // 崩溃恢复
}
```

`AbstractStrategy` 实现 `getState/restoreState`（序列化到 `tenant:{tid}:strategy:{iid}:state`），子类只需实现 `onBar/onFill/shouldOpen/shouldClose`。

### 5.3 AIPrompter Interface

```php
interface AIPrompter
{
    /**
     * @return Signal {symbol, interval, direction, entry_price, exit_price, stop_loss, confidence, rationale}
     * @throws AiUnavailableException  上层走 RuleEngineFallback
     */
    public function analyze(ExchangeKline $latestBar, array $context): Signal;
    public function name(): string;   // openai|deepseek|qwen|local
    public function model(): string;
}
```

`AbstractAIPrompter` 内置：Redis 令牌桶限流、prompt 截断（超 context 60% 按时间窗口裁剪）、30s 超时、静默异常（report() 后抛 `AiUnavailableException`）。

### 5.4 RiskGuard Interface

```php
interface RiskGuard
{
    /** @return RiskDecision {allowed, reason, breakerState} */
    public function check(int $instanceId, string $action, array $payload = []): RiskDecision;
    public function recordTrade(int $instanceId, ExchangeOrder $fill): void;  // 驱动 4 维度计算
    public function attemptRecovery(int $instanceId): bool;                   // HALF_OPEN → CLOSED
    public function getBreakerState(int $instanceId): string;                 // closed|open|half_open
}
```

### 5.5 SignalDispatcher Interface

```php
interface SignalDispatcher
{
    public function dispatch(Signal $signal, array $channels): void;  // telegram|wechat_work|mail|websocket
}
```

实现：每个 channel 分发为独立 Job（queue=notifications），互不阻塞；冷却去重：同 (tenant, symbol, direction, interval) 在 N 分钟内只通知一次。

---

## 6. 熔断保护机制

### 四维度 Rule

| 维度 | 触发条件 | 默认阈值 | 数据来源 |
|---|---|---|---|
| 单笔最大亏损 | 单笔成交已实现亏损 ≥ 阈值 | 2% of allocated_capital | onFill 回调 |
| 日内最大亏损 | 当日累计已实现亏损 ≥ 阈值 | 5% | strategy_orders 当日聚合 |
| 最大回撤 | 历史峰值 → 当前净值回撤 ≥ 阈值 | 10% | balance_snapshots + 实时 |
| 连续亏损次数 | 连续亏损订单数 ≥ 阈值 | 5 次 | strategy_orders 状态序列 |

阈值可在 `risk_rules` 表按租户/实例自定义，覆盖 `config/risk.php` 默认值。

### 状态机（策略级，复用 AI-API 思路）

```
        单笔/日内/回撤/连亏触发
   CLOSED ─────────────────────► OPEN
     ▲                              │
     │ 自动恢复窗口到期              │
     │ (HALF_OPEN 探测)              │
     └── HALF_OPEN ◄────────────────┘
            │ 探测单盈利 → CLOSED
            │ 探测单亏损 → OPEN（重置计时）
```

状态存储：Redis Hash `tenant:{tid}:breaker:{iid}`，字段含 `state/opened_at/triggered_rule/triggered_value/attempts/next_attempt_at`。崩溃恢复从 Redis 读状态，无丢失。

### 触发后动作

1. `RiskGuard.check` 在 `Strategy.shouldOpen` 与 `ExchangeAdapter.placeOrder` 之间拦截
2. 任一 Rule 触发 → 状态转 OPEN → 调度器协程暂停该实例（status=paused_by_breaker）
3. 写 `circuit_breaker_events` 审计记录（仅业务亏损触发熔断，API 异常不触发）
4. 分发通知（带冷却去重）
5. 等待恢复窗口（`cooldown_minutes`，可配）→ 转 HALF_OPEN
6. HALF_OPEN 放行一笔探测单：盈利 → CLOSED；亏损 → OPEN 重置计时
7. 用户可在后台手动解锁（强制 CLOSED，写审计）

**关键约束**：熔断只暂停策略的"开新仓"动作，不平掉已有持仓（避免雪上加雪），已有持仓交给策略自身的止盈止损逻辑。

---

## 7. 首页面板数据聚合

`DashboardAggregator` 并发拉取 5 类数据，各子块独立缓存 TTL：

| 模块 | 数据来源 | 缓存 TTL |
|---|---|---|
| 市场热度（MarketHeatService） | BTC/ETH tick + 涨跌幅榜 Top 20 + alternative.me 恐惧贪婪指数 | 10s（行情）/30min（指数） |
| 余额（BalanceAggregator） | 跨交易所 balance_snapshots 多账户汇总 + 每 60s 定时任务拉取 | 60s |
| 交易信号（SignalFeedService） | 最近 20 条 signals + status=running 的 strategy_instances | 30s |
| 警告（AlertFeedService） | circuit_breaker_events + exchange_health_checks + strategy_orders(rejected/failed) 最近 24h | 30s |
| 多空大户爆仓（LiquidationMapService） | 币安 `wss://fstream.binance.com/ws/!forceOrder@arr` 全市场强平流 → 按 (symbol, price) 聚类 → Top 5 价格区间 | 30s |

聚合接口：`GET /api/v1/dashboard`，单接口聚合，前端一次拉取。

---

## 8. POC 拆分优先级

骨架搭完后按以下顺序填 POC：

| 阶段 | 范围 | 验证目标 | 关键风险点 |
|---|---|---|---|
| **P1 策略+下单闭环** | ExchangeAdapter + BinanceSpotAdapter + SpotGridStrategy + RiskGuard 最小版 + Redis 状态 | 单租户单策略：K 线订阅 → onBar → RiskGuard.check → placeOrder → onFill → 崩溃恢复 | Binance API 限流与签名；WS 断线重连；策略状态序列化版本兼容；订单幂等 |
| **P2 AI 信号闭环** | BarAggregator + DeepSeekAdapter + RuleEngineFallback + SignalDispatcher(Telegram+WebSocket) | bar close → AI 分析 → Signal 落库 → 多渠道分发；AI 失败时规则引擎兜底 | AI 响应延迟（30s+）导致信号过期（价差校验）；LLM JSON schema 解析容错；冷却去重边界 |
| **P3 多租户计费** | tenancy 中间件 + plans + TwoPhaseMeter + 订阅状态机 | 两租户同跑策略，数据严格隔离；AI 调用/订单按计划配额扣减 | 配额检查与下单的并发竞争（预冻结）；跨租户聚合泄露；DEK 轮换 |
| **P4 高级风控** | 4 维度 Rule 全实现 + 熔断状态机 + HALF_OPEN 探测 + 自动恢复 | 模拟连亏 → 熔断 OPEN → 通知 → 冷却 → HALF_OPEN → 探测单 → CLOSED/重置 | 回撤计算实时性；HALF_OPEN 探测单并发安全；多 Rule 优先级 |
| **P5 大户爆仓数据** | subscribeLiquidationStream + 实时聚类 + LiquidationMapService + 面板展示 | 实时显示 BTC/ETH 大户爆仓密集区 | 强平流订阅权限（公开，无需认证）；聚类算法性能（高 symbol 并发时 Sorted Set 写入压力）；历史爆仓数据获取（Binance 仅推实时） |
| **P6 扩展（可选）** | OKX/Bybit/Bitget Adapter + Custom DSL 策略 + ClickHouse 历史 K 线迁移 | 验证 Adapter 抽象的健壮性、DSL 引擎、分析层扩展 | — |

---

## 9. 关键风险与权衡

### 9.1 交易所 API 限流
复用 AI-API 的 Redis 令牌桶 RateLimiter，按 (exchange, endpoint) 维度：
- Binance 按 weight 制，Sorted Set 记录 1 分钟内已用 weight
- 触达 80% → 告警 + 暂停低优任务（如 Dashboard 行情刷新）
- 触达 100% → 拒绝该 endpoint 调用，走缓存兜底

### 9.2 WebSocket 断线重连
Swoole 协程内 retry/backoff（指数退避 1s/2s/4s/8s/16s，上限 60s）：
- 重连后从 Redis 读最后 bar 时间，REST getKline 补拉缺失 K 线
- 心跳：30s 无 tick → 主动断开重连
- 多租户订阅去重：同一 (exchange, symbol, interval) 只订阅一次

### 9.3 AI 调用延迟和失败
- 独立 queue=ai，超时 30s
- 失败/超时 → AiUnavailableException → RuleEngineFallback 兜底
- Signal 表 `source` 字段标记 ai/rule_fallback，便于审计
- LLM 限流按 (tenant, provider) 维度，超限直接走兜底
- prompt 截断超 context 60% 按时间窗口裁剪

### 9.4 策略状态持久化与崩溃恢复
- 状态双写：Redis Hash（高频读写）+ strategy_instances.position_snapshot（每 N 秒刷一次）
- 进程 B 启动：扫描 status in (running, paused_by_breaker) 的实例 → 起协程 → restoreState
- StrategyState 带 schema_version，反序列化按版本迁移
- 关键操作先 Redis 后 DB（DB 失败有 Redis 兜底）
- 订单幂等：clientOrderId = `{tenant_id}_{instance_id}_{timestamp}_{random}`

### 9.5 多租户协程隔离
- 每个策略实例一个协程，不持有跨租户静态状态
- 跨协程共享仅通过 Redis（带 tenant 前缀）
- Swoole Table 用于进程内高频共享（订阅表/限流桶），不存租户敏感数据
- Octane worker 内请求级状态按请求隔离，不缓存 tenant context

### 9.6 时钟与时间序列
- 所有时间戳 UTC 存储，前端按 tenant timezone 展示
- K 线 bar close 时间用交易所返回的 close_time（避免本地时钟漂移）
- 熔断窗口用 Redis TTL 而非本地计时（进程崩溃后窗口仍准确）

---

## 与用户既有 SaaS 约定的对齐

| 约定 | 落地位置 |
|---|---|
| BelongsToTenant Trait + TenantScope | §2 + app/Domain/Tenancy/ |
| 禁止 withoutTenant() 跨租户聚合 | Platform 面板走不带 trait 的模型 |
| 服务层禁 static 缓存（Octane） | §9.5 配置走 DB/Redis 按需读 |
| 外部服务 graceful degradation | §9.3 AI 兜底；Exchange 健康检查降级 |
| LLM 限流+截断+静默异常 | §5.3 AbstractAIPrompter |
| 通知冷却去重 | §5.5 DedupCooldown |
| 路由 Route::name('api.') 前缀 | routes/api.php |
| TestCase setUp 里 setLocale | tests/TestCase.php |
| composer.lock 锁 PHP 版本 | composer.json require php ^8.4 |
| .env.example locale=zh_CN | §3 |
| i18n 界面文案不硬编码 | lang/{en, zh_CN}/ |
| AI-API 渠道适配器+熔断器+密钥加密+两阶段计费+健康检查 | 全方案对应模块复用 |

---

## 验证方式（骨架完成后）

骨架阶段以静态可加载为验证标准：

1. **加载验证**：`php artisan octane:install --server=swoole && php artisan octane:start --test` 确认 Octane+Swoole 启动成功
2. **迁移可执行**：`php artisan migrate` 所有表创建成功，无外键顺序冲突
3. **接口契约可发现**：`php artisan route:list` 列出所有 API 路由，`php artisan openapi:generate` 生成 OpenAPI spec
4. **租户隔离单测**：`php artisan test tests/Unit/Domain/Tenancy/TenantScopeTest.php` 验证 query scope 自动注入
5. **进程 B 可启动**：`php artisan market:feed --dry-run` 启动 Swoole 协程容器，连接交易所 WS（订阅 BTCUSDT@kline_1m），日志输出 bar close 事件，但不实际下单
6. **Dashboard 聚合接口可调**：`GET /api/v1/dashboard` 返回 5 类空数据骨架，结构正确

后续 P1-P5 POC 的端到端验证见各 POC 阶段的具体验证目标。

---

## 骨架搭建步骤

按以下顺序生成代码，先基础设施再领域模块：

1. **Laravel 11 项目初始化**：composer create-project、安装 Octane/Swoole/Reverb/Sanctum/Filament 依赖、配置 .env.example（locale=zh_CN）、配置 phpunit.xml
2. **Tenancy 基础设施**：TenantContext + BelongsToTenant trait + TenantScope + BootstrapTenancy 中间件 + Sanctum 集成
3. **Support 工具**：AesGcmCrypt（sodium_crypto_aead_aes256gcm_*）
4. **数据库 migrations**：按 §4 顺序生成所有表（tenants → users → exchanges → exchange_accounts → strategies → strategy_instances → strategy_orders → signal_subscriptions → signals → signal_notifications → risk_rules → circuit_breaker_events → kline_bars → balance_snapshots → positions → meter_events → subscriptions + exchange_health_checks）
5. **Eloquent Models**：每个表对应 Model，租户级表挂 BelongsToTenant trait
6. **Domain Contracts**：§5 中所有 Interface + DTO（ExchangeAdapter/StrategyInterface/AIPrompter/RiskGuard/SignalDispatcher + 各 DTO）
7. **Domain Base/Abstract**：AbstractExchangeAdapter、AbstractStrategy、AbstractAIPrompter、CircuitBreaker 状态机骨架（实现留 P1-P5）
8. **Jobs/Events**：§3 列出的 Job/Event 类骨架（构造函数签名 + handle() 空实现）
9. **Console Commands**：MarketFeedCommand 入口（启动 Swoole 协程容器）+ 其余命令骨架
10. **Filament 后台**：Platform（平台超管）+ Admin（租户管理）两套面板骨架
11. **API 路由 + Resources**：REST 端点骨架，含 dashboard 聚合接口
12. **i18n + TestCase**：lang/{en,zh_CN}/ + tests/TestCase.php setUp setLocale
13. **docs/**：写入本方案 + ADR 记录关键决策（双进程模型、行级隔离、AES-256-GCM、熔断状态机）
