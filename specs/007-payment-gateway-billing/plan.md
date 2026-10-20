# Implementation Plan: P9 支付网关 + 计费 UI

**Branch**: `007-payment-gateway-billing` | **Date**: 2026-10-20 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/007-payment-gateway-billing/spec.md)

**Input**: Feature specification from `/specs/007-payment-gateway-billing/spec.md`

## Summary

为数字货币交易 SaaS 平台补齐**商业化最后一环**：USDT 收单（BTCPay Server 自托管，首发 TRC20 单链）+ webhook 验签幂等 + 支付确认→订阅激活接线（复用 P3 `SubscriptionService`）+ 到期前 7/3/1 天续费提醒 + Filament 计费页。

核心架构命题（三句话）：
1. **通道抽象层镜像既有 Exchange Adapter 范式**（接口 + 按 `code` 路由 + 未知 code 抛异常）：BTCPay 首发，新增通道纯增量（实现接口 + 配置一行，零改动既有通道与订阅接线代码，FR-001）。
2. **幂等锚在「平台自己的业务事件」而非通道报文形态**：支付单条件更新（`WHERE status IN ('pending','processing','partial')` + 独立一次性标志 `confirmed_at`）同时承担防重入与防并发；Redis 单飞锁仅削峰、非正确性依赖（Redis 停机时幂等仍成立）。
3. **通道 IO 拒于事务外、状态机单调、精度全程 bcmath**：`decimal(20,8)` + Eloquent `decimal:8`（读出 string），足额/不足额/超额判定收敛为一句 `bccomp`；接线只走 `SubscriptionService::provision()`，P3 状态机零改动（FR-008）。

**双轨交付**：本机（Windows，无 Docker，境外端点不可达，坑 #23）无法验证真实 BTCPay —— 故 `FakePaymentChannel` 先行完成**全部**接线/幂等/状态机/UI 断言；`BtcPayChannel` 同类交付（验签为纯函数，可离线单测），真实投递验收挂 `docs/p9-btcpay-poc-plan.md` 阶段 A/B/C，节点就绪后执行，不阻塞本期交付。

## Technical Context

**Language/Version**: PHP 8.4（项目已确立；WSL/ServBay 开发机）

**Primary Dependencies**: Laravel 13.x + Octane(Swoole) + Filament v5 既有栈。**零新增 Composer 依赖**——BTCPay 走 REST + `Http` facade（超时 10s），与既有 `App\Domain\Exchange\Adapters\AbstractExchangeAdapter::httpSigned/httpGet` 同模式。

**Storage**: MySQL 8.4（新增三表：`payment_invoices` / `payment_webhook_events` / `payment_renewal_reminders`）+ Redis 7（webhook 单飞锁；既有日配额计数键复用，不新增限额缓存）。

**Testing**: PHPUnit 12（`#[DataProvider]` 属性，constitution VI）。离线层（Fake 通道 + 验签纯函数）零外部依赖；集成层需 MySQL；`@redis` 组需 Redis（停机则 skip，不阻塞基线）；真实 BTCPay 用例以**可用性标志** skip（沿用既有 56 skipped 惯例）。测试清理按 `ref`/`tenant_id` 精确清三张新表，try/catch 包住且先于 `parent::tearDown()`。

**Target Platform**: Linux server（生产）；Windows/WSL 开发机仅跑离线 + 集成层。BTCPay Server 部署位置 = 海外节点（运营侧）。

**Project Type**: web-service（Laravel SaaS monolith，多租户；Filament Admin 面板 + REST API 双面）。

**Performance Goals**: 平台侧「确认到账 → 订阅激活 → 配额可用」≤ 30 秒（SC-001，实际为单事务毫秒级 + 1 次通道 webhook 投递）；webhook 单请求 p95 < 200ms（验签为一次 HMAC + 一次条件更新 + 一次 provision 事务）；计费页首屏（复用 P3 服务读配置 + Redis 计数）无额外重计算。（p95 为设计目标：离线环境由 `poc:verify-payment` 单请求 <200ms 冒烟断言承接；真实 p95 压测与计费页渲染基准**不设本期门禁**）

