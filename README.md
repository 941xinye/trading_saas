# Trading SaaS Platform

币圈交易 SaaS 平台 —— 多租户策略交易 + AI 信号 + 实时风控 + 熔断保护。

## 快速链接

- [架构方案（完整）](.trae/documents/币圈交易SaaS平台架构方案.md)
- [架构总览](docs/architecture.md)
- [ADR 记录](docs/adr/)
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

### 🚧 待实现：POC 路线图

| 阶段 | 范围 | 验证目标 | 关键风险 |
|---|---|---|---|
| **P1 策略+下单闭环** | BinanceSpotAdapter（REST+WS）+ SpotGridStrategy + RiskGuard 最小版 + StrategyRunner 协程调度 | K线订阅 → onBar → RiskGuard → placeOrder → onFill → 崩溃恢复 | 币安签名+HMAC；WS 断线重连；订单幂等 |
| **P2 AI 信号闭环** | BarAggregator 实现 + DeepSeekAdapter + RuleEngineFallback + SignalDispatcher（Telegram+WS） | bar close → AI 分析 → Signal 落库 → 多渠道分发；AI 失败兜底 | AI 延迟（30s+）信号过期（价差校验）；LLM JSON schema 容错 |
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

## 安全说明

- `.env` 包含 EXCHANGE_MASTER_KEY 和数据库密码，**绝不要提交到仓库**
- API Secret 明文仅创建时返回一次，加密存 exchange_accounts
- KEK（.env）丢失则所有加密的 DEK 无法解密，需重新创建租户
- 生产环境建议启用 HTTPS（Filament 默认需要）

## License

MIT
