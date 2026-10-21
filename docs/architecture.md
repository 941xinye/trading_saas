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
| 行情存储 | 实时 tick → Redis；历史 K 线/信号/订单/爆仓 → MySQL 热层 + ClickHouse 冷层（spec 004 已落地，按 applied_watermark 三段路由） | 写入频率分层：ms 级用 Redis；历史归档用列存，保留期内仍可纯热查询 |
| WS 推送 | Laravel Reverb（用户推送）+ 独立 Swoole 协程（交易所行情） | Reverb 复用广播认证体系；交易所行情不入 Reverb |

## 目录结构

```
app/
├── Domain/                     ← 领域模块（核心）
│   ├── Tenancy/                ← 多租户基础设施
│   ├── Exchange/               ← 交易所适配器（Binance/OKX/Bybit/Bitget 现货 + Binance 合约）
│   ├── Strategy/               ← 策略引擎（SpotGrid/SpotDca/FuturesGrid 实装；Custom DSL 沙箱解释器）
│   ├── Signal/                 ← AI 信号订阅与分发
│   ├── Risk/                   ← 熔断与风控（四维度 + 仓位风控）
│   ├── Billing/                ← 订阅计费（两阶段计量）
│   ├── Payment/                ← 支付网关（通道抽象 + webhook 幂等 + 激活）
│   ├── Reconciliation/         ← 账实对账（差异检测 + 暂停/恢复）
│   ├── Archive/                ← ClickHouse 冷热归档（批次管线 + HistoricalQuery）
│   ├── Backtest/               ← 回测引擎（回放驱动 + 撮合 + 绩效指标）+ Optimization/（参数网格 + walk-forward 寻优）
│   ├── Notification/           ← 多渠道通知（Log/Telegram/企微/邮件）
│   └── Liquidation/            ← 大户爆仓聚类（Clusterer/MapService/FeedRunner）
├── Http/
│   ├── Middleware/BootstrapTenancy.php
│   ├── Controllers/Api/V1/
│   └── Resources/
├── Filament/{Admin,Platform}/   ← 两套 Filament 面板（含回测绩效页、计费页）
├── Jobs/ {AiAnalyze, DispatchNotification, RiskNotification, RenewalReminder, PlaceOrder*, CancelOrder*, CloseBar*}  ← * 为 P1 骨架
├── Events/ {BarClosed, SignalGenerated, OrderFilled, BreakerTripped, BreakerAlert}
├── Console/Commands/            ← 39 个命令（market:feed / archive:* / backtest:* / payment:* / reconcile:* / risk:* / 5×poc:verify 等）
├── Models/                      ← 23 个 Eloquent（租户级表挂 BelongsToTenant）
└── Support/AesGcmCrypt.php     ← AES-256-GCM 封装
config/ {exchange, strategy, ai, risk, plans, notifications, tenancy, liquidation, payment, archive, backtest, optimization, reconcile, octane, reverb, filament}.php
database/migrations/            ← 36 张表
routes/ {api.php, channels.php, web.php}
```

## POC 优先级

> 实测踩坑见 [pitfalls.md](pitfalls.md)。