**Constraints**:
- 金融精度（constitution II / FR-011）：金额列 `decimal(20,8)`；bcmath 字符串流转；足额判定 `bccomp(paid, amount, 8) >= 0`；**浮点全程不参与**。
- 幂等与并发（FR-005 / SC-002）：DB 条件更新为正确性守卫（Redis 无关）；并发防重用 Redis 单飞锁 `set($k,$v,'EX',15,'NX')`（**phpredis 参数位顺序**，constitution VII，先例 `TenantKeyResolver.php#L97`）。
- 原子性与可重试（FR-006）：条件更新 + `provision` + `processed_key` 写入**同一事务**；通道 HTTP 与通知投递**事务外**；失败回滚 + webhook 返回 500 触发通道重投，**不存在半激活**。
- 策略单调（FR-003 / US2-3）：状态只向终态前进，以「条件更新只接受非终态」实现乱序不回退。
- 租户隔离（FR-010 / SC-006）：三表带 `tenant_id`；`payment_invoices`/`payment_renewal_reminders` 挂 `BelongsToTenant`；webhook 事件表显式写 tenant_id（回调用无上下文，与 `TwoPhaseMeter` 同思路）；Filament Resource 走 `TenantScope`，页面内查询显式 `TenantContext`。
- 假通道红线（research R-004）：`fake` 仅 `local/testing` 可用，其它环境启用即抛异常；生产 MUST 显式 `PAYMENT_CHANNEL=btcpay`（防「假通道收单」资损）。
- 配额语义不可误解（research R-003）：`plan_code` 变更即按新套餐限额生效，**MUST NOT** 新增缓存或清零当日计数（不追溯/不双计）。
- 协程边界（constitution III）：webhook 短请求路径 + 通道 HTTP 10s 超时；续费提醒走 `notifications` 队列（普通 PHP 进程）；**不进入** `market:feed` 行情协程进程。

