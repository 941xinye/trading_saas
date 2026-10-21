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
| 数据库 | MySQL 8.4（业务主库） + Redis（行情/状态/队列/限流） + ClickHouse（已引入——P8 历史归档冷层） |
| AI 分析 | 抽象 AIPrompter + OpenAI/DeepSeek/Qwen/Ollama 适配器 + RuleEngineFallback 兜底 |
| 交易所 | ExchangeAdapter 抽象 + RequestSigner 签名域（Binance/OKX/Bybit/Bitget 四家）+ Binance 现货（完整实跑）/Binance 合约（强平流 + 9 个交易接口，spec 005）+ OKX 现货（spec 001 完成，真实链路待 demo key）+ Bybit 现货（spec 002 完成，真实链路待 testnet key）+ Bitget 现货（spec 003 完成，无公开模拟盘，主网小额单待 key） |
| 后台 | Filament 5.x 双面板（Platform 超管 / Admin 租户） |
| 加密 | AES-256-GCM（libsodium）三段密钥层级 KEK → DEK → API Key |
| 风控 | Circuit Breaker 四维度状态机（单笔/日内/回撤/连亏）+ 仓位级风控（warning/reduce/emergency_close 三档） |

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
│   ├── Exchange/               # 交易所适配器（Binance/OKX/Bybit/Bitget 现货 + Binance 合约 + 账户加密写入口）
│   ├── Strategy/                # 策略引擎（SpotGrid/SpotDca/FuturesGrid 实装；Custom DSL 沙箱解释器）
│   ├── Signal/                  # AI 信号（AIPrompter 接口 + RuleEngineFallback 兜底）
│   ├── Risk/                    # 熔断风控（CircuitBreaker 状态机 + 4 维度 Rule）+ 仓位级风控（PositionRiskMonitor 三档 + 强平价计算器）
│   ├── Billing/                 # 订阅计费（两阶段计量）
│   ├── Payment/                 # 支付网关（通道抽象 + webhook 幂等 + 激活事务）
│   ├── Reconciliation/          # 账实对账（差异检测 + 暂停/恢复）
│   ├── Notification/            # 通知（Log/Telegram/企微/邮件渠道，信号/风控双链共用 + 冷却去重）
│   ├── Liquidation/             # 大户爆仓聚类（Clusterer/MapService/FeedRunner/BucketPolicy）
│   ├── Backtest/                # 回测引擎（回放驱动 / 撮合 / 绩效指标）+ 参数寻优（网格 / walk-forward）
│   └── Archive/                 # 历史归档（P8：ArchivePipeline 状态机 / HistoricalQueryService 冷热路由 / ClickHouseStore HTTP 直连 / 四类型策略 + RowNormalizer 行哈希）
├── Http/                        # 中间件 + Controllers + Resources（API 写接口共用领域服务）
├── Filament/{Admin,Platform}/   # Filament 两套面板（含回测绩效页/计费页）
├── Jobs/                        # Queue Job（orders/ai/notifications/aggregation）
├── Events/                      # 领域事件
├── Console/Commands/            # 39 个 Artisan 命令（market:feed / archive:* / backtest:* / payment:* / reconcile:* / risk:* / 5 个 poc:verify 等）
├── Models/                      # 23 个 Eloquent（租户级挂 BelongsToTenant）
└── Support/AesGcmCrypt.php     # AES-256-GCM 封装
config/                          # 27 个 config（含 exchange/strategy/ai/risk/plans/payment/archive/backtest/optimization/reconcile/liquidation）
database/migrations/            # 36 张表
routes/{api.php, channels.php, web.php}  # API 路由 + Reverb 频道授权 + Filament
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
| 协程调度 | StrategyRunner 协程池派发 onTick/onBar + 崩溃退出重启恢复；后台 start 后 5s 内热拉起，stop 由实例协程监听 stopped 自停（无需重启进程 B） | [ADR-0001](docs/adr/0001-dual-process-model.md) |
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

### ✅ 已完成：spec 001 OKX 现货适配器（2026-10-17，spec-kit SDD 首个落地 feature）

对齐 BinanceSpotAdapter 接口面的 OKX 现货适配器，38 任务完成 37（T037 quickstart 实跑验收依赖 OKX demo key 与海外网络，与 P5.5 节点验收打包）。

| 模块 | 产出 | 文档 |
|---|---|---|
| REST 行情 | getTicker/getKline（instId 归一化 BTC-USDT、limit 上限 300、升序重排） | [spec](specs/001-okx-spot-adapter/spec.md) |
| WS K 线流 | subscribeKlineStream（`candle{interval}` 频道、confirm=1 才 onBar） | [contracts](specs/001-okx-spot-adapter/contracts/okx-api-contract.md) |
| 签名下单 | OKX HMAC prehash（timestamp+method+requestPath+body）+ passphrase 守卫 + 模拟盘 `x-simulated-trading: 1` 头 | [research](specs/001-okx-spot-adapter/research.md) |
| POC 命令 | `poc:verify-okx-feed`（REST ticker/kline + WS 120s，公共流无需凭据） | [quickstart](specs/001-okx-spot-adapter/quickstart.md) |

**实测结果**：25 passed / 1 incomplete（WS 手测项）/ 1 skipped（Redis 离线）/ 137 assertions，无回归；`subscribeLiquidationStream` 显式抛 LogicException 防范围蔓延。

### ✅ 已完成：spec 002 Bybit 现货适配器（2026-10-18，提交 1c5ffa8）

Bybit V5 现货适配器全量落地，46/46 任务；同步抽取 Signing 签名域统一三家签名实现。

| 模块 | 产出 | 文档 |
|---|---|---|
| REST/WS | getTicker/getKline（升序重排）/getBalance（unified wallet）/subscribeKlineStream（confirm 过滤） | [spec](specs/002-bybit-spot-adapter/spec.md) |
| 签名下单 | Bybit V5 五件套头（key/sign/timestamp/recvWindow/signType）+ 市价单 quoteCoin 换算 + 170213 撤单幂等 + recvWindow 时钟提示 | [contracts](specs/002-bybit-spot-adapter/contracts/bybit-api-contract.md) |
| Signing 域 | RequestSigner/SignedRequest 抽象 + Binance/Okx/Bybit 三家签名器（修复 OKX 3 处硬编码时间戳测试向量） | — |
| 横切断言 | ErrorCodeTranslation 三家错误码分类一致性（15 测试）；ExchangeManager 路由（5 测试）；StrategyExchangeCompatibility RecordingAdapter 驱动 spot_grid/spot_dca 决策链与崩溃恢复链、策略源码零具体适配器引用（5 测试） | — |
| POC 命令 | `poc:verify-bybit-feed` | [quickstart](specs/002-bybit-spot-adapter/quickstart.md) |

**实测结果**：`tests/Domain/Exchange` 98 passed / 1 incomplete / 2 skipped（均为既有基线）/ 381 assertions，无回归；`AbstractExchangeAdapter` 保持零按家分支。

**已知边界**：OKX demo / Bybit testnet 真实行情与下单实跑待 key 与海外网络到位后按 quickstart 补测。

### ✅ 已完成：spec 003 Bitget 现货适配器（2026-10-18，HEAD d49f509）

Bitget V2 现货适配器全量落地，40/40 任务；验证「第四家接入纯增量」架构承诺——`AbstractExchangeAdapter` 零改动（git diff 为空）、前三家既有测试 100% 通过零变化，差异全部收敛在 BitgetRequestSigner / 错误码翻译实现内。