| 阶段 | 范围 | 验证目标 | 状态 |
|---|---|---|---|
| **P1 策略+下单闭环** | BinanceSpotAdapter + SpotGridStrategy + RiskGuard 最小版 | K线订阅→onBar→RiskGuard→placeOrder→onFill→崩溃恢复 | ✅ 全链路 PASS（2026-10-13）：行情/环境装配/真实下单成交/崩溃恢复双写幂等 |
| **P2 AI 信号闭环** | BarAggregator + DeepSeekAdapter + RuleEngineFallback + SignalDispatcher | bar close→AI 分析→Signal 落库→多渠道分发；AI 失败兜底 | ✅ 全链路 PASS（2026-10-13）：DeepSeek 真实信号 #7 落库、42/42 断言、15min 去重生效 |
| **P3 多租户计费** | tenancy 中间件 + plans + TwoPhaseMeter + 订阅状态机 + DEK 轮换 | 两租户同跑策略，数据严格隔离；配额扣减；订阅状态机 | ✅ 双验证 PASS（2026-10-14）：62/62 离线断言（含 10 进程抢 3 配额并发）+ 双租户同 WS 实跑 meter 独立 |
| **P4 高级风控** | 4 维度 Rule 全实现 + 熔断状态机 + HALF_OPEN 探测 + 自动恢复窗口 | 模拟连亏→熔断→通知→冷却→HALF_OPEN→探测单→CLOSED/重置 | ✅ 全链路 PASS（2026-10-14）：35/35 离线断言（4 维度触发/HALF_OPEN 双路径/事件分发/4 渠道通知/跨日重置/崩溃恢复/去重/命令幂等）+ risk:status/risk:recover 命令验证 |
| **P5 大户爆仓数据** | subscribeLiquidationStream + 实时聚类 + LiquidationMapService + 面板展示 | 实时显示 BTC/ETH 大户爆仓密集区 | ✅ 离线 PASS（2026-10-14）：15/15 断言（DTO/聚类/Redis ZSET+HASH/三档窗口隔离/eviction/MapService）；真实 fstream 实跑阻塞于 WSL 对 Binance 主网网络不可达，命令保留待补测 |
| **P5.5 上线前工程化加固** | 账实对账 + SpotDca 落地 + 测试迁入 PHPUnit/CI + supervisor/每日调度/海外节点 | 见 [P5.5 执行计划](p5.5-hardening-plan.md) 各子项验收标准 | ✅ 代码侧完成（2026-10-15，步骤 0–4）：PHPUnit 分组/CI 双 job/supervisor/每日调度/对账/SpotDca 全部落地；节点机实跑验收见 [Runbook](overseas-node-runbook.md) |
| **P6 多交易所 Adapter 扩展** | OKX/Bybit/Bitget 现货+合约 Adapter；统一错误码翻译表 | 三家 testnet 各跑通 1 笔市价单 + 1 路 WS 1m closed bar | ✅ 代码侧完成（2026-10-16~18，spec 001/002/003）：三家现货适配器 + 签名域统一；OKX demo/Bybit testnet/Bitget 主网小额单待节点回填 |
| **P7 Custom DSL 策略引擎** | YAML 声明式 entry/exit/filter/risk 四段 + 沙箱解释器 + Filament 编辑器 | 一条 DSL 装载后跑通 onBar→信号→下单；与 PHP 内置策略等价 | ✅ 完成（2026-10-19，spec 006）：受限 YAML + 沙箱解释器 + 编辑器/预览 + 售卖门控 |
| **P8 ClickHouse 历史归档** | kline_bars/signals/orders/liquidation_events 迁 CH；冷热分层；HistoricalQuery 抽象 | 一周历史 K 线查询 < 500ms；迁移期业务零感知 | ✅ 核心落地（2026-10-19，spec 004）：归档管线/统一查询/租户隔离/故障降级/可观测；节点机 CH 冒烟与端到端验收待回填 |
| **P9 支付网关 + 计费 UI** | Stripe + 支付宝/微信；Filament 计费页（套餐/Invoice/用量）；自动续费 | 完整一笔 下单→支付→激活→配额刷新；续费失败走 P3 past_due→suspended | ✅ 我方实现完成（2026-10-20，spec 007）：通道抽象（BTCPay 首发）+ webhook 幂等 + 激活事务 + 续费提醒 + 计费页；真实收单待节点验收 |
| **P10 回测引擎 + 绩效分析** | 历史回放（CH K 线→BarAggregator）；Sharpe/回撤/胜率；Filament 绩效页；风控参数自动调优 | DSL 回测 1 年 < 30s；与 P1 实跑净值曲线误差 < 1% | ✅ 完成（2026-10-21，spec 008）：回放驱动 + 9 指标 + 等价性 + 异步队列 + 绩效页；1 年 5m 实测 6.5–9.1s。参数寻优由 P11 承接 |
| **P11 参数寻优** | 参数网格展开（显式值 / min-max-step + 白名单）；grid 全区间选优；walk-forward 分折训练/样本外 OOS 均值；寻优与 trials 双表持久化；REST API + Filament 参数寻优页（异步 backtest 队列） | 30min 1m fixture 3 组合选优与 2 折 walk-forward 输出符合预期；全量回归 0 failed | ✅ 完成（2026-10-21）：`backtest:optimize` + `--dry-run` + `/api/v1/parameter-optimizations`；config/optimization.php 可调上限/折数/目标 |