**Scale/Scope**: 3 张新表 + 1 个 `app/Domain/Payment` 包（Contracts/Dto/Channels/Services）+ 3 个 artisan 命令（`billing:renewal-reminders` / `billing:expire-invoices` / `billing:fake-webhook`）+ 1 个 `poc:verify-payment` 冒烟 + 1 个 Job + 1 个 Payload + 1 个 Filament Page + 1 个 Resource + 1 个 Widget + 5 条 API 路由。P1–P8 既有代码除 `routes/` 注册与 `config/` 新增外**零改动**（`SubscriptionService`/`QuotaGuard`/`PlanService`/通知域全部只读复用）。出范围：多链收单、年付/折扣/税额、自动退款、通道主动查单与对账报表、Stripe 并行线（spec Assumptions）。

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | Plan Compliance | Status |
|---|-----------|------------------|--------|
| I | SDD 优先 | 走完整五阶段：specify ✓ → clarify（Q1/Q2 超时按推荐默认收敛，推翻入口见 research R-010）→ plan 当前 → tasks → analyze → implement | ✓ PASS |
| II | 金融精度与时间基准 | 金额列 `decimal(20,8)` + Eloquent `decimal:8`（string 读出）；足额/差额/超额判定一律 `bccomp`/`bcsub`；测试断言字符串逐位相等；支付单 `expires_at`/`confirmed_at`/`received_at`/提醒判定全 UTC | ✓ PASS |
| III | 协程隔离与连接边界 | webhook = Octane HTTP 短请求（验签 HMAC + 单事务，无慢 IO）；通道 HTTP timeout 10s 且不进行情协程；提醒发送走 `notifications` 队列（`RenewalReminderJob`，镜像 `RiskNotificationJob` 的普通 PHP 进程范式） | ✓ PASS |
| IV | 状态持久化与崩溃恢复幂等 | 幂等守卫 = 支付单条件更新 + 独立一次性列 `confirmed_at`（**不复用**会被正常流程改写的业务字段）；事件表 `processed_key` 可空唯一列提供「processed 行 ≤1」可断言证据；提醒幂等 = `(subscription_id, ends_at, days_before, channel)` 唯一索引（续费顺延即新周期）；重放 3 次断言进 quickstart §4 | ✓ PASS |
| V | 计费语义与风控边界 | 未知 `channel_code` / 未知 `plan_code` **抛异常不降级**（防提权）；激活后新动作仍走 `QuotaGuard::assertEntitled`（不绕过）；`log` 系统渠道恒放行（`PlanService::SYSTEM_CHANNELS`）；Fake 通道生产禁用（同源的防降级红线）；退款不自动化（FR-014，人工边界） | ✓ PASS |
| VI | 测试隔离与完整路径 | `@redis` 组守卫（`ChecksRedisAvailability`）；Feature 用 `RefreshDatabase`；tearDown 按 `ref`/`tenant_id` 精确清三张新表且 try/catch 先于 `parent::tearDown()`；持久化字段走 Domain 层公开方法（`PaymentInvoiceService`/`PaymentActivationService`）不直改内部状态；`#[DataProvider]` 属性 | ✓ PASS |
| VII | PSR-4 与框架基类对齐 | 一文件一类（DTO/enum 全部独立文件：`PaymentInvoiceRequest`/`PaymentInvoiceData`/`WebhookEvent`/`PaymentStatus`）；Redis facade 用 phpredis 签名 `set($k,$v,'EX',$ttl,'NX')`（先例两处已核）；`PaymentChannelRegistry` 绑定 bind 非 singleton（Octane 长驻禁 static 缓存，沿用 `AppServiceProvider.php#L39-L52`）；不覆写框架基类属性 | ✓ PASS |

**Gate Result**: 全部 7 项 PASS，**无违反**，Complexity Tracking 为空。Phase 0 research 完成（R-001~R-011 全部 Resolved：通道形态 / 表结构 / 事务边界 / 双轨交付 / 验签与防重 / 幂等键形态 / 提醒调度 / 计费页隔离 / 精度口径 / Q1-Q2 回填 / 测试与不可达依赖）；Phase 1 设计产物（data-model / contracts×3 / quickstart）已生成，复检结论不变。

## Project Structure

### Documentation (this feature)

```text
specs/007-payment-gateway-billing/
├── spec.md              # specify✓（4 US / 14 FR / 6 SC / Q1-Q2 已收敛）
├── plan.md              # 本文件
├── research.md          # Phase 0 产出（R-001~R-011 决策全部 Resolved）
├── data-model.md        # Phase 1 产出（3 新表 + 状态机 + 配置 + 隔离口径 + 双轨）
├── quickstart.md        # Phase 1 产出（离线/集成/真实三层验证指南 + SC 对照表）
├── contracts/
│   ├── payment-channel-contract.md      # 通道接口 + DTO + 注册表 + Fake/BtcPay 行为
│   ├── webhook-contract.md              # 端点/验签/幂等/状态推进/响应码/断言口径
│   └── billing-integration-contract.md  # 支付单服务/接线/提醒/计费页/API/命令/配置
├── checklists/
│   └── requirements.md  # specify 阶段产出（全过）
└── tasks.md             # tasks 阶段产出（非 plan 创建）
```

### Source Code (repository root)