| 模块 | 产出 | 文档 |
|---|---|---|
| REST 行情 | getTicker/getKline（V2 `/api/v2/spot/public/*`、升序重排）/getBalance/symbolFilters（Redis 缓存） | [spec](specs/003-bitget-spot-adapter/spec.md) |
| WS K 线流 | subscribeKlineStream——**bar 切换检测判定关闭**（V2 candle 无 confirm 标志：snapshot 首帧只播种不回调，新 bar ts 出现 ⇒ 旧 bar 关闭）；文本帧 ping/pong 心跳 25s | [contracts](specs/003-bitget-spot-adapter/contracts/bitget-api-contract.md) |
| 签名下单 | V2 prehash（timestamp+METHOD+requestPath(+queryString)+body，HMAC-SHA256 base64）+ ACCESS-KEY/SIGN/TIMESTAMP/PASSPHRASE 四件套；三段凭据守卫（key/secret/passphrase 任一缺失抛 ExchangeApiException） | [research](specs/003-bitget-spot-adapter/research.md) |
| 无模拟盘边界 | Bitget 现货无公开 testnet/模拟盘 → config 不含 testnet 键（结构性断言覆盖 FR-013）；真实下单验收 = 主网小额市价单待 key | [quickstart](specs/003-bitget-spot-adapter/quickstart.md) |
| 四家横切 | ErrorCodeTranslation / ExchangeManagerRoute / StrategyExchangeCompatibility 全部扩展至四家；策略源码零具体适配器引用 | — |
| POC 命令 | `poc:verify-bitget-feed`（REST ticker/kline + WS 120s，公共流无需凭据） | [quickstart](specs/003-bitget-spot-adapter/quickstart.md) |

**实测结果**：`tests/Domain/Exchange` **147 passed / 1 incomplete / 3 skipped / 574 assertions**（spec 002 基线 98 passed 之上纯增量 +49，前三家零回归）。

**已知边界**：Bitget 主网小额下单与 WS 实跑待 key 与海外网络到位后按 quickstart 补测（连同 OKX demo / Bybit testnet 一并打包，见 [海外节点 Runbook](docs/overseas-node-runbook.md) 验收总清单）。

### ✅ 已完成：spec 004 ClickHouse 历史归档（2026-10-19，核心落地）

kline_bars / signals / strategy_orders / liquidation_events 四类时序数据归档冷层落地（31/31 任务）；**纯旁路管线**——P1–P5 代码零 import 指向 `App\Domain\Archive`（FR-008 导入闭包 gate 终检 PASS），禁止进入 market:feed 行情协程进程。节点机 CH 冒烟与端到端验收待回填。

| 模块 | 产出 | 文档 |
|---|---|---|
| 归档管线 | ArchivePipeline 状态机：建批 → copy（batch_id 幂等锚先删半途行）→ 三级 verify（行数/行哈希和/抽样逐行）→ apply（事务内再核对 + 分块限速删除 + 水位推进） | [spec](specs/004-clickhouse-historical-archive/spec.md) |
| 冷层存储 | ClickHouseStore：MergeTree + batch_id/archived_at 审计列，HTTP 直连零依赖（无 PHP 客户端库），异常统一 `ArchiveStorageUnavailableException` | [contracts](specs/004-clickhouse-historical-archive/contracts/clickhouse-schema-contract.md) |
| 统一查询 | HistoricalQueryService：applied_watermark 三段路由（纯冷/纯热/跨界归并 id 去重）+ keyset 游标分页 + 上限显式报错 | [contracts](specs/004-clickhouse-historical-archive/contracts/historical-query-contract.md) |
| 租户隔离 | tenant 类型 copy 显式绕 Scope（系统级搬迁）；查询侧强制 TenantContext 注入，热侧 TenantScope + 冷侧 filters 两层等价（R-008） | [plan](specs/004-clickhouse-historical-archive/plan.md) |
| 安全红线 | 非 passed 拒删（verify gate）；`--apply` 才删除；分块/节流参数下限校验（exit 2）；空切片不建批不推进水位 | [research](specs/004-clickhouse-historical-archive/research.md) |
| 可观测/运维 | `archive:schema`（建表 DDL）/ `archive:run`（dry-run 默认）/ `archive:verify`（独立复检+降级）/ `archive:status`（水位/待归档估算/批次清单） | [quickstart](specs/004-clickhouse-historical-archive/quickstart.md) |

**实测结果**：383 tests 全绿（RowNormalizer + ArchivePipeline + CH 集成/降级/租户隔离/命令族），全量回归零失败零回归。

**已知边界**：部署形态=自托管单节点 ClickHouse（Docker 起节点机，env 化连接；云托管留后续）；节点机 CH 冒烟与端到端验收（含 SIGKILL 幂等演练）待执行。选型见 [ADR-0005](docs/adr/0005-clickhouse-cold-tier.md)。

### ✅ 已完成：spec 005 合约网格策略 + 仓位级风控（2026-10-19）

合约网格策略（FuturesGridStrategy）+ 仓位级风控（PositionRiskMonitor）全量落地，6 工作流 48 任务；离线断言 + 单元/集成测试全绿，testnet 真实链路待海外节点 + key 补测。

| 模块 | 产出 | 文档 |
|---|---|---|
| 强平价计算器 | IsolatedLiquidationPriceCalculator：逐仓模式 `entry × (1 ∓ 1/leverage ± MMR)`；保证金率 = 距强平价剩余缓冲比例（入场=1.0，强平=0） | [spec](specs/005-futures-grid-position-risk/spec.md) |
| 仓位风控三档 | PositionRiskMonitor：warning(≤50% 通知)/action(≤20% 减仓 50% reduceOnly)/emergency(≤10% 全平)；Redis 单飞锁防与网格平仓并发；position_risk_events 审计 + PositionRiskAlert 事件 | [plan](specs/005-futures-grid-position-risk/plan.md) |
| 合约网格策略 | FuturesGridStrategy（schema_version=2）：6 种决策（开多/开空/平多/平空/加多/加空）+ reduceOnly 平仓 + onFill 加权平均成本 + RecoverablePendingOrders 崩溃恢复 + 区间外不交易 | — |
| Runner 接线 | StrategyRunner：30s 节流调 adapter.getPosition() + evaluatePositionRisk()；emergency_close → status=paused_by_position_risk（禁止开仓，平仓仍可触发） | — |
| 下单执行器 | OrderExecutor：透传 reduceOnly/leverage 到 adapter + 落库 strategy_orders | — |
| 合约 Adapter | BinanceFuturesAdapter：9 个交易接口（placeOrder/cancelOrder/getOrder/getPosition/setLeverage/setMarginType/leverageBracket/getBalance/getTicker）+ 错误码翻译 | — |
| 售卖门控 | config/strategy.implemented 加入 futures_grid；pro/enterprise 套餐白名单加入 futures_grid，free 不含；StrategyWhitelistIntegrityTest 防超卖 | — |
| 通知 | NotifyPositionRiskAlert：ShouldQueue + 15min 去重（sha1(instanceId\|actionType)），复用 RiskNotificationJob | — |
| POC 命令 | `poc:verify-futures`（36 项离线断言：强平价公式 + 决策矩阵 + 风控三档 + 门控）/ `poc:futures-testnet-setup`（建实例 + 设杠杆 + 逐仓）/ `poc:futures-risk-simulate`（注入模拟仓位触发风控） | [quickstart](specs/005-futures-grid-position-risk/quickstart.md) |

**实测结果**：
- 离线断言 PASS：`php artisan poc:verify-futures` → **36/36 ALL CHECKS PASSED**：强平价多/空 × 4 杠杆 + MMR 影响、保证金率入场=1/强平=0/中点=0.5/越界钳制、决策矩阵 6 种 + 区间外无信号、风控 warning/reduce/emergency 三档 + 无持仓、售卖门控 free 拒/pro+enterprise 放行/未落地策略全拒。
- 单元测试：FuturesGridStrategyTest **20 passed**；LiquidationPriceCalculatorTest **30 passed**。
- 集成测试：PositionRiskMonitorTest 9 项（@redis）、FuturesBreakerSemanticsTest 4 项（@redis）、FuturesOrderExecutorTest 3 项、FuturesGatewayTest 2 项。
- 全量回归（排除 redis/exchange 组）：**396 passed / 71 skipped / 1 incomplete / 0 failed**。

**已知边界**：testnet 真实下单链路 + 仓位风控实跑待海外节点 + Binance futures testnet key（quickstart 齐备）；仓位风控 action/emergency 档依赖 Redis 单飞锁，Redis 不可用时保守返回 skipped（不重复下单）。

### ✅ 已完成：spec 006 自定义 DSL 策略引擎（2026-10-20）

自定义 DSL 策略引擎全量落地（6 工作流 38 任务）：受限 YAML 子集解析器 + 判别式 AST + 白名单沙箱解释器 + DslStrategy 全生命周期 + Filament 编辑器 + 历史回测预览 + 售卖/计费/风控接线。离线断言 + 单元/集成测试全绿。

