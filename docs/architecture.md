# 币圈交易 SaaS 平台架构总览

> 详细架构方案见 `.trae/documents/币圈交易SaaS平台架构方案.md`

## 核心决策

| 决策 | 选择 | 理由 |
|---|---|---|
| 框架 | Laravel 13.x + Octane(Swoole) | 最大化复用 Laravel 生态 + Octane 加速 HTTP |
| 双进程模型 | Octane HTTP + 独立 Swoole MarketFeedWorker | 长连接在 Octane worker 内会阻塞 HTTP；独立进程更干净 |
| 多租户隔离 | 单库 + tenant_id 行级隔离 | 沿用 CRM/AI-API 已验证的约定，schema 级隔离迁移/计费成本爆炸 |
| API Key 加密 | AES-256-GCM（sodium） | 认证加密（AEAD），区别于 Laravel 默认的 CBC+HMAC；KEK→DEK→API Key 三段密钥层级 |
| 熔断状态机 | CLOSED→OPEN→HALF_OPEN→CLOSED | 策略级四维度（单笔/日内/回撤/连亏），只暂停开新仓不平已有持仓 |
| AI 降级 | LLM 失败→RuleEngineFallback（MA/RSI/MACD/Bollinger） | 信号链路必须持续，AI 不可用时兜底出确定性信号 |
| 行情存储 | 实时 tick → Redis Sorted Set / 历史 K线 → MySQL（POC）→ ClickHouse（P4） | 写入频率分层：ms 级用 Redis，历史归档用列存 |
| WS 推送 | Laravel Reverb（用户推送）+ 独立 Swoole 协程（交易所行情） | Reverb 复用广播认证体系；交易所行情不入 Reverb |

## 目录结构

```
app/
├── Domain/                     ← 领域模块（核心）
│   ├── Tenancy/                ← 多租户基础设施
│   ├── Exchange/               ← 交易所适配器
│   ├── Strategy/                ← 策略引擎（7 种内置）
│   ├── Signal/                  ← AI 信号订阅与分发
│   ├── Risk/                    ← 熔断与风控（四维度）
│   ├── Dashboard/               ← 首页面板 5 类数据聚合
│   ├── Billing/                 ← 订阅计费（两阶段计量）
│   ├── Notification/            ← 多渠道通知（Telegram/企微/邮件/WS）
│   └── Market/                  ← 行情数据 Store
├── Http/
│   ├── Middleware/BootstrapTenancy.php
│   ├── Controllers/Api/V1/
│   └── Resources/
├── Filament/{Admin,Platform}/   ← 两套 Filament 面板
├── Jobs/ {PlaceOrder, CancelOrder, AiAnalyze, DispatchNotification, CloseBar}
├── Events/ {BarClosed, SignalGenerated, OrderFilled, BreakerTripped}
├── Console/Commands/ {MarketFeed, StrategyScheduler, AggregateKline, HealthCheck, OpenApiSpec, PerfBenchmark}
├── Models/                      ← Eloquent（租户级表挂 BelongsToTenant）
└── Support/AesGcmCrypt.php     ← AES-256-GCM 封装
config/ {exchange, strategy, ai, risk, plans, notifications, tenancy, octane, reverb, filament}.php
database/migrations/            ← 18 张表
routes/ {api.php, channels.php, web.php}
```

## POC 优先级

> 实测踩坑见 [pitfalls.md](pitfalls.md)。

| 阶段 | 范围 | 验证目标 | 状态 |
|---|---|---|---|
| **P1 策略+下单闭环** | BinanceSpotAdapter + SpotGridStrategy + RiskGuard 最小版 | K线订阅→onBar→RiskGuard→placeOrder→onFill→崩溃恢复 | ✅ 行情/环境装配 PASS（2026-10-13），真实下单待 testnet key |
| **P2 AI 信号闭环** | BarAggregator + DeepSeekAdapter + RuleEngineFallback + SignalDispatcher | bar close→AI 分析→Signal 落库→多渠道分发；AI 失败兜底 | 待实现 |
| **P3 多租户计费** | tenancy 中间件 + plans + TwoPhaseMeter + 订阅状态机 | 两租户同跑策略，数据严格隔离；配额扣减 | 待实现 |
| **P4 高级风控** | 4 维度 Rule 全实现 + 熔断状态机 + HALF_OPEN 探测 | 模拟连亏→熔断→通知→冷却→HALF_OPEN→探测单→CLOSED/重置 | 待实现 |
| **P5 大户爆仓数据** | subscribeLiquidationStream + 实时聚类 + LiquidationMapService | 实时显示 BTC/ETH 大户爆仓密集区 | 待实现 |

## 与既有 SaaS 约定对齐

| 约定 | 落地 |
|---|---|
| BelongsToTenant Trait + TenantScope | app/Domain/Tenancy/ |
| 禁止 withoutTenant() 跨租户聚合 | TenantScope::extend 标注限制 |
| 服务层禁 static 缓存（Octane） | TenantContext 基于 Laravel Context API |
| Route::name('api.') 前缀防冲突 | routes/api.php |
| TestCase setUp setLocale | tests/TestCase.php |
| composer.lock 锁 PHP 版本 | require php ^8.4 |
| i18n 界面文案不硬编码 | lang/{en,zh_CN}/ |
| AI-API 渠道适配器+熔断器+密钥加密+两阶段计费+健康检查 | 全方案复用 |