```text
app/Domain/Payment/                      # 唯一新增 Domain 包（P1–P8 零 import）
├── Contracts/
│   ├── PaymentChannel.php               # 通道抽象（code/createInvoice/verifyWebhook/parseWebhook）
│   └── PaymentChannelRegistry.php       # 按 code 路由 + 未知 code 抛异常
├── Dto/
│   ├── PaymentInvoiceRequest.php
│   ├── PaymentInvoiceData.php
│   ├── WebhookEvent.php
│   └── PaymentStatus.php                # enum（终态判定 isTerminal）
├── Channels/
│   ├── FakePaymentChannel.php           # 离线轨道（local/testing 限定）
│   └── BtcPayChannel.php                # 真实轨道（HMAC 验签 + REST 建单）
├── PaymentChannelManager.php            # Registry 实现（bind 非 singleton）
├── PaymentInvoiceService.php            # 建单/复用/过期清扫/列表
├── PaymentActivationService.php         # 支付确认 → 订阅接线（唯一入口，事务内）
├── RenewalReminderService.php           # 7/3/1 提醒判定 + 落库 + 投递
└── Exceptions/PaymentChannelException.php

app/Http/Controllers/Api/V1/BillingController.php     # summary / invoices / show
app/Http/Controllers/Api/V1/BillingWebhookController.php  # 无 auth/tenant 中间件，验签即鉴权

app/Filament/Admin/Pages/Billing.php                  # 计费页（套餐/用量/升级 Action）
app/Filament/Admin/Resources/PaymentInvoiceResource.php
app/Filament/Admin/Resources/PaymentInvoiceResource/Pages/ListPaymentInvoices.php
app/Filament/Admin/Widgets/QuotaUsageWidget.php

app/Console/Commands/BillingRenewalRemindersCommand.php   # billing:renewal-reminders
app/Console/Commands/BillingExpireInvoicesCommand.php     # billing:expire-invoices
app/Console/Commands/BillingFakeWebhookCommand.php        # billing:fake-webhook（dev-only）
app/Console/Commands/PocVerifyPaymentCommand.php           # poc:verify-payment

app/Jobs/RenewalReminderJob.php
app/Domain/Notification/Payloads/RenewalReminderPayload.php
app/Models/PaymentInvoice.php
app/Models/PaymentWebhookEvent.php
app/Models/PaymentRenewalReminder.php

database/migrations/*_create_payment_invoices_table.php
database/migrations/*_create_payment_webhook_events_table.php
database/migrations/*_create_payment_renewal_reminders_table.php

config/payment.php                       # 新增；config/{plans,notifications}.php 零改动
routes/api.php                           # 追加 billing 分组 + webhook 独立注册
routes/console.php                       # 追加 billing:renewal-reminders 调度（00:10 UTC）
```

**Structure Decision**：沿用既有单仓 Laravel 分层（`app/Domain/{域}/` + `app/Http/Controllers/Api/V1/` + `app/Filament/{Admin,Platform}/` + `app/Console/Commands/`），支付域作为**独立 Domain 包**纯增量接入，与 Exchange/Billing/Notification 域的耦合面仅限契约调用（`SubscriptionService` / `PlanService` / `NotifierChannel`），便于 analyze 阶段核对 import 闭包。

## 1. 工作流拆解

按依赖关系分 7 个工作流（US 映射），建议 WS1→WS4 顺序、WS5 与 WS6 并行、WS7 收口：

```
WS1 数据层 + 配置（FR-011/012 基础）
  └─ WS2 通道抽象层 + Fake 通道 + BtcPay 类（FR-001/004）
       └─ WS3 支付单服务（FR-002/012/013）
            └─ WS4 webhook 验签 + 幂等 + 状态机 + 订阅接线（FR-003/004/005/006）★核心
                 ├─ WS5 续费提醒 + 到期流转接线（FR-007/008，US3）
                 └─ WS6 Filament 计费页 + API 通路 + 隔离（FR-009/010/014，US4）
                      └─ WS7 冒烟命令 + 全链路断言 + 文档同步（SC-001~006）
```

依赖说明：WS5/WS6 都只依赖 WS4 的稳定服务面（`PaymentInvoiceService` / `ActivationService`），可并行；WS1 的三张表是其余全部的前置。