| 模块 | 产出 | 文档 |
|---|---|---|
| DSL 解析 + AST | DslYamlParser（受限块式子集，拒锚点/别名/tag/flow/重复键）+ DslCompiler（白名单 + 枚举/范围/规模上限校验）+ DslParser 门面；DslDiagnostic 带 行/字段 定位 | [spec](specs/006-custom-dsl-strategy/spec.md) |
| 沙箱解释器 | WhitelistInterpreter：判别式 AST `match` 求值（comparison/logical/cross/always），白名单原生，禁 eval/exec/反射/IO；step/time budget 超限抛 DslExecutionException（不静默） | [contracts](specs/006-custom-dsl-strategy/contracts.md) |
| 指标快照 | IndicatorSnapshotBuilder：复用 IndicatorCalculator，窗口=max(period)+1 上限 150，NaN→null，prev shifted snapshot | — |
| DSL 策略 | DslStrategy（schema_version=2）：装载编译→onBar 求值→单飞挂单→shouldOpen/shouldClose bcmath 换算→onFill 入账→position_snapshot 持久化；崩溃恢复 pending 核实幂等（独立 recovered 标志） | — |
| 编辑器 | StrategyInstanceResource：params.dsl_script 文本域 + 即时校验（DslValidator）+ 试装载 action（仅编译不启动）；按策略联动显隐/必填；跨租户草稿隔离（TenantScope） | — |
| 回测预览 | HistoricalPreviewService：复用 P8 HistoricalQuery(KlineBar) 分页回放，与实跑共用同一 DslInterpreter + IndicatorCalculator；输出信号序列 + 简易资金曲线 + 统计 | — |
| 售卖门控 | config/strategy.implemented 加入 custom_dsl；pro/enterprise 套餐白名单加入，free 不含；StrategyWhitelistIntegrityTest 防超卖 | — |
| 计费/风控 | DSL 下单复用 OrderExecutor（QuotaGuard 两阶段计费）+ RiskGuard（熔断 OPEN 拦截开仓、平仓放行） | — |
| POC 命令 | `poc:verify-dsl`（28 项离线断言 SC-001~010：编译/红线/生命周期/等价/恢复/预览/门控/预算/精度） | [quickstart](specs/006-custom-dsl-strategy/quickstart.md) |

**实测结果**：
- 离线断言 PASS：`php artisan poc:verify-dsl` → **28/28 ALL CHECKS PASSED**。
- 等价性：DslEquivalenceTest — DCA 与 SpotGrid 决策序列（bar/side/qty）与 DSL 100% 一致；DSL ma7 指标与内置 IndicatorCalculator 逐点一致。
- 预览一致性：DslPreviewConsistencyTest — 预览信号序列 == 实跑信号序列。
- 编辑器：DslEditorValidationTest — 校验定位 / 阻断保存 / 试装载不启动实例 / 租户隔离。
- 门控/计费/熔断：DslPlanGateTest + DslBillingBreakerTest（@redis）— free 拒/pro+enterprise 放行；配额耗尽交易所零调用；熔断 OPEN 开仓拦截/平仓放行。
- 沙箱红线：DslSandboxRedlineCorpusTest（21 条恶意语料解析期+运行期双处拒绝）+ DslNoDynamicEvalTest（DSL 核心目录静态扫描零红线关键词、判别式 match 无 switch）；DSL 全量 147 tests / 561 assertions 全绿。

**已知边界**：DSL 预览未模拟滑点/手续费（结果中已注明）；FuturesDca / SmartMartingale / TrendRolling 骨架已于 2026-10-20 移除（B1 销账，未来实现须先立项 spec）。

### ✅ 已完成：spec 007 支付网关 + 计费 UI（2026-10-20，离线轨道全验，真实 BTCPay 待节点）

商业化支付闭环全量落地（7 工作流 64 任务）：BTCPay TRC20-USDT 收单基线 + webhook 验签幂等 + 订阅激活单事务 + 续费提醒/失败流转 + Filament 计费页 + Billing REST API。平台侧全部离线可验（Fake 通道），真实收单闭环待海外节点按 [P9 前置 BTCPay PoC 执行方案](docs/p9-btcpay-poc-plan.md) 执行。

| 模块 | 产出 | 文档 |
|---|---|---|
| 支付数据层 | `payment_invoices`（ref unique / amount_usdt decimal(20,8) bcmath / 状态机 pending→processing→partial→confirmed，expired→manual）+ `payment_webhook_events`（processed_key 可空 unique 幂等锚，仅 processed 落值）+ `payment_renewal_reminders`（unique 四元组防重发） | [spec](specs/007-payment-gateway-billing/spec.md) |
| 通道抽象层 | PaymentChannelRegistry（未配置通道抛异常**不降级**）+ 通道契约 4 方法（code/createInvoice/verifyWebhook/parseWebhook；fetchInvoice 登记为后续扩展点）+ FakePaymentChannel（**仅 local/testing 可用，其它环境启用即抛异常**）+ BtcPayChannel（HMAC-SHA256 验签纯函数单测） | [contracts](specs/007-payment-gateway-billing/contracts/) |
| 支付闭环（US1） | PaymentInvoiceService（建单/同套餐不堆叠/TTL 过期清扫/足额·不足额·超额判定）+ BillingWebhookController 固定流水线（验签失败 401→解析→ref 定位→条件更新→激活→审计）+ PaymentActivationService（**条件更新 + SubscriptionService::provision(trialDays:0) + processed_key 同事务**；失败回滚→webhook 500→通道重投，无半激活） | [plan](specs/007-payment-gateway-billing/plan.md) |
| webhook 加固（US2） | Redis 单飞锁削峰（不可用时降级放行）+ processed_key 唯一幂等（重放零副作用）+ 乱序事件不回退状态机 + unknown ref→rejected 审计 + 伪造签名 100% 拒绝 + 原始载荷审计留存 | — |
| 续费与失败流转（US3） | RenewalReminderService（7/3/1 天三档提醒）+ 既有 P3 状态机接线（active→past_due→宽限→suspended→补缴恢复，ends_at 顺延一个周期）+ `billing:renewal-reminders` / `billing:refresh-status` / `billing:expire-invoices` 调度注册 | — |
| 计费页 + API（US4） | Filament 计费页（当前套餐/周期起止/当日用量与上限/Invoice 列表/升级 3 步内出 USDT 金额·收款地址·倒计时，再点引导既有待支付单不堆叠）+ Billing REST API（auth:sanctum+tenant 栈双租户隔离） | — |
| POC 命令 | `poc:verify-payment`（35 项离线断言 SC-001~006 平台侧）+ `billing:fake-webhook`（dev 环境构造带签名 webhook，等价真实 BTCPay 投递） | [quickstart](specs/007-payment-gateway-billing/quickstart.md) |

**实测结果**：
- 离线断言 PASS：`php artisan poc:verify-payment` → **35/35 ALL CHECKS PASSED**（建单→验签→状态推进→激活→配额生效→幂等重放→伪造签名拒绝→提醒判定；webhook 单请求 <200ms）。
- 单元测试：PaymentInvoiceServiceTest / PaymentStatusMachineTest / WebhookSignatureTest / PaymentChannelConfigTest / RenewalReminderServiceTest 全 PASS。
- 集成测试：FakeChannelCheckoutFlowTest（US1 全链路）/ WebhookIdempotencyTest（同 ref 重放 3 次激活 1 条）/ WebhookSignatureRejectionTest / WebhookOrderingAndUnknownRefTest / RenewalRecoveryTimelineTest / BillingPageTest + BillingApiTest（双租户零串扰）全 PASS；`--group=redis` WebhookConcurrencyTest（并发同 ref 单飞锁）Redis 在线全绿。
- SC-001~006 平台侧全部离线验收（对照表见 [quickstart §8](specs/007-payment-gateway-billing/quickstart.md)）。

**已知边界**：收单链仅 TRC20-USDT；不足额支付有效期内可补齐（不引入账户余额体系）；无自动退款（超额不记账）；过期后到账转 manual 不自动激活；计费周期仅月度；通道主动查单（fetchInvoice）不在本期；`fake` 通道仅 local/testing，生产 MUST 显式 `PAYMENT_CHANNEL=btcpay`；真实 USDT 收单闭环（PoC 阶段 A/B/C）**待海外节点**。

