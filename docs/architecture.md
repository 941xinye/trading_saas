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
| 行情存储 | 实时 tick → Redis Sorted Set / 历史 K线 → MySQL（当前）→ ClickHouse（P8 规划，尚未引入） | 写入频率分层：ms 级用 Redis，历史归档用列存 |
| WS 推送 | Laravel Reverb（用户推送）+ 独立 Swoole 协程（交易所行情） | Reverb 复用广播认证体系；交易所行情不入 Reverb |

## 目录结构

```
app/
├── Domain/                     ← 领域模块（核心）
│   ├── Tenancy/                ← 多租户基础设施
│   ├── Exchange/               ← 交易所适配器
│   ├── Strategy/                ← 策略引擎（SpotGrid 已实跑；SpotDca/合约 4 策略为骨架；Custom DSL 占位）
│   ├── Signal/                  ← AI 信号订阅与分发
│   ├── Risk/                    ← 熔断与风控（四维度）
│   ├── Billing/                 ← 订阅计费（两阶段计量）
│   ├── Notification/            ← 信号通知（Log/Telegram 渠道；企微/邮件仅风控占位）
│   └── Liquidation/             ← 大户爆仓聚类（Clusterer/MapService/FeedRunner）
├── Http/
│   ├── Middleware/BootstrapTenancy.php
│   ├── Controllers/Api/V1/
│   └── Resources/
├── Filament/{Admin,Platform}/   ← 两套 Filament 面板
├── Jobs/ {PlaceOrder, CancelOrder, CloseBar, AiAnalyze, DispatchNotification, RiskNotification}
├── Events/ {BarClosed, SignalGenerated, OrderFilled, BreakerTripped, BreakerAlert}
├── Console/Commands/            ← 18 个命令（market:feed / 5×poc:verify / 5×poc 装配辅助 / risk:status+recover 等）
├── Models/                      ← 18 个 Eloquent（租户级表挂 BelongsToTenant）
└── Support/AesGcmCrypt.php     ← AES-256-GCM 封装
config/ {exchange, strategy, ai, risk, plans, notifications, tenancy, liquidation, octane, reverb, filament}.php
database/migrations/            ← 21 张表
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
| **P5.5 上线前工程化加固** | 账实对账 + SpotDca 落地 + 测试迁入 PHPUnit/CI + supervisor/每日调度/海外节点 | 见 [P5.5 执行计划](p5.5-hardening-plan.md) 各子项验收标准 | 🚧 进行中：步骤 0（测试基建：分组/12 项样板断言/Actions 双 job）已于 2026-10-15 完成；步骤 1–4 待实现 |
| **P6 多交易所 Adapter 扩展** | OKX/Bybit/Bitget 现货+合约 Adapter；统一错误码翻译表 | 三家 testnet 各跑通 1 笔市价单 + 1 路 WS 1m closed bar | 待实现（依赖 P1） |
| **P7 Custom DSL 策略引擎** | YAML 声明式 entry/exit/filter/risk 四段 + 沙箱解释器 + Filament 编辑器 | 一条 DSL 装载后跑通 onBar→信号→下单；与 PHP 内置策略等价 | 待实现（依赖 P1, P2） |
| **P8 ClickHouse 历史归档** | kline_bars/signals/orders/liquidation_events 迁 CH；冷热分层；HistoricalQuery 抽象 | 一周历史 K 线查询 < 500ms；迁移期业务零感知 | ✅ 核心落地（2026-10-19，spec 004）：归档管线/统一查询/租户隔离/故障降级/可观测 383 tests 全绿；节点机 CH 冒烟与端到端验收待回填 |
| **P9 支付网关 + 计费 UI** | Stripe + 支付宝/微信；Filament 计费页（套餐/Invoice/用量）；自动续费 | 完整一笔 下单→支付→激活→配额刷新；续费失败走 P3 past_due→suspended | 待实现（依赖 P3） |
| **P10 回测引擎 + 绩效分析** | 历史回放（CH K 线→BarAggregator）；Sharpe/回撤/胜率；Filament 绩效页；风控参数自动调优 | DSL 回测 1 年 < 30s；与 P1 实跑净值曲线误差 < 1% | 待实现（依赖 P1, P7, P8） |

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