**节点依赖轨道（非阻塞，独立于上述 7 个 WS）**：`docs/p9-btcpay-poc-plan.md` 阶段 A/B/C —— 真实 BTCPay 部署 + 真实验签投递 + 重放幂等 + 状态机时间线。

## 2. 各工作流范围

### WS1: 数据层 + 配置

| 产出 | 文件 |
|---|---|
| migration：`payment_invoices`（`ref` unique、`decimal(20,8)`、状态 enum、复合索引） | `database/migrations/*_create_payment_invoices_table.php` |
| migration：`payment_webhook_events`（`processed_key` 可空 unique、`raw_payload` json） | `database/migrations/*_create_payment_webhook_events_table.php` |
| migration：`payment_renewal_reminders`（周期唯一索引） | `database/migrations/*_create_payment_renewal_reminders_table.php` |
| 模型：`PaymentInvoice`（`BelongsToTenant` + `decimal:8` cast）、`PaymentWebhookEvent`（不用 trait）、`PaymentRenewalReminder` | `app/Models/*.php` |
| 配置 `config/payment.php`（通道 driver 映射、TTL、提醒档位与渠道） | `config/payment.php` |
| `.env.example` 追加 `PAYMENT_CHANNEL` / `PAYMENT_INVOICE_TTL_MINUTES` / `BTCPAY_*` | `.env.example` |

**验收**：`php artisan migrate` 成功；`config('payment.channels')` 可读；`bccomp` 读出的金额为 string；`unique(processed_key)` 允许多 NULL（插入两行 NULL 成功、两行同值失败）。

### WS2: 通道抽象层 + Fake 通道 + BtcPay 类

| 产出 | 文件 |
|---|---|
| `PaymentChannel` / `PaymentChannelRegistry` 接口 | `app/Domain/Payment/Contracts/*.php` |
| DTO：`PaymentInvoiceRequest` / `PaymentInvoiceData` / `WebhookEvent` / `PaymentStatus` enum | `app/Domain/Payment/Dto/*.php` |
| `PaymentChannelManager`（读 config driver、未知 code 抛异常、bind 非 singleton） | `app/Domain/Payment/PaymentChannelManager.php` |
| `FakePaymentChannel`（确定性假地址/假发票号 + HMAC 验签 + 固定事件解析；**非 local/testing 抛异常**） | `app/Domain/Payment/Channels/FakePaymentChannel.php` |
| `BtcPayChannel`（`Http` 建单写 metadata + `BTCPay-Sig` HMAC-SHA256 常量时间验签 + 事件解析） | `app/Domain/Payment/Channels/BtcPayChannel.php` |
| `PaymentChannelException` | `app/Domain/Payment/Exceptions/PaymentChannelException.php` |
| 容器绑定（bind 非 singleton，附注释同 `AppServiceProvider` L39-52 惯例） | `app/Providers/AppServiceProvider.php` |

**验收**：
- Unit：`WebhookSignatureTest` —— BTCPay 验签正例 + 篡改 body/错密钥/缺头/前缀非法 4 类负例，**100% 判定正确且无需联网**。
- Unit：`PaymentChannelConfigTest` —— 未知 code 抛异常、driver 缺配置抛异常、fake 在 `production` 抛异常。
- 契约面：`parseWebhook` 只解析不写库（用纯函数断言）。

### WS3: 支付单服务

| 产出 | 文件 |
|---|---|
| `PaymentInvoiceService`（create / pendingFor / listForTenant / expireStale） | `app/Domain/Payment/PaymentInvoiceService.php` |
| 过期清扫命令 | `app/Console/Commands/BillingExpireInvoicesCommand.php` |
| dev 伪造投递命令（构造带签名载荷 → 投递本平台端点） | `app/Console/Commands/BillingFakeWebhookCommand.php` |