### ✅ 已完成：spec 008 回测引擎 + 绩效分析（2026-10-21，离线轨道全验）

历史回放与绩效分析全量落地（8 工作流 50 任务）：回测与实跑共用 `StrategyRegistry::make` 唯一构造入口（FR-003），Runner 纯内存执行零交易所调用（FR-004），撮合假设可配置并在报告显式标注（FR-005），异步队列 + 单飞锁 + 崩溃收割，报告持久化二次查看零重算（FR-008），Filament 回测绩效页四区展示。离线断言 + 单元/集成/Feature 测试全绿。

| 模块 | 产出 | 文档 |
|---|---|---|
| 回测数据层 | `backtest_runs`（状态机 pending→running→completed/failed；params_snapshot / assumptions 为发起时刻快照）+ `backtest_reports`（9 指标 + equity_curve JSON + 假设快照）+ `backtest_simulated_trades`（append-only，seq 作等价性锚点）；三表租户隔离 | [spec](specs/008-backtest-engine/spec.md) |
| 驱动核心（US1） | BacktestRunner：HistoricalQuery 分页回放（CH/MySQL 路由复用）→ `StrategyRegistry::make` 构造 → onBar/onFill 驱动 → EquityCurveBuilder 净值（逐笔扣手续费）；MatchingEngine bar 级撮合 `close×(1±bps/10000)` + fee bcmath；覆盖率 < 80% 即 failed | [contracts](specs/008-backtest-engine/contracts/) |
| 绩效指标（US1） | PerformanceCalculator 9 指标：total/annualized（365/days 线性）、maxDrawdown、Sharpe（float，零方差 null）、winRate（bcmath 截断）、profitFactor（零亏损 null）、totalFee / finalEquity / barCount | [绩效契约](specs/008-backtest-engine/contracts/performance-metrics-contract.md) |
| 等价性（US2） | 回测与实跑共用同一策略构造入口 + 决策序列 100% 一致（fixture 4 笔）；净值误差 < 1% 且方向可解释（回测含费 ≤ 实跑毛收益） | [quickstart](specs/008-backtest-engine/quickstart.md) |
| 异步队列（US3） | RunBacktestJob（backtest 队列，tries=1）+ Redis 单飞锁（`SET NX EX120` + Lua token 释放，不可用降级）+ 租户并发上限 + `backtest:reap-stale`（running 超时 → failed/reaped，每 5m 调度）+ `backtest:run`（默认 dispatch，`--sync` 调试） | [worker 样板](deploy/supervisor/worker-backtest.conf.example) |
| 假设可配置（US4） | MatchingAssumptions：initial_capital / fee_rate / slippage_bps 可配并校验；报告显式展示假设 + 未建模项声明（bar 级撮合、futures 资金费率）；净值已扣手续费 | — |
| 回测绩效页（US5） | Admin「策略」组 →【回测绩效】（slug `backtest-performance`）：run 列表 10s 轮询 + 发起表单（实例或 inline DSL、区间、资金/费率/滑点）+ 详情四区（净值曲线/指标卡/假设/交易明细）+ 已完成 run「落地实盘」一键创建 stopped 实例（参数快照/交易对/周期预填，资金可覆盖，同策略同交易对去重）；跨租户零串扰 | — |
| POC 命令 | `poc:verify-backtest`（A~J 十组 55 项离线断言：撮合/驱动/绩效/等价/生命周期/并发/隔离/性能/红线/报告持久化） | [quickstart](specs/008-backtest-engine/quickstart.md) |

**实测结果**：
- 离线断言 PASS：`php artisan poc:verify-backtest` → **55/55 ALL CHECKS PASSED**；H 组性能：105,120 根 5m bar（365 天）端到端 **6.577–9.079s**（两轮实测，预算 30s，SC-001）。
- 单元 35 / 集成 25 / Feature 13 全绿；全量回归 **816 passed / 10 skipped / 1 incomplete / 3167 assertions**（2026-10-21 复测，含 P11 参数寻优闭环、回测落地实盘、信号一键下单与自动跟单；spec 008 交付时 `FuturesBreakerSemanticsTest` 2 项失败已于 2026-10-21 修复为 Redis 熔断键用例间串场，属测试隔离问题，非产品缺陷）。
- 等价性：BacktestEquivalenceTest — fixture 决策序列（bar/side/qty）100% 一致，净值 ≤ 实跑毛收益。
- 报告零重算：BacktestReportReloadTest + J 组 DB::listen — 二次读取 backtest_* 写查询 0。
- 红线：BacktestNoExchangeCallTest + I 组静态扫描 — Runner 依赖面不含 exchange/adapter，零交易所调用。
- 租户隔离：G 组 + BacktestPerformancePageTest — 双租户列表/详情互不可见。

**已知边界**：撮合为 bar 级（close±bps），不建模盘口深度/部分成交；futures_grid 未含资金费率影响（历史 funding 无数据源，报告已标注）；Sharpe 为 float 展示指标（落账全 bcmath）；年化按线性折算；区间上限 1 年、覆盖率阈值 80%（config/backtest.php 可调）；回测不计配额（FR-011），同实例单飞超限拒绝不排队；真实 testnet 长期净值对照**待海外节点**实跑数据积累。

### ✅ 已补齐：控制面热启停 + API 写接口（2026-10-21）

- **遗留红灯销账**：`FuturesBreakerSemanticsTest` 2 项失败根因为熔断 Redis Hash 未在用例间清理（HALF_OPEN 串场），补 setUp/tearDown 清理后全量 **765 passed / 0 failed**。
- **策略生命周期热生效**：`market:feed` 保活协程每 5s 比对 DB 期望状态与进程内协程，后台/Filament/API 点「启动」（status=starting）5s 内热拉起并推进 running；停止由实例协程监听 stopped 自停；启动扫描覆盖 `starting/running/paused_by_breaker/paused_by_position_risk/paused_by_reconcile`，重启保留暂停态，`paused_by_reconcile` 同样禁止开仓。
- **REST API 去 202 骨架**：交易所账户 store/update/destroy 走 `ExchangeAccountService`（租户 DEK + AES-256-GCM 加密，响应不回明文，被实例引用时 409）；策略实例 store/update/destroy/start/stop（运行中改配/删除 409、跨租户 404）；Dashboard 五面板聚合（market_heat/balance/signal_feed/alert_feed/liquidation_map，全部库内/Redis 数据）。
- **Filament 账户页接服务**：创建/编辑页改走 `ExchangeAccountService`（虚拟密钥字段加密落库，编辑留空保留原密文），被策略实例引用的账户隐藏删除入口，`status` 选项对齐 DB 枚举 `active/revoked/expired`。
- **新增测试**：`MarketFeedDryRunTest`（活状态扫描口径 + dry-run 不依赖 swoole）、`StrategyApiTest`（加密落库/状态机/409/404/聚合结构）、`ExchangeAccountPageTest`（Filament 创建加密/编辑留空保原密文）。

### ✅ 已完成：P11 参数寻优（2026-10-21，网格搜索 + walk-forward）

在 P10 回测引擎之上补齐参数调优（原 P4 调优范围）：网格展开 → 逐组合复用 `BacktestRunner` 回放 → 按目标选优；walk-forward 把区间切为 folds+1 段，训练段选参、样本外评分，跨折取均值抑制过拟合。同步执行、零交易所调用，结果落 `parameter_optimizations` + `parameter_optimization_trials`，`--dry-run` 零写入。

