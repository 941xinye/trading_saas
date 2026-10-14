# Trading SaaS Platform

币圈交易 SaaS 平台 —— 多租户策略交易 + AI 信号 + 实时风控 + 熔断保护。

## 快速链接

- [架构方案（完整）](.trae/documents/币圈交易SaaS平台架构方案.md)
- [架构总览](docs/architecture.md)
- [ADR 记录](docs/adr/)
- [踩坑记录](docs/pitfalls.md)
- [功能矩阵 & 路线图](#功能矩阵--路线图)
- [快速开始](#快速开始)

## 技术栈

| 层 | 选型 |
|---|---|
| 框架 | Laravel 13.x + Octane(Swoole) |
| 多租户 | 行级 tenant_id + BelongsToTenant Trait + TenantScope |
| 鉴权 | Sanctum API Token |
| WebSocket | Laravel Reverb（用户推送）+ 独立 Swoole 协程（交易所行情） |
| 数据库 | MySQL 8.4（业务主库） + Redis（行情/状态/队列/限流） + ClickHouse（P4 历史归档） |
| AI 分析 | 抽象 AIPrompter + OpenAI/DeepSeek/Qwen/Ollama 适配器 + RuleEngineFallback 兜底 |
| 交易所 | ExchangeAdapter 抽象 + Binance Spot/Futures + OKX/Bybit/Bitget（预留） |
| 后台 | Filament 5.x 双面板（Platform 超管 / Admin 租户） |
| 加密 | AES-256-GCM（libsodium）三段密钥层级 KEK → DEK → API Key |
| 风控 | Circuit Breaker 四维度状态机（单笔/日内/回撤/连亏） |

## 双进程模型

```
进程 A：Laravel Octane (Swoole HTTP)
├── REST API (Sanctum)
├── Filament 后台
├── Queue Worker (异步下单/AI/通知)
└── Reverb WebSocket Server（租户推送）

进程 B：MarketFeedWorker (artisan market:feed)
├── 协程 1：交易所 WS 订阅（多租户去重）
├── 协程 2：K 线聚合（bar close → event → Queue）
├── 协程 3：大户爆仓流订阅
└── 协程 4：策略调度器（onTick/onBar 派发到协程池）
```

跨进程通信：Redis（共享状态）+ Laravel Queue（跨进程任务）。

## 目录结构

```
app/
├── Domain/                     # 领域模块（核心）
│   ├── Tenancy/                # 多租户基础设施
│   ├── Exchange/               # 交易所适配器（ExchangeAdapter 接口 + DTO）
│   ├── Strategy/                # 策略引擎（7 种内置策略 + StrategyInterface）
│   ├── Signal/                  # AI 信号（AIPrompter 接口 + RuleEngineFallback 兜底）
│   ├── Risk/                    # 熔断风控（CircuitBreaker 状态机 + 4 维度 Rule）
│   ├── Dashboard/               # 首页面板 5 类数据聚合
│   ├── Billing/                 # 订阅计费（两阶段计量）
│   ├── Notification/            # 多渠道通知（Telegram/企微/邮件/WS + 冷却去重）
│   ├── Liquidation/             # 大户爆仓聚类（Clusterer/MapService/FeedRunner/BucketPolicy）
│   └── Market/                  # 行情数据 Store
├── Http/                        # 中间件 + Controllers + Resources
├── Filament/{Admin,Platform}/   # Filament 两套面板
├── Jobs/                        # Queue Job（orders/ai/notifications/aggregation）
├── Events/                      # 领域事件
├── Console/Commands/            # 6 个自定义 Artisan 命令
├── Models/                      # Eloquent（租户级挂 BelongsToTenant）
└── Support/AesGcmCrypt.php     # AES-256-GCM 封装
config/                          # 8 个自定义 config（含 liquidation）
database/migrations/            # 20 张表
routes/{api.php, channels.php}  # API 路由 + Reverb 频道授权
```

## 功能矩阵 & 路线图

### ✅ 已完成：骨架搭建（2026-10-13）

| 模块 | 产出 | 文档 |
|---|---|---|
| 项目初始化 | Laravel 13.x + Octane 2.21 + Reverb 1.12 + Filament 5.10 + Sanctum 4 | — |
| 多租户隔离 | TenantContext + BelongsToTenant + TenantScope + BootstrapTenancy 中间件 | [ADR-0002](docs/adr/0002-row-level-tenancy.md) |
| API Key 加密 | AES-256-GCM（sodium）+ 三段密钥层级 | [ADR-0003](docs/adr/0003-aes-256-gcm-api-key-encryption.md) |
| Exchange Domain | 6 Contracts/DTOs + AbstractAdapter + 5 具体 Adapter 骨架 | [架构 §5.1](.trae/documents/币圈交易SaaS平台架构方案.md#51-exchangeadapter-interface) |
| Strategy Domain | 3 Contracts/DTOs + AbstractStrategy + 7 策略骨架 | [架构 §5.2](.trae/documents/币圈交易SaaS平台架构方案.md#52-strategyinterface) |
| Signal Domain | 4 Contracts/DTOs + 4 Prompter + BarAggregator + RuleEngineFallback | [架构 §5.3](.trae/documents/币圈交易SaaS平台架构方案.md#53-aiprompter-interface) |
| Risk Domain | 3 Contracts + CircuitBreaker + 4 Rule + RiskGuard | [架构 §6](.trae/documents/币圈交易SaaS平台架构方案.md#6-熔断保护机制) + [ADR-0004](docs/adr/0004-circuit-breaker-state-machine.md) |
| Database | 18 张表 migrations + 18 个 Eloquent Model | [架构 §4](.trae/documents/币圈交易SaaS平台架构方案.md#4-核心数据库表设计) |
| Jobs/Events | 5 Queue Job + 4 领域事件 | [架构 §3](.trae/documents/币圈交易SaaS平台架构方案.md#3-目录结构) |
| Commands | 6 Artisan 命令（含 swoole 扩展检查） | — |
| Filament | Platform Panel（超管）+ Admin Panel（租户）+ 7 Resource | — |
| API | 7 Controllers + 6 Resources + Reverb 4 频道授权 | [架构 §7](.trae/documents/币圈交易SaaS平台架构方案.md#7-首页面板数据聚合) |
| Config | exchange / strategy / ai / risk / plans / notifications / tenancy | — |
| i18n | lang/zh_CN + TestCase setUp setLocale | — |
| 双进程模型 | Octane HTTP + 独立 MarketFeedWorker 入口 | [ADR-0001](docs/adr/0001-dual-process-model.md) |
| docs | architecture.md + 4 ADR + 完整架构方案 | — |

### ✅ 已完成：P1 策略+下单闭环（2026-10-13）

WSL2（PHP 8.4 + Swoole 6.2.3）环境实测验证通过。

| 模块 | 产出 | 文档 |
|---|---|---|
| BinanceSpotAdapter | REST 签名请求（HMAC-SHA256）+ WS K线订阅 + 断线重连 + testnet 端点自动切换 | [架构 §5.1](.trae/documents/币圈交易SaaS平台架构方案.md#51-exchangeadapter-interface) |
| 网格策略 | SpotGridStrategy onBar 网格挂单逻辑（±2% 10 格默认参数） | — |
| 协程调度 | StrategyRunner 协程池派发 onTick/onBar + 崩溃退出重启恢复 | [ADR-0001](docs/adr/0001-dual-process-model.md) |
| 订单执行 | OrderExecutor clientOrderId 幂等模板 `{tenant}_{instance}_{ts}_{rand}` | — |
| 风控熔断 | RiskGuard + CircuitBreaker（Redis Hash 状态）+ 4 维度 Rule；熔断只停开新仓 | [ADR-0004](docs/adr/0004-circuit-breaker-state-machine.md) |
| 租户密钥 | TenantKeyResolver DEK 懒生成 + AES-256-GCM 加密账户落库 | [ADR-0003](docs/adr/0003-aes-256-gcm-api-key-encryption.md) |
| POC 命令 | `poc:verify-feed` / `poc:testnet-setup` / `market:feed` | [快速开始 §POC 验证](#poc-验证p1) |
| 实测沉淀 | 9 条踩坑记录（Guzzle/Swoole hook、协程取消、PSR-4、协程连接复用、签名串一致性、崩溃恢复 hydrate 等） | [踩坑记录](docs/pitfalls.md) |

**实测结果**（testnet BTCUSDT）：

- 行情链路 PASS：REST ticker/kline + WS 1m closed bar，干净退出 exit=0。
- 环境装配 PASS：tenant / DEK 懒生成 / AES-GCM 加密账户 / 实例 running。
- **真实下单闭环 PASS**：市价买单实际成交（orderId 2259141，filled 0.00011 BTC @ 83548.01），完整链路 WS bar → onBar → 网格信号 → RiskGuard → placeOrder → 成交回报 → onFill 全通。
- **崩溃恢复 PASS**：`kill -9` 强杀后重启，持仓 / pending 格 / `last_index` 从 Redis 正确重放，bar 连续推进、无重复下单；连续二次崩溃恢复幂等；Redis 每 bar + DB `position_snapshot` 每 60s 双写均验证。

**已知边界**：策略内存持仓与交易所 `getBalance` 的账实对账计划在 P3 完善；网格自动单只在价格穿越网格线（约 0.4%/格）时触发，横盘无单属设计行为。

### ✅ 已完成：P2 AI 信号闭环 POC（2026-10-14）

真实 Binance 现货行情 + DeepSeek（deepseek-chat）实跑验证通过；**铁律：信号只分析/通知，不触发下单**。

| 模块 | 产出 | 文档 |
|---|---|---|
| 指标引擎 | IndicatorCalculator 纯函数：SMA7/25/99、EMA、RSI14、MACD(12,26,9)、BOLL(20,2)、ATR14（Wilder），NAN→null | — |
| 周期聚合 | BarAggregator：1m WS → 5m 分桶（OHLCV bcmath 累加）+ Redis 未闭 bar 崩溃恢复 + **ticks 残次桶守卫**（收齐 N 根才闭合） | [踩坑 #11](docs/pitfalls.md) |
| AI 分析 | PromptBuilder（中文/纯 JSON/证据不足 neutral/超预算自动裁剪 K 线）+ DeepSeek OpenAI 兼容调用（30s 超时/JSON 围栏清洗/1 次纠错重试/价位矛盾置空）+ 每租户每分钟固定窗口限流 | — |
| 确定性兜底 | RuleEngineFallback：MA 交叉/排列+RSI+MACD+BOLL 打分，\|score\|≥3 才给方向，ATR 算止损止盈；AI 不可用时自动降级 | — |
| 信号编排 | SignalPipeline：指标→AI（失败降级）→neutral 不落库→1% 价差守卫→signals 落库→分发 | — |
| 通知 | LogChannel + TelegramChannel（HTML，无 token 不阻塞）、Redis SET NX 15 分钟指纹去重、signal_notifications 状态机 queued/sent/failed/deduped | — |
| 队列隔离 | AiAnalyzeJob（ai 队列）/ DispatchNotificationJob（notifications 队列）独立 worker，AI 慢调用绝不进行情协程进程；Job 用 `onQueue()` 不重声明 `$queue` | [踩坑 #10](docs/pitfalls.md) |
| POC 命令 | `poc:verify-ai`（42 项离线断言）/ `poc:signal-setup`（建订阅+连通探测） | [快速开始 §POC 验证](#poc-验证p2-ai-信号) |
| 实测沉淀 | 3 条新踩坑（Queueable `$queue` 冲突、残次聚合桶、worker 启动期 Redis 拒连） | [踩坑记录](docs/pitfalls.md) |

**实测结果**（BTCUSDT/5m，UTC 2026-10-13）：

- 全链路 PASS：`1m WS → 5m 聚合（满 5 ticks）→ kline_bars → AiAnalyzeJob → 独立 worker REST 取 120 根 → DeepSeek 分析（约 1s）→ signals 落库 → 去重 → 通知 sent`。
- **真实 AI 信号**：signal #7 `source=ai` short，confidence 62，entry 83170，带中文 rationale（空头排列/BOLL/MACD/RSI 证据链）。
- **降级链路**：无 key/错 key 时自动切 RuleEngineFallback（确定性形态 score=3 long，ATR 止损止盈），neutral 信号只记日志不落库。
- **去重**：15 分钟内同 symbol/interval/direction 第二次信号 → `deduped` 不重复通知（signal #7 实测命中）。
- **残桶守卫**：进程中途启动/版本升级重启产生的不完整桶（ticks<5）被拦截，不写脏 bar、不投递分析；完整桶正常闭合。
- **队列隔离**：AI 调用期间 market:feed 协程进程无阻塞，行情与网格调度连续推进。

**已知边界**：Telegram 渠道代码就绪，待 `TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID` 实测；POC 期 worker 手工 nohup 保活，正式期需 supervisor `autorestart`（见踩坑 #12）；信号周期暂固定 5m。

### ✅ 已完成：P3 多租户计费 POC（2026-10-14）

双租户隔离 + 两阶段配额 + 订阅状态机 + DEK 轮换离线/实跑双验证通过；本期不做支付网关/Filament 计费页/Invoice。

| 模块 | 产出 | 文档 |
|---|---|---|
| 套餐服务 | PlanService 读 `config/plans.php`：限额/渠道/策略白名单；**未知 plan_code 直接抛异常，不静默降级 free（防异常数据提权）**；`log` 系统渠道始终放行 | — |
| 两阶段计量 | TwoPhaseMeter：Redis Lua 原子 check+INCR 热闸门（key `tenant:{tid}:meter:{event}:{UTC Ymd}`，TTL 2 天）+ meter_events 审计行（frozen→settled/reversed）；ref 幂等不双扣；`reconcile()` 丢 key 后从 DB 重建 | [踩坑 #13](docs/pitfalls.md) |
| 统一门面 | QuotaGuard：`assertEntitled`（tenant 状态+订阅状态机+到期兜底）、`freezeDaily/settle/release`、资源数门槛、渠道/策略白名单 | — |
| 订阅状态机 | SubscriptionService：provision（trial/active）→ 到期 refresh 转 past_due → 过宽限（默认 3 天，`BILLING_PAST_DUE_GRACE_DAYS`）suspended → 续费恢复；cancel 即拦截 | — |
| AI 计费接线 | freeze 在 HTTP 前；**已应答（含 4xx/5xx/解析失败）settle 计费；连接未达（ConnectionException）reverse 还额；前置失败（未配 key/限流/配额耗尽）不占额**；耗尽/权益失效自动走 RuleEngineFallback | — |
| 下单计费接线 | OrderExecutor：权益/日配额闸门在任何交易所动作之前；受理（new/partial/filled）settle；拒绝/异常/数量归零/落库失败 reverse；拒绝路径交易所零调用 | — |
| 资源门 | `poc:signal-setup` / `poc:testnet-setup`：仅新建时校验账户/实例/订阅数上限与渠道/策略白名单，更新既有行不拦 | — |
| DEK 轮换 | TenantKeyResolver::rotateDek：Redis 锁（token Lua 释放）+ 行锁事务，KEK 解旧 DEK→新 DEK 重加密全部账户密文→抽验+旧密文残留断言；停实例后轮换（非热轮换） | [踩坑 #13](docs/pitfalls.md) |
| 队列上下文 | AiAnalyzeJob 内 `TenantContext::initialize` + finally clear（market:feed 协程进程仍铁律不初始化上下文，全部显式 tenant_id） | — |
| POC 命令 | `poc:verify-billing`（62 项离线断言，含 10 进程抢 3 配额并发测试）/ `poc:billing-demo`（双租户实跑装配，`--status`/`--cleanup`） | [快速开始 §POC 验证](#poc-验证p3-多租户计费) |

**实测结果**：

- 离线断言 PASS：`php artisan poc:verify-billing` → **62/62**：套餐解析与防提权、两阶段/ref 幂等/reconcile、**10 进程并发抢 3 配额恰好 3 行 frozen**、订阅状态机全流转、配额耗尽 pipeline 0.04s 走兜底零 HTTP、下单拦截交易所零调用+失败冲正还额、资源/渠道门端到端拒绝与放行、DEK 轮换后新旧密文与跨租户零影响、TenantScope 双向不可见。
- **双租户信号实跑 PASS（UTC 2026-10-14 01:11 5m 收盘）**：tenant#1 与 tenant#8(poc-billing) 的订阅共用同一条 Binance WS（`subscription_count=2`，按 symbol+interval 分组扇出），同一根 bar 各投一个 AiAnalyzeJob；DeepSeek 各应答一次，meter_events 各 1 行 `ai_call/settled`（ref 为各自 `subscription_id:openTime`），Redis 计数桶 `tenant:1:…=1` 与 `tenant:8:…=1` 独立互不串。
- 无策略实例的租户只跑信号链路，避免第二网格共用同一 testnet key 互扰（策略实跑为可选 stretch）。

**已知边界**：无支付网关，状态流转由管理动作/命令显式驱动；无 Invoice/Filament 计费页；DEK 轮换需停实例（非热轮换），轮换期间账户操作应停窗；refreshStatus 需挂每日调度（POC 手工触发）。

### ✅ 已完成：P4 高级风控（2026-10-14）

4 维度 Rule + CircuitBreaker 三态机（CLOSED→OPEN→HALF_OPEN→CLOSED）+ BreakerTripped 事件分发通知链 + 跨日重置 + 崩溃恢复 lastTradeDate 注入 + 人工恢复命令；离线 35/35 全绿。

| 模块 | 产出 | 文档 |
|---|---|---|
| 4 维度 Rule | MaxLossPerTrade(2%)/MaxDailyLoss(5%)/MaxDrawdown(10%)/ConsecutiveLoss(5次)；BreakerMetrics 追踪峰值/回撤/日内/连亏 | — |
| 三态熔断 | CircuitBreaker：trip(CLOSED/HALF_OPEN→OPEN) + attemptProbe(OPEN→HALF_OPEN 冷却到期) + recover(HALF_OPEN→CLOSED)；Redis Hash 持久化 `tenant:{tid}:breaker:{iid}` | [踩坑 #17](docs/pitfalls.md) |
| 事件分发 | trip/recover → BreakerTripped 事件 → NotifyBreakerTrip(ShouldQueue,notifications 队列) + BroadcastBreakerAlert(同步 Reverb)；dispatch 失败 try/catch 不回滚状态迁移 | [踩坑 #19](docs/pitfalls.md) |
| 通知链 | RiskNotificationJob：log 渠道直接写；telegram HTTP Bot API；wechat_work/mail 标 failed；DedupCooldown 15 分钟（sha1(instanceId\|ruleType)）；risk_notifications 落库审计 | — |
| 跨日重置 | BreakerMetrics.recordTradeWithDate：比对 UTC 日期，跨日→resetDaily（dailyLoss/consecutiveLoss 清零）；lastTradeDate 持久化 Redis Hash，崩溃恢复注入 | [踩坑 #18](docs/pitfalls.md) |
| 探测单语义 | HALF_OPEN 下放行 1 笔探测单（config `half_open_probe_count=1`）：盈利→recover(CLOSED)；亏损→re-trip(OPEN，重置计时)；熔断只停开新仓 check('close') 永远放行 | — |
| POC 命令 | `poc:verify-risk`（35 项离线断言：4 维度触发/HALF_OPEN 双路径/事件计数/4 渠道通知/跨日重置/崩溃恢复/去重/命令幂等）/ `risk:status {iid}` / `risk:recover {iid}` | [快速开始 §POC 验证](#poc-验证p4-高级风控) |

**实测结果**：

- 离线断言 PASS：`php artisan poc:verify-risk` → **35/35**：4 维度 Rule 独立触发与审计行落库、HALF_OPEN 探测亏损 re-trip / 盈利 recover 双路径、BreakerTripped 事件 trip/recover 各 1 次、4 渠道 risk_notifications 落库(log=sent/wechat_work=failed/mail=failed)、跨日 resetDaily、崩溃恢复从 Redis 注入 lastTradeDate 后首笔跨日回写、DedupCooldown 15 分钟去重、risk:recover 命令 HALF_OPEN→CLOSED + CLOSED 幂等跳过。
- 运维命令 PASS：`risk:status 1` 显示 state/triggerInfo/last_trade_date + 最近 5 条审计；`risk:recover 1` 对 CLOSED 状态幂等返回 SUCCESS。

**已知边界**：风控通知未走 NotifierChannel 契约（该契约绑定 Signal 对象，风控无 signal 概念）；telegram 渠道待配 TELEGRAM_BOT_TOKEN/CHAT_ID 实测；Reverb 广播需前端 WebSocket 客户端订阅 `tenant.{tid}.alerts` 频道；max_drawdown 的 balance_snapshots 对账待 P5+ 完善（当前从 strategy_orders 重放保守取值）。

### ✅ 已完成：P5 大户爆仓数据（2026-10-14）

Binance 合约强平流 `!forceOrder@arr` 实时订阅 + Redis ZSET+HASH 聚类 + 三档滑动窗口（5m/15m/60m）+ 0.1% 自适应桶宽 + Filament Widget + API；离线 15/15 全绿。真实 fstream 实跑阻塞于 WSL 出口网络对 Binance 主网不可达，命令保留待网络恢复补测。

| 模块 | 产出 | 文档 |
|---|---|---|
| 强平事件 DTO | LiquidationEvent readonly class：exchange/symbol/side/price/qty/valueUsd/tsMs/eid；`fromBinancePayload()` 解析 `o` 字段 | — |
| 桶宽策略 | LiquidationBucketPolicy：0.1% 百分比桶宽从 `config/liquidation.php` 读（BTC=60/ETH=3/SOL=0.2），bucketIndex/center/range 纯函数 | — |
| 聚类器 | LiquidationClusterer：Redis ZSET（score=ts_ms,member=eid）+ HASH（bucket→qty/count）+ eid 反向索引；ingest pipeline 批量、evictExpired 反减负值清空桶、snapshot 按 total_qty desc | [踩坑 #21](docs/pitfalls.md) |
| MapService | LiquidationMapService::query：返回 topN 密集区含 distance_pct（正=上方/负=下方） | — |
| FeedRunner | LiquidationFeedRunner：仿 SignalFeedRunner 协程范式；订阅 symbols = strategy_instances ∪ signal_subscriptions（空集默认 BTC/ETH）；onLiquidation 落库 liquidation_events + 三档 ingest；每 60s 快照写 liquidation_clusters | — |
| 合约 Adapter | BinanceFuturesAdapter：仅实现 subscribeLiquidationStream（单流 `/ws/!forceOrder@arr`），其余接口抛 LogicException 防范围蔓延；覆写 `wsBaseUrl()` 强制主网 fstream | [踩坑 #22](docs/pitfalls.md) |
| 面板/接口 | LiquidationMapWidget（Filament v3 Livewire，10s 轮询）+ `GET /api/v1/liquidation/map` + `GET /api/v1/liquidation/recent` | [踩坑 #20](docs/pitfalls.md) |
| POC 命令 | `poc:verify-liquidation`（15 项离线断言）/ `poc:liquidation-demo`（连真实 fstream N 秒，当前阻塞于网络） | [快速开始 §POC 验证](#poc-验证p5-大户爆仓数据) |
| 实测沉淀 | 4 条新踩坑（Filament $view 非 static、phpredis zAdd 签名、futures wsBaseUrl testnet 覆盖、WSL 主网网络不可达） | [踩坑记录](docs/pitfalls.md) |

**实测结果**：

- 离线断言 PASS：`php artisan poc:verify-liquidation` → **15/15 ALL CHECKS PASSED**：DTO 解析 + value_usd、0.1% 桶宽边界、ingest 单条/批量、snapshot 结构与排序、HASH bucket qty 一致性、eviction 空桶清理 + ZCARD 减少、window/side/symbol 隔离、MapService topN + distance_pct 正负号。
- Migration PASS：`liquidation_events`（symbol+event_ts / symbol+side+event_ts / exchange_code+event_ts 三索引）+ `liquidation_clusters`（unique exchange+symbol+side+window+bucket_center+snapshot_ts）两张表创建成功。
- Filament Widget 正确注册到 Platform Dashboard（`getWidgets()` 追加 LiquidationMapWidget::class）。
- API 路由 `/api/v1/liquidation/map` + `/api/v1/liquidation/recent` 接入 `auth:sanctum + tenant` 中间件栈。

**已知边界**：真实 fstream 实跑阻塞于 WSL 出口网络对 Binance 主网（fstream.binance.com / api.binance.com）不可达（ECONNREFUSED），testnet 端点可达——离线断言已覆盖全部核心逻辑（DTO/聚类/Redis 数据结构/三档窗口/eviction/MapService），网络恢复后 `poc:liquidation-demo --duration=120` 可随时补测；Reverb 广播爆仓密集区、多交易所强平流、ClickHouse 历史归档留待后续迭代。

### 🚧 待实现：剩余路线图

> P1 策略+下单闭环 / P2 AI 信号闭环 / P3 多租户计费 / P4 高级风控 / P5 大户爆仓数据 已完成，实测结果见上方对应区块。

| 阶段 | 范围 | 验证目标 | 关键风险 |
|---|---|---|---|
| **P6 扩展（可选）** | OKX/Bybit/Bitget Adapter 落地 + Custom DSL 引擎 + ClickHouse 历史归档 | Adapter 抽象跨所健壮性；DSL 自定义策略可装载；分析层迁移到列存 | 各所 WS 协议差异；DSL 安全沙箱；ClickHouse 迁移窗口 |

## 快速开始

### 前置要求

- PHP 8.4+（推荐 ServBay 管理）
- MySQL 8.4+
- Redis 7.x+
- Swoole 扩展（P1 POC 前启用）
- Composer

### 安装

```bash
# 克隆
git clone https://github.com/941xinye/trading_saas.git
cd trading_saas

# 依赖安装
composer install

# 环境配置
cp .env.example .env
php artisan key:generate

# 配置 MySQL / Redis / EXCHANGE_MASTER_KEY（32 字节 hex）
# EXCHANGE_MASTER_KEY=$(php -r "echo bin2hex(random_bytes(32));")

# 数据库迁移
php artisan migrate

# 启动服务
# Windows 本地：php artisan serve（Octane/Swoole 与 market:feed 需在 WSL 内运行，见下节）
php artisan octane:start --server=swoole
php artisan market:feed --dry-run  # 验证协程容器入口（需 swoole 扩展）
```

### POC 验证（P1）

在具备 Swoole 的环境（本机为 WSL 侧）执行：

```bash
# 1. 行情链路验证（无需 API Key）：REST ticker/kline + WS 1m closed bar
php artisan poc:verify-feed --duration=90

# 2. （可选）testnet 网格实例装配：
#    .env 先配置 EXCHANGE_MASTER_KEY + EXCHANGE_BINANCE_TESTNET=true
#    + EXCHANGE_TESTNET_KEY/SECRET（https://testnet.binance.vision 注册）
php artisan poc:testnet-setup                    # 默认 BTCUSDT ±2% 10 格

# 3. 启动行情/调度主进程（进程 B），网格实例开始按 bar 调度
php artisan market:feed
```

无 testnet key 时实例按设计标记 error，填好 key 重跑步骤 2 即可。

### POC 验证（P2 AI 信号）

在 WSL 侧执行（需先在 `.env` 配置 `DEEPSEEK_API_KEY`，注意 DeepSeek key 必须带 `sk-` 前缀）：

```bash
# 1. 离线全链路断言（42 项：聚合/指标/真实 AI/兜底/价差守卫/去重，不依赖长驻进程）
php artisan poc:verify-ai

# 2. 建信号订阅并探测 DeepSeek 连通（按 tenant+symbol upsert，active=1）
php artisan poc:signal-setup --tenant=1 --symbol=BTCUSDT --interval=5m --channel=log

# 3. 两个长驻进程（分开启动，AI 慢调用只在 queue worker，不进行情协程进程）
php artisan market:feed                                              # 1m WS → 5m 聚合 → 投 Job
php artisan queue:work redis --queue=ai,notifications --tries=2      # AI 调用 + 通知发送
```

无 key 或 AI 故障时链路自动降级到 RuleEngineFallback，不影响运行；可选 `TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID` 开启 Telegram 渠道。**P2 信号铁律：只分析通知，不触发下单。**

### POC 验证（P3 多租户计费）

```bash
# 1. 离线全链路断言（62 项，使用一次性租户 poc-verify-billing-a/b，自动清理；
#    内含 10 个并发子进程，需允许 proc_open）
php artisan poc:verify-billing

# 2. 双租户信号实跑：建第二租户 poc-billing(free) + BTCUSDT/5m/log 订阅
php artisan poc:billing-demo
#    然后重启 market:feed 与 queue worker（构造依赖变更必须重启），等下一个 5m 整点：
php artisan poc:billing-demo --status     # 查看双方 meter_events 与最近信号
php artisan poc:billing-demo --cleanup    # 删除第二租户及其全部数据
```

注意：`poc:verify-billing` 会并发拉起 `php artisan poc:meter-freeze` 子进程（隐藏命令），Windows 原生 PHP 与 WSL PHP 均可跑，但请在实际运行长驻服务的一侧执行（本机 WSL），确保子进程连到同一 Redis。

### POC 验证（P4 高级风控）

```bash
# 1. 离线全链路断言（35 项，使用一次性租户 poc-verify-risk，自动清理）
php artisan poc:verify-risk

# 2. 运维命令：查看实例熔断状态 + 最近审计
php artisan risk:status {instanceId}

# 3. 人工恢复：HALF_OPEN → CLOSED（写 manual_resume 审计行；CLOSED 幂等跳过）
php artisan risk:recover {instanceId}
```

`poc:verify-risk` 覆盖 12 组场景共 35 项断言：4 维度 Rule 独立触发（单笔 2%/日内 5%/回撤 10%/连亏 5 次）、HALF_OPEN 探测亏损 re-trip / 盈利 recover 双路径、BreakerTripped 事件 trip/recover 分发计数、4 渠道 risk_notifications 落库（log=sent / wechat_work/mail=failed）、跨日 resetDaily、崩溃恢复 lastTradeDate 从 Redis 注入后跨日回写、DedupCooldown 15 分钟去重、risk:recover 命令幂等。

### POC 验证（P5 大户爆仓数据）

```bash
# 1. 离线全链路断言（15 项：DTO/聚类/Redis ZSET+HASH/三档窗口隔离/eviction/MapService）
#    使用一次性 symbol POCVERIFYBTCUSDT/POCVERIFYETHUSDT，自动清理 Redis fixtures
php artisan poc:verify-liquidation

# 2. （可选）连真实 Binance 合约强平流 N 秒，每 10s 输出统计 + top 3 密集区
#    需 WSL 出口网络对 fstream.binance.com 主网可达（本机当前不可达，见踩坑 #23）
php artisan poc:liquidation-demo --duration=120
```

`poc:verify-liquidation` 覆盖 15 项断言：LiquidationEvent DTO 解析与 value_usd、0.1% 桶宽边界、ingest 单条/批量、snapshot 结构与排序、HASH bucket qty 一致性、eviction 空桶清理 + ZCARD 减少、window/side/symbol 隔离、MapService topN + distance_pct 正负号。

真实流实跑 `poc:liquidation-demo` 需要 WSL 出口对 `fstream.binance.com:443` 可达；当前开发机出口被 Binance 主网封禁（testnet 端点可达但合约强平流只在主网），离线断言已覆盖全部核心逻辑，网络恢复后可随时补测。

### Swoole 运行环境（Windows 开发机：WSL2 方案）

Swoole 官方不支持 Windows 原生运行，本机已采用 **WSL2 + Ubuntu 22.04** 方案，`market:feed`（进程 B）跑在 WSL 内，通过 NAT 网关回连 Windows 侧 ServBay 的 MySQL / Redis：

| 组件 | Windows 侧（ServBay） | WSL 侧（~/trading_saas） |
|---|---|---|
| PHP | 8.5.11（CLI，无 swoole） | 8.4.26 + swoole 6.2.3 + redis + sodium |
| MySQL | 8.4.11，监听全接口，用户 `trading_wsl@%`（仅 crypto_saas 库） | 经 `172.18.x.1:3306` 访问 |
| Redis | 7.x，仅绑回环 | 经 portproxy `16379 → 6379` 访问 |

关键配置（已就绪，重启后无需重做）：

- `wsl --import Ubuntu-22.04 D:\WSL\Ubuntu2204`（WSL2，rootfs 来自 cloud-images.ubuntu.com）
- `netsh interface portproxy add v4tov4 listenaddress=0.0.0.0 listenport=16379 connectaddress=127.0.0.1 connectport=6379`（规则持久化注册表）
- 防火墙规则 `WSL2 to ServBay MySQL 3306 / Redis 16379`（限 172.16.0.0/12）
- WSL 内 `.env`：`DB_HOST/REDIS_HOST=<win-ip>`、`REDIS_PORT=16379`、`DB_USERNAME=trading_wsl`

WSL 内启动进程 B：

```bash
wsl -d Ubuntu-22.04
cd ~/trading_saas
php artisan market:feed
```

Linux / macOS 环境无需以上步骤，直接 `pecl install swoole` 即可。

### 创建用户

```bash
# 创建平台超管
php artisan tinker
$tenant = App\Models\Tenant::create(['name' => 'Admin', 'slug' => 'admin', 'plan_code' => 'enterprise']);
App\Models\User::create([
    'tenant_id' => null,  # 平台超管无租户
    'name' => 'Super Admin',
    'email' => 'admin@example.com',
    'password' => bcrypt('password'),
    'role' => 'platform_admin',
]);

# 创建租户级用户
App\Models\User::create([
    'tenant_id' => $tenant->id,
    'name' => 'Trader',
    'email' => 'trader@example.com',
    'password' => bcrypt('password'),
    'role' => 'tenant_admin',
]);
```

### 访问面板

| 面板 | URL | 用户 |
|---|---|---|
| Platform 超管 | `/platform/login` | role=platform_admin |
| Admin 租户 | `/admin/login` | 租户级用户（tenant_id 非 null） |
| API v1 | `/api/v1/dashboard` | Sanctum API Token |

### 运行测试

```bash
php artisan test
```

## 关键硬约束（架构决策）

| 约束 | 原因 | 来源 |
|---|---|---|
| 所有业务表必须带 tenant_id 复合索引 | TenantScope 自动加 WHERE tenant_id=?，无索引全表扫描 | [ADR-0002](docs/adr/0002-row-level-tenancy.md) |
| 禁止业务接口调用 withoutTenant() | 跨租户数据泄露风险 | CRM 项目约定 |
| 服务层禁止 static 方法内缓存 | Octane/queue 长驻下脏数据 | CRM 项目约定 |
| AI 失败必须降级 RuleEngineFallback | 信号链路必须持续 | [架构 §5.3](.trae/documents/币圈交易SaaS平台架构方案.md#53-aiprompter-interface) |
| 熔断只暂停开新仓，不平已有持仓 | 避免雪上加雪 | [架构 §6](.trae/documents/币圈交易SaaS平台架构方案.md#6-熔断保护机制) |
| API Secret 明文仅创建时返回一次 | 密钥安全 | [ADR-0003](docs/adr/0003-aes-256-gcm-api-key-encryption.md) |
| 进程 B 必须独立启动 | Octane worker 内长连接阻塞 HTTP | [ADR-0001](docs/adr/0001-dual-process-model.md) |
| 时钟统一 UTC 存储，前端按 tenant timezone 展示 | 避免本地时钟漂移 | [架构 §9.6](.trae/documents/币圈交易SaaS平台架构方案.md#96-时钟与时间序列) |

## 文档索引

| 文档 | 说明 |
|---|---|
| [完整架构方案](.trae/documents/币圈交易SaaS平台架构方案.md) | 11 节：技术选型/多租户/目录结构/数据库表/接口契约/熔断/面板聚合/POC 优先级/风险权衡 |
| [架构总览](docs/architecture.md) | 核心决策速查 + 目录结构 + POC 路线图 |
| [ADR-0001 双进程模型](docs/adr/0001-dual-process-model.md) | 为什么 Octane + 独立 Swoole |
| [ADR-0002 行级隔离](docs/adr/0002-row-level-tenancy.md) | 为什么 tenant_id 而非 schema 级 |
| [ADR-0003 AES-256-GCM](docs/adr/0003-aes-256-gcm-api-key-encryption.md) | 为什么 GCM 而非 CBC |
| [ADR-0004 熔断状态机](docs/adr/0004-circuit-breaker-state-machine.md) | 四维度 Rule + HALF_OPEN 探测 |
| [踩坑记录](docs/pitfalls.md) | P1/P2/P3/P4/P5 实测踩坑 23 条（Swoole/Guzzle/协程/PSR-4、残次聚合桶、两阶段计量 Lua、DEK 轮换、HALF_OPEN 死锁、Filament $view 非 static、phpredis zAdd 签名、futures wsBaseUrl 覆盖、主网网络不可达等）持续追加 |

## 安全说明

- `.env` 包含 EXCHANGE_MASTER_KEY 和数据库密码，**绝不要提交到仓库**
- API Secret 明文仅创建时返回一次，加密存 exchange_accounts
- KEK（.env）丢失则所有加密的 DEK 无法解密，需重新创建租户
- 生产环境建议启用 HTTPS（Filament 默认需要）

## License

MIT