**核心逻辑**：
- `ref = 'pi_'.Str::ulid()`；金额取 `price_monthly` → 8 位字符串；未知 `plan_code` 抛异常。
- 同租户 + 同套餐存在非终态单 → **原样返回既有单**（US4-3，不堆叠）。
- 建单序：落 `pending` 行 → 调通道 `createInvoice()` → 回填通道字段；通道抛异常 → **删除刚落的行**（无半状态单，spec Edge）。
- 金额复核：通道返回金额 ≠ 应付 → 拒建单（`bccomp` 比较）。

**验收**：Unit `PaymentInvoiceServiceTest` —— 唯一性/复用/过期/TTL 边界/金额字符串逐位相等/通道失败无残留行。

### WS4: webhook 验签 + 幂等 + 状态机 + 订阅接线 ★

| 产出 | 文件 |
|---|---|
| `PaymentActivationService`（条件更新 + `provision` + `processed_key` 同事务） | `app/Domain/Payment/PaymentActivationService.php` |
| `BillingWebhookController`（流水线 ①~⑦，见 webhook-contract §2） | `app/Http/Controllers/Api/V1/BillingWebhookController.php` |
| webhook 路由（**分组外**，不挂 `auth:sanctum` / `tenant`） | `routes/api.php` |
| CSRF 豁免核对（api 路由天然豁免 / `bootstrap/app.php` 显式） | `bootstrap/app.php`（如需） |

**关键约束**：
- 验签对**原始字节**做（`hash_equals` 常量时间）；失败 401 + 审计 + 零状态变更。
- 幂等守卫 = 支付单条件更新（**先更新后接线**）；`affected=0` ⇒ `idempotent_skipped`。
- 事务内仅 DB 写入；通道 IO / 通知投递事务外。
- 失败 → 回滚 → 500（触发通道重投）→ 重入成功，无半激活。
- 单飞锁 `set($k,$v,'EX',15,'NX')`（拿不到 → 200 + skipped）。

**验收**：
- Integration `FakeChannelCheckoutFlowTest`（US1 全链路 → 订阅 active + 配额按新套餐放行）
- Integration `WebhookIdempotencyTest`（重放 3 次：`subscriptions` +1 行、`processed_key` 计数=1、配额零增量；SC-002）
- Integration `WebhookSignatureRejectionTest`（SC-003）
- Integration `WebhookOrderingAndUnknownRefTest`（乱序不回退；unknown ref → rejected；PoC B3）
- Integration(@redis) `WebhookConcurrencyTest`（并发同 ref 只处理一次）
- Unit `PaymentStatusMachineTest`（迁移表逐行 + 终态拒绝 + 过期到账 → manual）

### WS5: 续费提醒 + 到期流转接线（US3）

| 产出 | 文件 |
|---|---|
| `RenewalReminderService`（7/3/1 判定 + 唯一索引幂等 + 投递） | `app/Domain/Payment/RenewalReminderService.php` |
| `RenewalReminderPayload`（含一键续费链接） | `app/Domain/Notification/Payloads/RenewalReminderPayload.php` |
| `RenewalReminderJob`（镜像 `RiskNotificationJob` 三段式，`notifications` 队列） | `app/Jobs/RenewalReminderJob.php` |
| 提醒命令 + 调度（00:10 UTC，`withoutOverlapping(10)`） | `app/Console/Commands/BillingRenewalRemindersCommand.php` + `routes/console.php` |

**核心逻辑**：扫描最新订阅（`status ∈ active,trialing` 且 `ends_at` 未来）→ `days_left ∈ {7,3,1}` → 落 `queued` 行（唯一索引幂等）→ 按套餐渠道白名单投递。**不改** P3 状态机（`billing:refresh-status` 零改动承担 past_due→grace→suspended）。