| 模块 | 产出 |
|---|---|
| 网格展开 | `ParameterGrid`：显式值列表或 `min/max/step` 双语法 + 策略参数白名单 + 笛卡尔积上限 200（`config/optimization.php`） |
| 寻优执行 | `ParameterOptimizer`：grid 全区间选优；walk_forward 分折训练/样本外并以 OOS 均值排名；单组合失败落 failed trial，不中断整体 |
| 目标口径 | `total_return_pct` / `sharpe_ratio` / `max_drawdown_pct` / `profit_factor`；回撤越小越好，其余越大越好；`min_trades` 过滤 |
| 持久化 | 任务表（状态机 / 进度 / best_params / best_score / report / 请求快照）+ trials 表（append-only：fold_index / in_sample / score / metrics），双表租户隔离 |
| 异步执行 | `RunOptimizationJob`（backtest 队列，tries=1）：按 id 还原请求快照 → 恢复租户上下文 → 写入 trials 与进度；`OptimizationService` 拆为 create / execute / dispatch（命令仍可同步 `run`） |
| REST API | `GET/POST /api/v1/parameter-optimizations`（创建 202 入队）+ `GET .../{id}` + `GET .../{id}/trials`（分页）；网格非法在构造期拒绝（422，不落 failed 任务） |
| Filament 页 | Admin「策略」组 →【参数寻优】（slug `parameter-optimization`）：列表 10s 轮询 + 发起表单（JSON 网格/固定参数/目标）+ 详情（最优参数 / Top N / 分折明细 / 逐次试跑） |
| 结果应用 | `POST /api/v1/parameter-optimizations/{id}/apply`（body `instance_id` + 可选 `rank`）+ 详情页「应用到实例」action：支持 Top N 任选参数组，best/base params 合并写入实例 params（保留实例其他键），实例须同策略 / 同交易对 / 同周期且 `stopped`；事务写应用历史（前后快照 + 操作人），`POST /parameter-optimization-applications/{id}/rollback` 可回滚一次（参数被人工修改或运行中拒绝），`GET /strategy-instances/{id}/optimization-applications` 反查实例参数来源 |
| 命令 | `backtest:optimize`（`--grid` JSON 或 `@file`、`--mode`、`--folds`、`--objective`、`--top`、`--dry-run`） |

```bash
php artisan backtest:optimize spot_grid BTCUSDT 1m \
  --from="2023-11-14 00:00:00" --to="2023-11-14 00:30:00" --tenant=1 \
  --params='{"lower_price":"90","upper_price":"110","investment_amount":"1000"}' \
  --grid='{"grid_count":[5,10,20]}' --objective=total_return_pct --top=3

# walk-forward：2 折 = 3 段，训练段选参、样本外评分
php artisan backtest:optimize spot_grid BTCUSDT 1m \
  --from="2023-11-14 00:00:00" --to="2023-11-14 00:30:00" --tenant=1 \
  --params='{"lower_price":"90","upper_price":"110","investment_amount":"1000"}' \
  --grid='{"grid_count":[5,10]}' --mode=walk_forward --folds=2
```

**实测输出**（30min 1m fixture，grid 3 组合）：
```
mode=grid objective=total_return_pct combos=3 trials=3 failed=0
best score=1.01820060 params={"lower_price":"90","upper_price":"110","investment_amount":"1000","grid_count":5}
#1 score=1.01820060 return=1.01820060 dd=0 trades=6 params={"...":"...","grid_count":5}
#2 score=0.50910030 return=0.50910030 dd=0 trades=6 params={"...":"...","grid_count":10}
#3 score=0.25455015 return=0.25455015 dd=0 trades=6 params={"...":"...","grid_count":20}
```
walk-forward（folds=2、2 组合、8 trials）两折均选中 `grid_count=5`，OOS 均值 **0.33940020**。

**测试**：新增 23 项（网格展开/白名单/上限、排名方向、失败隔离、walk-forward 汇总、命令落库 / dry-run / 租户隔离、API 创建/详情/trials/隔离/422、Filament 页发起与详情渲染）；全量 **788 passed / 10 skipped / 1 incomplete / 3031 assertions / 0 failed**。

**已知边界**：UI/API 走 backtest 队列异步执行，CLI 仍为同步；仅内置策略参数可寻优（DSL 脚本不在本期）；walk-forward 为等长分段（非滚动锚定）；单次组合上限 200。

### 🚧 待实现：剩余路线图