> 逐项能力、交付日期与文档对照见 [README 功能矩阵](../README.md#功能矩阵--路线图)。

## P6/P7/P9/P10/P11 交付索引

| 阶段 | 落地要点 | 文档 |
|---|---|---|
| P6 多所现货 | 三家适配器共享 `ExchangeSigning` 签名域；错误码统一翻译 + 限频退避 | [001 OKX](../specs/001-okx-spot-adapter/spec.md) / [002 Bybit](../specs/002-bybit-spot-adapter/spec.md) / [003 Bitget](../specs/003-bitget-spot-adapter/spec.md) |
| P7 Custom DSL | 受限 YAML 四段（entry/exit/filter/risk）+ 沙箱解释器 + Filament 编辑器 + 预览（不含手续费滑点）+ 售卖门控 | [006 DSL](../specs/006-custom-dsl-strategy/spec.md) |
| P9 支付 | `PaymentGatewayInterface` 通道抽象（BTCPay 首发）+ webhook 幂等 + 激活事务 + 续费提醒 + 计费页/API | [007 支付网关](../specs/007-payment-gateway-billing/spec.md) / [BTCPay PoC](p9-btcpay-poc-plan.md) |
| P10 回测 | `BacktestRunner` 回放驱动（不依赖交易所）+ bar 级撮合 + 9 指标 + 报告持久化零重算 + 绩效页 | [008 回测引擎](../specs/008-backtest-engine/spec.md) |
| P11 参数寻优 | `ParameterGrid`（白名单 + 上限）+ `ParameterOptimizer`（grid / walk-forward OOS 均值）+ `RunOptimizationJob`（backtest 队列）+ REST API / Filament 参数寻优页 + 双表持久化 + 结果应用闭环（Top N 任选应用、应用历史/回滚、实例反查，`applyToInstance` / `rollbackApplication`） | [README 功能矩阵](../README.md#功能矩阵--路线图) |

## P8 ClickHouse 历史归档（spec 004，2026-10-19 核心落地）

**双进程模型不变**：归档是纯旁路——`archive:*` 系列为独立 artisan 命令进程（无协程、无 WS，constitution III），禁止进入 market:feed 行情协程进程；P1-P5 代码零 import 指向 `App\Domain\Archive`（T028 导入闭包 gate 终检 PASS）。

### 冷热分层

```
热层 MySQL（保留期内，默认 kline 365d / signal 90d / order 180d / liquidation 90d）
  │  archive:run 批次管线：建批 → copy（幂等锚 deleteByBatch 先清半途）→ 三级 verify（行数/行哈希和/抽样逐行）→ apply（事务内再核对+分块限速删除+水位推进）
  ▼
冷层 ClickHouse（MergeTree + batch_id 列，HTTP 直连零依赖，无 PHP 客户端库）
  ▲
  └── HistoricalQuery 统一查询：applied_watermark 三段路由（纯冷/纯热/跨界归并 id 去重）+ keyset 游标分页
```

### 关键机制

| 机制 | 落点 |
|---|---|
| 账目与水位 | `archive_batches`（pending→copied→passed/failed，applied_at 标记应用）+ `archive_watermarks`（copied/applied 双水位只增） |
| 幂等 | uniq 批次复用、failed 重跑复位 pending、空切片不建批不推进水位、apply 事务回滚无半删 |
| 安全红线 | 非 passed 拒删（verify gate）；`--apply` 才删除；分块/节流参数下限校验（exit 2） |
| 租户隔离 | tenant 类型 copy 显式绕 Scope（系统级搬迁）；查询侧强制 TenantContext 注入，热侧 TenantScope + 冷侧 fetchRows filters 两层等价（R-008） |
| 故障降级 | CH 断连统一 `ArchiveStorageUnavailableException` 携因上抛（R-009）；纯热查询零依赖 CH |
| 可观测 | `archive:schema`（建表 DDL）/ `archive:run` / `archive:verify`（独立复检+降级）/ `archive:status`（水位/待归档估算/批次清单） |

详细选型见 [ADR-0005](adr/0005-clickhouse-cold-tier.md)；契约与验收对账见 `specs/004-clickhouse-historical-archive/`。

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