**验收**：
- Unit `RenewalReminderServiceTest`（档位命中/不命中、同周期重复调用零新增行、续费顺延后新周期可再发）
- Integration `RenewalRecoveryTimelineTest`（提醒 → past_due → 宽限 → suspended → 补缴恢复 + 周期顺延；SC-004）
- 调度注册断言（扩展既有 `tests/Integration/Console/ScheduleRegistrationTest.php`）

### WS6: Filament 计费页 + API 通路 + 隔离（US4）

| 产出 | 文件 |
|---|---|
| 计费页（套餐/周期/用量 + 升级 Action + 待支付单引导） | `app/Filament/Admin/Pages/Billing.php` |
| 用量看板 widget（复用 P3 服务，不另算口径） | `app/Filament/Admin/Widgets/QuotaUsageWidget.php` |
| 支付单只读 Resource + List 页 | `app/Filament/Admin/Resources/PaymentInvoiceResource.php`（+ Pages） |
| API：summary / invoices / show | `app/Http/Controllers/Api/V1/BillingController.php` + `routes/api.php` |

**关键约束**：产物只读（禁 create/edit/delete）；隔离走 `BelongsToTenant`+`TenantScope`（Resource）与 `TenantContext`（Page）；跨租户 → 404 不泄露存在性；SC-005 的 3 步约束（首屏升级按钮 → 选套餐 → 收款信息）。

**验收**：Feature `BillingPageTest`（渲染 + 用量数字与 `TwoPhaseMeter` 一致 + 双租户零串扰 + 只读）；Feature `BillingApiTest`（跨租户 404 + 参数校验）。

### WS7: 冒烟命令 + 全链路断言 + 文档同步

| 产出 | 文件 |
|---|---|
| `poc:verify-payment`（离线全链路断言，风格对齐 `PocVerifyDslCommand`） | `app/Console/Commands/PocVerifyPaymentCommand.php` |
| README P9 行状态更新 + 技术债索引销账 | `README.md` |
| spec 005/006 无关联；PoC 方案归档标注 | `docs/p9-btcpay-poc-plan.md` |

**验收**：`php artisan poc:verify-payment` 输出 `ALL CHECKS PASSED`（覆盖 SC-001~006 平台侧）；README 与 quickstart 状态一致。

## 3. 测试矩阵

| 层 | 文件 | 分组 | 覆盖 FR/SC |
|---|---|---|---|
| Unit | `PaymentInvoiceServiceTest` | default | FR-002/011/012 |
| Unit | `PaymentStatusMachineTest` | default | FR-003/012/013 |
| Unit | `PaymentChannelConfigTest` | default | FR-001 |
| Unit | `WebhookSignatureTest` | default | FR-004 |
| Unit | `PaymentActivationServiceTest` | default | FR-006 |
| Unit | `RenewalReminderServiceTest` | default | FR-007 |
| Integration | `FakeChannelCheckoutFlowTest` | default | FR-002/006（SC-001） |
| Integration | `WebhookIdempotencyTest` | default | FR-005（SC-002） |
| Integration | `WebhookSignatureRejectionTest` | default | FR-004（SC-003） |
| Integration | `WebhookOrderingAndUnknownRefTest` | default | FR-003/004 |
| Integration | `RenewalRecoveryTimelineTest` | default | FR-008（SC-004） |
| Integration | `WebhookConcurrencyTest` | redis | FR-005 |
| Integration | `BtcPayChannelLiveTest`（可用性标志 skip） | default | FR-001/004 |
| Feature | `BillingPageTest` | default | FR-009/010（SC-005/006） |
| Feature | `BillingApiTest` | default | FR-009/010（SC-006） |
| Feature | `BillingTenantIsolationTest` | default | FR-010（SC-006） |
| Feature | `ScheduleRegistrationTest`（扩展既有） | default | FR-007/008 |
| Smoke | `poc:verify-payment` | smoke | SC-001~006 |