> P1 策略+下单闭环 / P2 AI 信号闭环 / P3 多租户计费 / P4 高级风控 / P5 大户爆仓数据 / spec 001 OKX / spec 002 Bybit / spec 003 Bitget / spec 004 ClickHouse 历史归档 / spec 005 合约网格+仓位风控 / spec 006 自定义 DSL / spec 007 支付网关+计费 UI / spec 008 回测引擎+绩效分析 已完成（spec 004 为核心落地、spec 007 离线轨道已验证真实链路待节点、spec 008 离线全验且真实 testnet 净值对照待节点，节点验收待回填），实测结果见上方对应区块。
> **P5.5 上线前工程化加固代码侧已完成（2026-10-15）**——步骤 1–4 产物、三环境实测证据与待节点侧实跑清单见 [P5.5 执行计划](docs/p5.5-hardening-plan.md#执行结果与实测证据2026-10-15步骤-14-代码侧全部完成)。

| 阶段 | 范围 | 验证目标 | 关键风险 | 依赖 |
|---|---|---|---|---|
| **P5.5 上线前工程化加固 ✅ 代码侧完成** | ①账实对账（内存持仓 vs 交易所）②SpotDca 落地（free 套餐已售卖）③poc:verify-\* 断言迁入 PHPUnit（157 项三环境全绿）+ CI ④supervisor 样板 / billing+reconcile 调度 / 海外节点 runbook | 代码侧验收全过；testnet 真实成交/转出对账/强平 120 秒/supervisor kill -9/Actions 绿灯待节点与首次推送，清单见 [P5.5 执行计划](docs/p5.5-hardening-plan.md) | 资金安全基线、断言迁移回归面 | P1–P5 |
| **P6 多交易所 Adapter 扩展 ✅ 代码侧完成** | ~~OKX / Bybit / Bitget~~ 三家现货 Adapter 全部落地（spec 001/002/003），复用 P1 BinanceSpotAdapter 范式；Signing 签名域统一四家；统一错误码 → `ExchangeApiException` 翻译表四家一致性。**「第四家接入纯增量」验证通过**：spec 003 落地期 `AbstractExchangeAdapter` 零改动、前三家测试零回归 | Binance 已实跑；OKX demo / Bybit testnet / Bitget 主网小额单待 key + 海外网络（quickstart 齐备，验收总清单见 [海外节点 Runbook](docs/overseas-node-runbook.md)）；P1 `market:feed` 切换所代码 ≤10 行（StrategyExchangeCompatibilityTest 四家断言） | 各所 WS 协议差异（confirm 标志 vs bar 切换检测、心跳形态）、签名算法差异、testnet 配额/可用性 | P1 |
| **P8 ClickHouse 历史数据归档 ✅ 核心落地（2026-10-19，spec 004；节点机 CH 冒烟与端到端验收待回填）**（执行顺序先于 P7，理由见下） | kline_bars / signals / strategy_orders / liquidation_events 迁移到 ClickHouse；冷热分层（Redis 热缓存→MySQL 当日→CH 历史）；查询层抽象 `HistoricalQuery` | 一周历史 K 线查询 < 500ms；迁移窗口内业务零感知；冷热切换校验 | CH 迁移窗口与写入并发、查询方言差异（GROUP BY 严格）、TenantScope 在 CH 的下沉 | P1, P5 |
| **P7 Custom DSL 策略引擎 ✅ 代码侧完成（2026-10-20，spec 006）** | YAML 受限子集声明式策略（entry/exit/filter/risk 四段）；白名单沙箱解释器（禁 eval/exec/fs/网络/反射）；Filament 在线编辑器（即时校验+试装载）+ 历史 K 线回测预览（复用 P8 HistoricalQuery）；售卖门控回归白名单（pro/enterprise） | 一条 DSL 装载跑通 onBar→信号→下单→成交回报闭环；与 SpotDca/SpotGrid 决策序列 100% 等价；`poc:verify-dsl` 28/28 全绿 | DSL 表达力 vs 安全（白名单原语）、每 bar 解释开销（step budget 500）、与 RiskGuard/QuotaGuard 接线 | P1, P2 |
| **P9 支付网关 + 计费 UI ✅ 我方实现已交付（2026-10-20，spec 007；离线轨道已验证，真实 BTCPay 链路待节点）** | 通道抽象层（Registry 不降级）+ fake 离线通道（仅 local/testing）+ BtcPayChannel（验签纯函数已单测）；TRC20-USDT 支付单（decimal:8 bcmath 金额/同套餐不堆叠/TTL 过期清扫）；webhook 固定流水线（验签失败 401 + rejected 审计、processed_key 唯一幂等、Redis 单飞锁降级、状态机只进不退）；激活单事务接线 P3 provision；续费提醒 7/3/1 档 + past_due→suspended 既有流转；Filament 计费页（当前套餐/当日用量/Invoice 列表/升级 3 步出支付信息）+ Billing REST API（双租户隔离） | `poc:verify-payment` 35/35 全绿（建单→验签→状态推进→激活→配额生效→幂等重放→伪造签名拒绝→提醒判定，webhook 单请求 <200ms）；SC-001~006 平台侧全部离线验收；真实 USDT 收单闭环（B1~B3/C1~C2）待节点按 [P9 前置 BTCPay PoC 执行方案](docs/p9-btcpay-poc-plan.md) 执行后销账 | ~~支付回调幂等~~（已落地：processed_key 唯一 + 条件更新）、汇率/税额（不做，见已知边界）、**通道合规（数字货币 SaaS 品类受限，BTCPay 自托管为基线，Stripe 申报远期并行）** | P3 |
| **P10 回测引擎 + 绩效分析 ✅ 已完成（2026-10-21，spec 008；P4 参数调优建议不在本期）** | 历史回放（HistoricalQuery 分页→策略驱动，CH/MySQL 路由复用）；策略绩效 9 指标（Sharpe/最大回撤/胜率/盈亏比/年化等）；撮合假设可配置并在报告显式标注；异步队列 + 单飞锁 + 崩溃收割；Filament「回测绩效」页（列表/发起/详情四区） | `poc:verify-backtest` 55/55 全绿；1 年 5m（105,120 bar）端到端 **6.577–9.079s** < 30s（SC-001）；fixture 决策序列 100% 一致 + 净值误差 < 1%（SC-002）；报告二次查看零重算（SC-003）；零交易所调用（SC-005）；双租户零串扰（SC-006）；真实 testnet 长期净值对照待海外节点 | 回测精度（撮合假设、滑点、手续费）、参数过拟合、与真实下单语义一致 | P1, P7, P8 |
| **P11 参数寻优 ✅ 已完成（2026-10-21，P10 延伸）** | 参数网格展开（显式值 / min-max-step + 策略白名单 + 200 组上限）；grid 全区间逐组合选优；walk-forward 分折训练/样本外 OOS 均值排名；REST API（创建 202 入队 / 详情 / trials）+ Filament 参数寻优页 + `backtest:optimize` 命令；双表持久化（含请求快照）、租户隔离 | 23 项新增测试全绿；30min 1m fixture grid 3 组合实测选优与 walk-forward 分折输出符合预期；全量回归 788 passed / 0 failed | 过拟合（walk-forward 缓解但非消除）、组合数增长带来的队列耗时 | P10 |
| **P2 延伸：信号执行 ✅ 已完成（2026-10-21）** | 人工确认的一键下单（REST API + Filament「策略 → 信号」页）：long→buy / short→sell 市价；仅 running 且同交易对实例、15 分钟执行窗口、名义额上限且不超过实例分配资金；现货拒绝做空；凭据解密失败拒绝；`client_order_id=sig_{signal}_{instance}` 幂等，复用 OrderExecutor 权益/日配额闸门与先落库后下单。**订阅级自动跟单**：`auto_execute` + 执行实例 + 最低置信度 + 当日信号单上限，Pipeline 落库后投递 orders 队列 `AutoExecuteSignalJob`，Job 内二次校验并复用同一执行服务 | 新增 5 项 API + 1 项页面 + 6 项自动跟单 Job + 1 项订阅表单测试全绿（17 + 10 + 11 + 7 断言）；全量回归 816 passed / 0 failed | 真实资金误操作（人工确认/订阅开关 + 幂等 + 日上限）、名义额/资金上限、自动跟单追单风险（TTL + 置信度 + 实例 running） | P1, P2 |

> **执行顺序说明（2026-10-18 决策）**：P8 先于 P7。理由：①kline_bars/liquidation_events 持续增长，归档越晚迁移窗口越大、历史校验越难；②P10 回测与 P7 的回测预览都依赖 P8 的 `HistoricalQuery`。阶段编号保持不动（P7/P8 仅为标识）；P7 的表达力设计可与 P8 实现并行做 specify（纯文档阶段）。
> **P9 前置注记（2026-10-18 合规调研结论）**：
> - **Stripe**：加密货币交易所/钱包属「受限业务，需联系销售审批」；本平台是服务交易者的 SaaS（非交易所本体），属灰色地带，需走受限品类申报，且 Stripe 主体需支持地区实体——**不确定、周期长，不作为首发通道**。
> - **支付宝/微信**：境内虚拟币相关业务明确禁止，**直接排除**。
> - **加密原生通道**：Coinbase Commerce 已于 2026-03-31 关停（继任 Coinbase Business 仅美/新商户+KYB 托管）；可选 **BTCPay Server**（自托管、零费率、无 KYC）、**NOWPayments**（托管、350+ 币种）、BitPay、Binance Pay。
> - **产品形态推论**：加密支付是 push 模式、无卡基代扣，自动续费只能走「到期前提醒→手工续付→P3 past_due→suspended 既有流转」，P3 状态机天然兼容；specify 时按「USDT 收单（BTCPay 自托管为先）+ 手工续费」为基线方案，Stripe 申报作远期并行线。

### 🧾 技术债与已知边界汇总（截至 P5，2026-10-15 盘点）

> 各阶段"已知边界"的统一索引，按风险分级；P5.5 优先处理第一类。

**A. 资金 / 安全类（上线前必须解决）**

| # | 欠债 | 来源 |
|---|---|---|
| A1 | ~~无账实对账~~ **P5.5 步骤 1 已还**：`reconcile:run/status` + `reconciliation_reports`（ok/drift/critical/unknown 四级，每 5 分钟调度；critical 自动暂停实例 `paused_by_reconcile` + 多渠道告警，**只报告不自动改账**）；testnet 转账检出 drift 待海外节点实跑 | P1 已知边界 |
| A2 | ~~售卖能力超出代码~~ **P5.5 步骤 2 已还**：SpotDca 完整落地；套餐白名单硬闸门先查 `config('strategy.implemented')`，pro/free 仅保留已实装的 spot_grid/spot_dca，合约骨架策略全部摘出白名单 | P0/P1 盘点 |
| A3 | ~~drawdown 缺真实净值口径~~ **P5.5 步骤 1 已产数据**：每次成功对账写 `balance_snapshots` 净值序列（算法切换留待后续；本期只产数据） | P4 已知边界 |
| A4 | ~~合约策略为骨架且缺仓位级风控~~ **spec 005 已还**：FuturesGridStrategy 完整实现（6 决策 + reduceOnly + 崩溃恢复）；PositionRiskMonitor 三档风控（warning/reduce/emergency_close）+ 强平价计算器；paused_by_position_risk 状态；testnet 真实链路待海外节点补测 | P0/P5 盘点 |

**B. 功能占位 / 未实测**

| # | 占位项 | 当前行为 |
|---|---|---|
| B1 | ~~SpotDca 等 5 策略全抛异常~~ **P5.5 步骤 2：SpotDca 已实现并带 16 项测试**；~~FuturesGrid 骨架~~ **spec 005 已实现**（20 项测试）；~~FuturesDca / SmartMartingale / TrendRolling 骨架~~ **2026-10-20 已移除**（注册表/配置/目录注释同步清理，未落地语义改由虚构 code 测试守护；未来实现须先立项 spec 并加入 `strategy.implemented` 白名单） | 方法体全部 `throw new RuntimeException('Not implemented')` |
| B2 | ~~Custom DSL（DslParser / DslStrategy）骨架~~ **spec 006 已还**：受限 YAML 子集解析器 + 判别式 AST + 白名单沙箱解释器（禁 eval/exec/fs/网络/反射）+ DslStrategy 全生命周期（schema_version=2 + 崩溃恢复幂等）+ Filament 编辑器/试装载 + 历史 K 线预览；DslBillingBreakerTest 验证计费/熔断接线 | 无骨架残留 |
| B3 | ~~OKX / Bybit / Bitget Adapter~~ **已全还**：spec 001/002/003 三家现货落地（REST/WS/签名下单；OKX 模拟盘 header + Bybit testnet 域名开关 + Bitget 无模拟盘边界）；四家横切契约测试齐备（错误码/路由/策略兼容性）；真实链路实跑待 key + 海外网络（验收总清单见 runbook） | 无骨架残留 |
| B4 | ~~BinanceFuturesAdapter 仅强平流~~ **spec 005 已补全 9 个交易接口**：placeOrder/cancelOrder/getOrder/getPosition/setLeverage/setMarginType/leverageBracket/getBalance/getTicker + 错误码翻译；testnet 真实下单待海外节点 | 无骨架残留 |
| B5 | ~~企微 / 邮件通知渠道~~ **已实现（2026-10-18）**：WeComChannel（群机器人 markdown webhook）+ MailChannel（DomainNotificationMail 纯文本）注册入 channel_classes，信号/风控/对账三链共用；未配置即抛异常落 failed（与 Telegram 同契约，既有 failed 断言不变）；6 项渠道测试；待 WECHAT_WORK_WEBHOOK / NOTIFY_MAIL_TO 实测（入 runbook 验收矩阵 #10） | 配置缺失=failed 语义 |
| B6 | Telegram 渠道 | 代码就绪，未配 TELEGRAM_BOT_TOKEN/CHAT_ID 真实实测（信号 + 风控两处，同一渠道类双链复用） |
| B7 | ~~Reverb 告警广播缺前端~~ **已接（2026-10-18）**：补 `config/broadcasting.php`（原缺失导致 broadcast 静默失败，[坑 #29](docs/pitfalls.md)）+ Admin 面板 BODY_END 注入 `window.__tenantAlerts` 与 alerts.js（Echo 订阅 `private-tenant.{tid}.alerts` → FilamentNotification 弹窗），Feature 测试覆盖注入/平台面板不串订阅；待 reverb 进程 + REVERB_* 实跑联调 |
| B8 | P5 真实强平流 | 离线 15/15 已迁入 PHPUnit（`tests/*/Liquidation`，@redis）；**主网实跑待海外节点**——部署 runbook 与验收步骤已交付：[海外节点 Runbook](docs/overseas-node-runbook.md)，节点就绪后 `poc:liquidation-demo --duration=120` 即销此项 |

**C. 工程 / 运维类**

| # | 欠债 | 影响 |
|---|---|---|
| C1 | ~~无正式测试/CI~~ **P5.5 步骤 0+3 已还**：default/redis/mysql/smoke 分组 + GitHub Actions 双 job；154 项手工断言已全部迁移（150 项入 PHPUnit、5 项真实服务 smoke 保留于 poc 命令、1 项非断言丢弃，映射表见 [P5.5 计划](docs/p5.5-hardening-plan.md#步骤-3-迁移映射附录验收标准-1逐条映射无遗漏丢弃写明理由)）；当前三环境实测 157 项全绿；Actions 绿灯待首次推送 | P6 多交易所后回归成本指数上升 |
| C2 | ~~手工 nohup 保活~~ **P5.5 步骤 4 已交付**：[deploy/supervisor/](deploy/supervisor) 五样板（market-feed / worker-ai / worker-default / octane / reverb，autorestart + 轮转说明）；节点 kill -9 拉起验收待海外节点执行 | 进程退出无人拉起 |
| C3 | ~~refreshStatus 未挂调度~~ **P5.5 步骤 4 已还**：`billing:refresh-status` 薄命令 + 每日 UTC 00:05 调度（`schedule:list` 可查，带流转测试） | past_due→suspended 需手工触发 |
| C4 | DEK 轮换为非热轮换，需停实例 + 操作停窗 | 运维窗口期限制 |
| C5 | REST 走阻塞 curl（Swoole hook 关闭 CURL，坑 #1） | 单实例可接受，多租户高密度需换协程 HTTP client |
| C6 | ~~无支付网关 / Invoice / Filament 计费页~~ **spec 007 已还**：通道抽象 + fake 离线通道 + BtcPayChannel（验签纯函数单测）；支付单/webhook 幂等/激活事务/续费提醒全链路 `poc:verify-payment` 35/35 离线验证；Filament 计费页 + Billing API 交付；**真实 BTCPay 收单闭环待海外节点**（B1~B3/C1~C2 清单见 [P9 前置 BTCPay PoC 执行方案](docs/p9-btcpay-poc-plan.md)） | 商业化前置，P9 |

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
php artisan queue:work redis --queue=ai,notifications,orders --tries=2  # AI + 通知 + 自动跟单

# 4.（可选）人工确认的一键下单：API 或「策略 → 信号」页右上角「执行信号」
curl -X POST /api/v1/signals/{id}/execute -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" -d '{"instance_id":1,"notional":"100"}'
# long→buy / short→sell 市价；需 running 实例 + 15 分钟执行窗口；client_order_id=sig_{signal}_{instance} 幂等

# 5.（可选）信号自动跟单：在「信号订阅」编辑页开启「自动跟单」，绑定 running 实例，
#    并设置单次名义额 / 最低置信度 / 当日信号单上限（0=不限）
```

无 key 或 AI 故障时链路自动降级到 RuleEngineFallback，不影响运行；可选 `TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID` 开启 Telegram 渠道。**P2 分析链路默认仍不自动下单**：订阅显式开启自动跟单后才会投递 orders 队列 Job，未开启时仍仅通知 + 人工确认执行。

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

### POC 验证（spec 005 合约网格 + 仓位风控）

```bash
# 1. 离线全链路断言（36 项：强平价公式 + 保证金率语义 + 决策矩阵 6 种 + 风控三档 + 售卖门控）
php artisan poc:verify-futures

# 2. testnet 合约实例装配（建实例 + 设杠杆 + 逐仓模式）
#    .env 配置 EXCHANGE_TESTNET_KEY/SECRET（futures testnet: https://testnet.binancefuture.com）
php artisan poc:futures-testnet-setup --symbol=BTCUSDT --leverage=10 --margin=100

# 3. 注入模拟仓位触发风控评估，验证 warning/reduce/emergency 三档动作
php artisan poc:futures-risk-simulate --tenant=1 --instance=1
```

`poc:verify-futures` 覆盖 36 项断言：强平价多/空 × 4 杠杆（1x/5x/10x/20x）+ MMR 影响、保证金率入场=1/强平=0/中点=0.5/越界钳制、决策矩阵 6 种（开多/开空/平多/平空/加多/加空）+ 区间外无信号、风控 warning/reduce/emergency 三档 + 无持仓、售卖门控 free 拒/pro+enterprise 放行/未落地策略全拒。

**注意**：仓位风控 action/emergency 档依赖 Redis 单飞锁，Redis 不可用时保守返回 skipped（不重复下单）；warning 档不依赖锁。

### POC 验证（spec 007 支付网关 + 计费 UI）

```bash
# 1. 一键离线全链路断言（35 项：建单→验签→状态推进→激活→配额生效→幂等重放→伪造签名拒绝→提醒判定）
#    无需 BTCPay/节点/外网（Fake 通道）；Redis 需在线（webhook <200ms 断言依赖）
php artisan poc:verify-payment

# 2. 集成测试（Fake 通道；默认 sqlite :memory: 即可跑，@redis 并发用例需 Redis）
php artisan test tests/Integration/Payment

# 3. 手工端到端（Fake 通道，无 UI）：建单 → 模拟回调 → 断言激活；同 ref 连投 3 次仅一次生效
php artisan billing:fake-webhook --ref=<ref> --event=fake.settled --paid=<amount_usdt>

# 4. 计费页点检：租户用户登录 /admin →「计费」→ 升级 3 步内出 USDT 金额/收款地址/倒计时
```

详细分层验证（单元/集成/SC-001~006 对照表/续费时间线复现）见 [quickstart](specs/007-payment-gateway-billing/quickstart.md)；`fake` 通道仅 `local/testing` 可用，生产必须 `PAYMENT_CHANNEL=btcpay`。

### POC 验证（spec 008 回测引擎 + 绩效分析）

```bash
# 1. 一键离线全链路断言（A~J 十组 55 项：撮合/驱动/绩效/等价/生命周期/并发/隔离/性能/红线/报告零重算）
#    无需交易所凭据/外网；F 组需 Redis 在线（不可达时 --only=A,B,C,D,E,G,H,I,J 排除后重跑）
php artisan poc:verify-backtest

# 2. 分层测试
php artisan test tests/Unit/Backtest
php artisan test tests/Integration/Backtest
php artisan test tests/Feature/Backtest

# 3. 手工端到端（sync 调试模式；策略/交易对/周期为位置参数，默认 dispatch 到 backtest 队列）
php artisan backtest:run spot_grid BTCUSDT 5m --tenant=1 --from=2026-07-01 --to=2026-10-01 \
    --params='{"lower_price":"100","upper_price":"120","grid_count":10,"qty_per_grid":"0.1"}' --sync

# 4. 回测绩效页点检：/admin →「策略」组 →「回测绩效」（slug backtest-performance）
#    已完成 run 详情页点「落地实盘」→ 选账户/资金 → 生成 stopped 策略实例（不自动启动）

# 5. 参数寻优（grid / walk-forward；--dry-run 只打印不落库）
php artisan backtest:optimize spot_grid BTCUSDT 1m --tenant=1 \
    --from="2026-07-01 00:00:00" --to="2026-10-01 00:00:00" \
    --params='{"lower_price":"100","upper_price":"120","investment_amount":"1000"}' \
    --grid='{"grid_count":[5,10,20]}' --objective=total_return_pct --top=3

# 6. 参数寻优页 / API（异步 backtest 队列）
#    /admin →「策略」组 →「参数寻优」（slug parameter-optimization）
curl -X POST /api/v1/parameter-optimizations -H "Authorization: Bearer <token>" \
  -d '{"strategy_code":"spot_grid","symbol":"BTCUSDT","interval":"1m",
       "from_utc":"2026-07-01 00:00:00","to_utc":"2026-10-01 00:00:00",
       "grid":{"grid_count":[5,10,20]},
       "base_params":{"lower_price":"100","upper_price":"120","investment_amount":"1000"}}'
# → 202；GET /api/v1/parameter-optimizations/{id} 查结果，.../{id}/trials 查逐次试跑

# 7. 应用寻优结果到策略实例（可选 rank 选 Top N，默认 1 = best；实例须 stopped）
curl -X POST /api/v1/parameter-optimizations/{id}/apply -H "Authorization: Bearer <token>" \
  -d '{"instance_id":1,"rank":2}'
# → 200 返回实例（params 已更新）+ application_id；运行中 409、不匹配 422、跨租户 404

# 8. 回滚一次应用 / 反查实例的寻优应用历史
curl -X POST /api/v1/parameter-optimization-applications/{application_id}/rollback -H "Authorization: Bearer <token>"
curl /api/v1/strategy-instances/1/optimization-applications -H "Authorization: Bearer <token>"
```

H 组实测：105,120 根 5m bar（365 天）端到端 **6.577–9.079s**（预算 30s）；SC-001~006 对照表与已知边界见 [quickstart](specs/008-backtest-engine/quickstart.md)。

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

### 运行测试（P5.5 步骤 0 起正式化）

```bash
php artisan test                       # 全套：default 必跑，redis/mysql 组缺服务时自行 skip
php artisan test --group=redis         # 只跑 @group redis（本机需 Redis：计量/聚合/风控/对账/通知去重等）
php artisan test --group=mysql         # 只跑 @group mysql（需真实 MySQL，见下）
php artisan test tests/Unit            # 纯逻辑组：不启动 Laravel/不连任何服务，断网可跑
```

当前规模（2026-10-21 本机实测，Redis 在线）：**781 passed / 10 skipped / 1 incomplete / 2982 assertions，约 2.5 分钟**（default 组必跑，redis 组真实执行，mysql 组自动 skip）。P5.5 基线（2026-10-15）：157 passed / 3 skipped / 588 assertions；154 项旧 POC 断言迁移映射见 [P5.5 计划](docs/p5.5-hardening-plan.md#步骤-3-迁移映射附录验收标准-1逐条映射无遗漏丢弃写明理由)。

分组约定：

| 组 | 依赖 | 服务不可用时 |
|---|---|---|
| default（无注解） | 无 | — 必须永远全绿 |
| `@group redis` | 本机 Redis | markTestSkipped，不允许 error（trait `ChecksRedisAvailability`） |
| `@group mysql` | `DB_CONNECTION=mysql` 指向真实 8.x | sqlite 下自动 skip |
| `@group smoke` | swoole/密钥/外网 | CI 永不跑，仅本机或 WSL 手工 |

mysql 组本机运行（测试库需预先建空库）：

```powershell
$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='crypto_saas_test'
php artisan test --group=mysql
```

CI：[.github/workflows/tests.yml](.github/workflows/tests.yml) 双 job（PHP 8.4 + Redis 的 SQLite job、PHP 8.4 + MySQL 8.4 + Redis 的 MySQL job），push main / PR 触发；`@group smoke` 不进 CI。

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
| [P5.5 工程化加固执行计划](docs/p5.5-hardening-plan.md) | 上线前还债：账实对账 / SpotDca / 测试正式化 / 运维化，含验收标准 |
| [P9 前置 BTCPay PoC 执行方案](docs/p9-btcpay-poc-plan.md) | 支付基线（BTCPay 自托管）收单/webhook 幂等/P3 接线的最小验证清单；**我方实现已交付（spec 007，离线轨道已验证）**，阶段 A/B/C 待节点照单执行 |
| [ADR-0001 双进程模型](docs/adr/0001-dual-process-model.md) | 为什么 Octane + 独立 Swoole |
| [ADR-0002 行级隔离](docs/adr/0002-row-level-tenancy.md) | 为什么 tenant_id 而非 schema 级 |
| [ADR-0003 AES-256-GCM](docs/adr/0003-aes-256-gcm-api-key-encryption.md) | 为什么 GCM 而非 CBC |
| [ADR-0004 熔断状态机](docs/adr/0004-circuit-breaker-state-machine.md) | 四维度 Rule + HALF_OPEN 探测 |
| [ADR-0005 ClickHouse 冷层](docs/adr/0005-clickhouse-cold-tier.md) | 自托管单节点 + MergeTree batch_id + 零依赖 HTTP + 隔离下沉 |
| [踩坑记录](docs/pitfalls.md) | 实测踩坑 47 条（#1–28 P1–P5.5 / #29–33 B7+P8 归档回填 / #34–38 spec 005 合约 / #39–42 spec 006 DSL / #43 spec 005 单飞锁修复 / #44–47 spec 008 回测；覆盖 Swoole/Guzzle/协程/PSR-4、残次聚合桶、两阶段计量 Lua、DEK 轮换、HALF_OPEN 死锁、Filament $view 非 static、phpredis zAdd 签名、futures wsBaseUrl 覆盖、主网网络不可达、TTL 去重键测试污染、phpredis 前缀 keys()、PHPUnit 12 DataProvider、空 testsuite 目录 CI exit 2、broadcasting 配置缺失静默失败、Blade @json 嵌套括号、CH 行哈希/lightweight delete、保证金率公式语义、命令自动发现目录、bcmath 截断 vs 展示值、净值扣费、零方差向量等）持续追加 |
| [spec-kit constitution](.specify/memory/constitution.md) | v1.0.0 七条原则（SDD 优先/金融精度/协程隔离/崩溃恢复幂等/计费语义/测试隔离/PSR-4 与基类对齐），约束所有 plan/analyze 阶段（spec 008 复核无需修订） |
| [specs/](specs/) | spec-kit SDD feature 规格：001 OKX / 002 Bybit / 003 Bitget 现货适配器 / 004 ClickHouse 历史归档 / 005 合约网格+仓位风控 / 006 自定义 DSL / 007 支付网关+计费 UI / 008 回测引擎+绩效分析（各含 spec/plan/tasks/research/data-model/contracts/quickstart） |

## 安全说明

- `.env` 包含 EXCHANGE_MASTER_KEY 和数据库密码，**绝不要提交到仓库**
- API Secret 明文仅创建时返回一次，加密存 exchange_accounts
- KEK（.env）丢失则所有加密的 DEK 无法解密，需重新创建租户
- 生产环境建议启用 HTTPS（Filament 默认需要）

## License

MIT
