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
│   └── Market/                  # 行情数据 Store
├── Http/                        # 中间件 + Controllers + Resources
├── Filament/{Admin,Platform}/   # Filament 两套面板
├── Jobs/                        # Queue Job（orders/ai/notifications/aggregation）
├── Events/                      # 领域事件
├── Console/Commands/            # 6 个自定义 Artisan 命令
├── Models/                      # Eloquent（租户级挂 BelongsToTenant）
└── Support/AesGcmCrypt.php     # AES-256-GCM 封装
config/                          # 7 个自定义 config
database/migrations/            # 18 张表
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

### 🚧 待实现：POC 路线图

| 阶段 | 范围 | 验证目标 | 关键风险 |
|---|---|---|---|
| ~~**P2 AI 信号闭环**~~ ✅ | BarAggregator + DeepSeekAdapter + RuleEngineFallback + SignalDispatcher（log 实测 / Telegram 待 token） | bar close → AI 分析 → Signal 落库 → 多渠道分发；AI 失败兜底 | 已验证：见上节 P2 实测结果 |
| **P3 多租户计费** | tenancy 中间件 + plans + TwoPhaseMeter + 订阅状态机 | 两租户同跑策略，数据严格隔离；配额扣减 | 配额检查与下单的并发竞争（预冻结）；DEK 轮换 |
| **P4 高级风控** | 4 维度 Rule 全实现 + HALF_OPEN 探测 + 自动恢复窗口 | 模拟连亏 → 熔断 → 通知 → HALF_OPEN → CLOSED/重置 | 回撤计算实时性；HALF_OPEN 并发安全 |
| **P5 大户爆仓数据** | subscribeLiquidationStream + 实时聚类 + LiquidationMapService + 面板展示 | 实时显示 BTC/ETH 大户爆仓密集区 | 强平流并发写入压力；历史数据缺失 |
| **P6 扩展（可选）** | OKX/Bybit/Bitget Adapter + Custom DSL + ClickHouse | Adapter 抽象健壮性；DSL 引擎；分析层扩展 | — |

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
| [踩坑记录](docs/pitfalls.md) | P1 POC 实测坑（Swoole/Guzzle/协程/PSR-4）持续追加 |

## 安全说明

- `.env` 包含 EXCHANGE_MASTER_KEY 和数据库密码，**绝不要提交到仓库**
- API Secret 明文仅创建时返回一次，加密存 exchange_accounts
- KEK（.env）丢失则所有加密的 DEK 无法解密，需重新创建租户
- 生产环境建议启用 HTTPS（Filament 默认需要）

## License

MIT