> constitution VI：PHPUnit 12 静态 `#[DataProvider]`；Redis 用例 `@group redis` 且带 `ChecksRedisAvailability` 守卫；Feature 用 `RefreshDatabase`；持久化字段走 `PaymentInvoiceService`/`PaymentActivationService` 公开方法；tearDown 按 `ref`/`tenant_id` 精确清三张新表，外部 IO 清理先于 `parent::tearDown()`。

## 4. 关键风险与缓解

| 风险 | 缓解 |
|---|---|
| 通道契约靠「想象」定形，真机接入时不敷用 | 契约只保留建单/验签/解析三件必需动作；主动查单登记为**显式扩展点**而非先造无用接口（research R-001） |
| BTCPay 报文形态与假设不符 | `parseWebhook` 纯函数单测 + 真实报文入 `payment_webhook_events.raw_payload` 可回溯调整；节点就绪后 B1 重跑 |
| 幂等「看起来对但证不明」 | `processed_key` 可空唯一列使断言可写成计数 =1；重放 3 次断言固化进 quickstart §4（research R-006） |
| 重复投递 + 并发同时到达 | DB 条件更新为正确性守卫（Redis 无关）；Redis 单飞锁仅削峰；@redis 并发用例断言（FR-005） |
| 半激活（订阅已激活但支付单未确认） | 条件更新 + `provision` + `processed_key` **同事务**；通道 IO 与通知拒于事务外；失败 500 触发重投（research R-003） |
| 续费提醒重复/漏发 | 唯一索引锚 `(subscription_id, ends_at, days_before, channel)`；续费顺延即新周期（constitution IV） |
| 假通道误入生产（资损） | `fake` 仅 `local/testing` 可用 + 非此环境抛异常；生产须显式 `PAYMENT_CHANNEL=btcpay`（research R-004） |
| 配额「刷新」被误解为清零 | R-003 固化口径：限额实时读取，无需动作、不追溯、不双计；实现期红线写入契约 |
| 真实链路无节点（本机不可达，坑 #23） | 双轨交付：离线轨道完成全部断言；真实用例可用性标志 skip；验收统一挂 PoC 方案阶段 A/B/C |
| 通知渠道/账号未配置导致提醒静默失败 | 沿用 `NotifierChannel` 「失败即抛、落 failed」范式 + Job 重试 backoff `[10,30,90]`（复用 `config('notifications.retry')`） |
| Filament 页面绕过隔离 | Resource 走 `TenantScope`；Page 内查询显式 `TenantContext`；`BillingTenantIsolationTest` 双租户断言（SC-006） |

## 5. 提交节奏

按工作流粒度提交，每提交含：实现 + 测试 + 文档同步（README / 契约若实现期有偏差则同提交修正）。

1. `feat(spec-007): WS1 支付数据层 + 配置`
2. `feat(spec-007): WS2 通道抽象层 + Fake 通道 + BtcPay 类`
3. `feat(spec-007): WS3 支付单服务 + 过期清扫`
4. `feat(spec-007): WS4 webhook 验签 + 幂等 + 订阅接线`
5. `feat(spec-007): WS5 续费提醒 + 到期流转接线`
6. `feat(spec-007): WS6 Filament 计费页 + API 通路 + 隔离`
7. `feat(spec-007): WS7 冒烟命令 + 全链路断言 + 文档同步`

## 6. 节点就绪后的验收（非本期交付门禁）

按 `docs/p9-btcpay-poc-plan.md` 阶段 A/B/C 执行：A1/A2 部署与收单冒烟 → B1 真实验签投递 → B2 重放 3 次幂等 → B3 metadata 伪造拒收 → C1/C2 状态机时间线。全部 PASS 后：README P9 注记追加「BTCPay PoC 已验证（日期）」、PoC 文件归档为 implementation context；同时与 runbook 第 8 节的 11 项境外节点验收一并销账。

## Complexity Tracking

> 无 Constitution Check 违反项，本表为空。
