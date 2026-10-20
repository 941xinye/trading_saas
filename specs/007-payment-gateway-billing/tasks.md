# Tasks: P9 支付网关 + 计费 UI

**Input**: Design documents from `/specs/007-payment-gateway-billing/`

**Prerequisites**: [plan.md](file:///e:/Work/数字货币交易Saas平台/specs/007-payment-gateway-billing/plan.md)（required）、[spec.md](file:///e:/Work/数字货币交易Saas平台/specs/007-payment-gateway-billing/spec.md)（required）、[research.md](file:///e:/Work/数字货币交易Saas平台/specs/007-payment-gateway-billing/research.md)、[data-model.md](file:///e:/Work/数字货币交易Saas平台/specs/007-payment-gateway-billing/data-model.md)、[contracts/](file:///e:/Work/数字货币交易Saas平台/specs/007-payment-gateway-billing/contracts)、[quickstart.md](file:///e:/Work/数字货币交易Saas平台/specs/007-payment-gateway-billing/quickstart.md)

**Tests**: **包含**——constitution VI（测试隔离与完整路径）与 plan.md §3 测试矩阵明确要求 Unit/Integration/Feature/Smoke 四层；本任务清单据此生成测试任务。

**Organization**: 按 User Story 分组，每个故事可独立实现与独立验证。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: 可并行（不同文件、无未完成依赖）
- **[Story]**: 所属用户故事（US1 / US2 / US3 / US4）
- 描述中均含精确文件路径

## Path Conventions

- 单仓 Laravel（web-service）：`app/`、`tests/`、`config/`、`routes/`、`database/migrations/` 均在仓库根
- 新增域包：`app/Domain/Payment/`
- 测试按域分子目录：`tests/{Unit,Integration,Feature,Smoke}/Payment/`（沿用既有 `tests/{Unit,Integration}/<Domain>/` 惯例）

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: 前置校验与目录骨架（本项目为既有 Laravel 单仓，无工程初始化动作）

- [x] T001 校验前置并确认零新增依赖：运行 `php -v` / `composer -V`；按 AGENTS.md 工作区规则安装 Laravel Boost（`composer require laravel/boost --dev` + `php artisan boost:install`，**--dev 开发工具依赖**，不违反 plan「功能代码零新增 Composer 依赖」口径），安装后重读定制版 AGENTS.md 再继续；确认功能代码不新增任何依赖（BTCPay 走 `Http` facade，见 plan.md Technical Context）
- [x] T002 [P] 创建域包目录骨架 `app/Domain/Payment/{Contracts,Dto,Channels,Exceptions}/`
- [x] T003 [P] 创建测试目录骨架 `tests/Unit/Payment/`、`tests/Integration/Payment/`、`tests/Feature/Payment/`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 数据层 + 配置 + 通道抽象层（plan.md WS1 + WS2）。**四个用户故事全部依赖本阶段**。

**⚠️ CRITICAL**: 本阶段完成前，任何用户故事不得开工。

### 数据层 + 配置（WS1）

- [x] T004 新建 migration `database/migrations/*_create_payment_invoices_table.php`：`tenant_id`（FK `tenants.id` cascadeOnDelete）、`ref` string(64) **unique**、`channel_code` string(30)、`channel_invoice_id` string(120) nullable、`plan_code` string(30)、`currency` string(10) default `USDT`、`chain` string(10) default `TRC20`、`amount_usdt` **decimal(20,8) NOT NULL**、`paid_amount_usdt` decimal(20,8) nullable、`pay_address` string(120) nullable、`pay_uri` string(255) nullable、`status` enum `pending,processing,partial,confirmed,expired,manual` default `pending`、`expires_at` timestamp NOT NULL、`confirmed_at` timestamp nullable、`meta` json nullable；索引 `index(tenant_id,status)` / `index(tenant_id,plan_code,status)` / `index(channel_invoice_id)` / `index(status,expires_at)`
- [x] T005 [P] 新建 migration `database/migrations/*_create_payment_webhook_events_table.php`：`tenant_id` nullable、`payment_invoice_id` nullable（FK nullOnDelete）、`channel_code` string(30)、`ref` string(64) nullable、`event_type` string(60)、`signature_valid` boolean、`status` enum `received,processed,idempotent_skipped,rejected,error` default `received`、`processed_key` string(120) **nullable unique**（上界 = ref ≤29 + `:` + event_type ≤60，留余量）、`raw_payload` json、`error` text nullable、`received_at` timestamp、`processed_at` timestamp nullable；索引 `index(ref,created_at)` / `index(channel_code,status,created_at)`
- [x] T006 [P] 新建 migration `database/migrations/*_create_payment_renewal_reminders_table.php`：`tenant_id`（FK cascadeOnDelete）、`subscription_id`（FK `subscriptions.id` cascadeOnDelete）、`subscription_ends_at` timestamp、`days_before` smallint、`plan_code` string(30)、`channel` string(20)、`status` enum `queued,sent,failed` default `queued`、`provider_message_id` string(100) nullable、`error` text nullable、`sent_at` timestamp nullable；**唯一索引**写法 `$table->unique(['subscription_id','subscription_ends_at','days_before','channel'], 'idx_prr_cycle_reminder')`（列用数组、名单独传参，**禁止** 5 个位置参数的写法）
- [x] T007 [P] 新建模型 `app/Models/PaymentInvoice.php`：挂 `BelongsToTenant`；`$casts = ['amount_usdt' => 'decimal:8', 'paid_amount_usdt' => 'decimal:8', 'meta' => 'array', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime']`；**禁止** float cast
- [x] T008 [P] 新建模型 `app/Models/PaymentWebhookEvent.php`：**不挂** `BelongsToTenant`（webhook 无租户上下文，显式写 `tenant_id`，与 `TwoPhaseMeter` 同思路）
- [x] T009 [P] 新建模型 `app/Models/PaymentRenewalReminder.php`：挂 `BelongsToTenant`
- [x] T010 [P] 新建 `config/payment.php`：`default_channel`（`env('PAYMENT_CHANNEL','fake')`）、`invoice_ttl_minutes`、`renewal_reminder_days=[7,3,1]`、`reminder_channels`、`channels.fake{driver,allowed_env:['local','testing'],settle_event}`、`channels.btcpay{driver,base_url,store_id,api_key,webhook_secret,settled_events,processing_events,expired_events,timeout:10}`（结构见 data-model.md §6.1）
- [x] T011 [P] 追加 `.env.example` 六项：`PAYMENT_CHANNEL` / `PAYMENT_INVOICE_TTL_MINUTES` / `BTCPAY_BASE_URL` / `BTCPAY_STORE_ID` / `BTCPAY_API_KEY` / `BTCPAY_WEBHOOK_SECRET`（密钥仅留空占位，不入库不入 git）

### 通道抽象层（WS2）

- [x] T012 [P] 新建接口 `app/Domain/Payment/Contracts/PaymentChannel.php`：`code()` / `createInvoice(PaymentInvoiceRequest): PaymentInvoiceData` / `verifyWebhook(string $rawBody, array $headers): bool` / `parseWebhook(string $rawBody, array $headers): WebhookEvent`（**不含** `fetchInvoice`，见 research R-001）
- [x] T013 [P] 新建接口 `app/Domain/Payment/Contracts/PaymentChannelRegistry.php`：`for(string $code): PaymentChannel` / `codes(): array` / `default(): PaymentChannel`
- [x] T014 [P] 新建 enum `app/Domain/Payment/Dto/PaymentStatus.php`：6 case + `isTerminal(): bool`（终态 = `confirmed|expired|manual`）
- [x] T015 [P] 新建 DTO `app/Domain/Payment/Dto/PaymentInvoiceRequest.php`：`final readonly`，字段 `tenantId/ref/planCode/symbol/chain/amount/description/ttlMinutes`（amount 为 bcmath 字符串）
- [x] T016 [P] 新建 DTO `app/Domain/Payment/Dto/PaymentInvoiceData.php`：`final readonly`，字段 `channelInvoiceId/payAddress/payUri/amount/expiresAt/raw`
- [x] T017 [P] 新建 DTO `app/Domain/Payment/Dto/WebhookEvent.php`：`final readonly`，字段 `eventType/ref/channelInvoiceId/paidAmount/currency/raw`
- [x] T018 [P] 新建 `app/Domain/Payment/Exceptions/PaymentChannelException.php`
- [x] T019 新建 `app/Domain/Payment/PaymentChannelManager.php`：读 `config('payment.channels.{code}.driver')` → `app($driverClass)`；未知 code / driver 未实现 `PaymentChannel` **抛异常绝不降级**（constitution V）
- [x] T020 [P] 新建 `app/Domain/Payment/Channels/FakePaymentChannel.php`：`code()='fake'`；确定性 `channelInvoiceId='fake_'.$ref`、假地址 `TFAKE...`；`verifyWebhook` 用 `hash_hmac('sha256',$rawBody,secret)` 常量时间比对（`hash_equals`）；`parseWebhook` 解析 `{event,ref,invoice_id,paid_amount,currency}`；**非 `config('payment.channels.fake.allowed_env')` 环境被解析时抛异常**（R-004 红线）
- [x] T021 [P] 新建 `app/Domain/Payment/Channels/BtcPayChannel.php`：`createInvoice` `POST {base_url}/api/v1/stores/{store_id}/invoices`（`Http` facade，超时 10s，body 含 `amount`/`currency`/`metadata:{ref,tenant_id,plan_code}`）；`verifyWebhook` 读 `BTCPay-Sig` 头，`hash_hmac('sha256',$rawBody,$webhook_secret)` + `hash_equals`（对**原始字节**验签）；`parseWebhook` 取 `type` 事件名 + `metadata.ref`（缺失 → null）
- [x] T022 在 `app/Providers/AppServiceProvider.php` 绑定 `PaymentChannelRegistry` → `PaymentChannelManager`，**bind 非 singleton**（Octane 长驻禁 static 缓存，附注释，沿用该文件既有 L39-L52 惯例）
- [x] T023 [P] 新建 Unit 测试 `tests/Unit/Payment/PaymentChannelConfigTest.php`：未知 `channel_code` 抛异常、`driver` 缺配置抛异常、`fake` 在非 local/testing 环境抛异常（FR-001 / constitution V）
- [x] T024 [P] 新建 Unit 测试 `tests/Unit/Payment/WebhookSignatureTest.php`：BTCPay 验签**正例 1 + 负例 4**（篡改 body / 错密钥 / 缺头 / 前缀非法），100% 判定正确且**无需联网**（FR-004）

**Checkpoint**: 数据层与通道抽象层就绪 —— 可执行 `php artisan migrate`；`config('payment.channels')` 可读；金额读出为 string；`processed_key` 允许多 NULL（插入两行 NULL 成功、两行同值失败）。

---

## Phase 3: User Story 1 - 套餐购买支付闭环 (Priority: P1) 🎯 MVP

**Goal**: 免费版租户发起 pro 升级 → 生成 USDT 支付单（含收款地址与倒计时）→ 通道确认足额到账 → 订阅自动激活为 pro、配额立即按新套餐可用，全程无人工介入。

**Independent Test**: `FakeChannelCheckoutFlowTest` —— 用 fake 通道建单 → 投递 fake webhook → 断言 `tenants.plan_code='pro'`、新增 1 条 `subscriptions.status=active`、`payment_invoices.status='confirmed'` 且 `confirmed_at` 非空、`QuotaGuard::assertEntitled` 对 pro 级策略放行。

> **注**：US1 与 US2 共用 `BillingWebhookController` 与 `PaymentActivationService` 两个文件。US1 阶段实现**完整流水线**（验签 → 解析 → 定位 → 条件更新 → provision → 审计），US2 阶段在此之上做并发削峰与安全加固并补全证据链断言。

### Implementation（WS3 + WS4 的 US1 面）

- [x] T025 [US1] 实现 `app/Domain/Payment/PaymentInvoiceService.php` 的 `create(int $tenantId, string $planCode, ?string $channelCode=null)`：`ref='pi_'.strtolower((string) Str::ulid())`；金额取 `PlanService::planConfig()['price_monthly']` → `number_format(...,8,'.','')` 字符串；未知 `plan_code` 抛异常；同租户同目标套餐存在非终态单 → **原样返回既有单**（US4-3 不堆叠）；建单序 = 落 `pending` 行 → 调通道 `createInvoice()` → 回填通道字段；通道异常 → **删除刚落的行**（无半状态单，spec Edge）；通道返回金额 ≠ 应付 → `bccomp` 拒建单（FR-002 / FR-011 / FR-013）
- [x] T026 [US1] 在 `app/Domain/Payment/PaymentInvoiceService.php` 实现 `listForTenant(int $tenantId, int $limit=20)`（时间倒序）与 `pendingFor(int $tenantId, ?string $planCode=null)`、`expireStale(?int $tenantId=null): int`（非终态且 `expires_at < now` → `expired`，条件更新 `WHERE status IN ('pending','processing','partial') AND expires_at < now`）（FR-009 / FR-012）
- [x] T027 [US1] 实现 `app/Domain/Payment/PaymentActivationService.php::activate(PaymentInvoice $invoice, string $paidAmount, string $eventType): bool`：**单事务内固定执行序** —— ①支付单条件更新 `SET status='confirmed', paid_amount_usdt=?, confirmed_at=NOW() WHERE id=? AND ref=? AND status IN ('pending','processing','partial')`（`affected=0` ⇒ 直接 `return false` 不接线）②`SubscriptionService::provision($invoice->tenant_id, $invoice->plan_code, trialDays: 0)` ③写 `payment_webhook_events.processed_key = "{ref}:{event_type}"`；**通道 IO 与通知投递一律在事务外**；任一步异常整体回滚（不存在半激活，FR-006 / constitution IV）
- [x] T028 [US1] 实现 `app/Http/Controllers/Api/V1/BillingWebhookController.php` 固定流水线 ①~⑦（见 webhook-contract.md §2）：解析 `{channel}` → Registry（未知 404）／`verifyWebhook` 失败 → **401** + 落 `signature_valid=false,status=rejected` + 原始载荷 + 零状态变更／`parseWebhook` 不可解析 → 200 + `rejected` + 审计／按 `ref` 定位支付单（`ref` 缺失或不存在 → 200 + `rejected` + `error` 留痕，不重试）→ 回填 `tenant_id`/`payment_invoice_id`／事务内调用 `PaymentActivationService`（成功 processed、已处理/乱序 `idempotent_skipped`）／异常 → 回滚 + **500**（触发通道重投）；实现 `pending|processing` → `processing`、`0<paid<amount` → `partial`、`expired` 收到足额 → `manual`（**不自动激活**）四类非激活迁移（FR-003 / FR-004 / FR-005 / FR-012）
- [x] T029 [US1] 在 `routes/api.php` **既有 `auth:sanctum` + `tenant` 分组之外**独立注册 `Route::post('v1/billing/webhook/{channel}', BillingWebhookController::class)`（**不挂** `auth:sanctum` / `tenant`，验签即鉴权）
- [x] T030 [US1] 核对并（如需）在 `bootstrap/app.php` 的 CSRF 豁免中放行 webhook 路由（api 组天然豁免则记明结论，避免回调用 419）
- [x] T031 [US1] 新建命令 `app/Console/Commands/BillingExpireInvoicesCommand.php`（签名 `billing:expire-invoices`，薄命令范式对齐 `app/Console/Commands/BillingRefreshStatusCommand.php`，委托 `PaymentInvoiceService::expireStale()`）（FR-012）
- [x] T032 [US1] 新建命令 `app/Console/Commands/BillingFakeWebhookCommand.php`（签名 `billing:fake-webhook --ref= --event= --paid=`，构造带签名载荷并投递本平台端点；等价真实通道投递；**非 local/testing 环境禁用**）
- [x] T033 [US1] 新建 `app/Http/Controllers/Api/V1/BillingController.php` 并在 `routes/api.php` 既有分组内注册 3 路由：`GET /v1/billing/summary`（当前套餐/周期起止/各计量用量与上限，与计费页同数据源）、`GET /v1/billing/invoices`（本租户倒序分页）、`GET /v1/billing/invoices/{ref}`（跨租户 → 404），并加 `POST /v1/billing/invoices`（body `{plan_code}` → `PaymentInvoiceService::create()`）（FR-009 / FR-010）

### Tests for User Story 1

- [x] T034 [P] [US1] 新建 Unit 测试 `tests/Unit/Payment/PaymentInvoiceServiceTest.php`：`ref` 唯一性与格式、同租户同套餐复用不堆叠、TTL 边界、过期清扫条件、金额字符串逐位相等（`bccomp` 足额/不足额/超额）、通道失败无残留行（FR-002 / FR-011 / FR-012）
- [x] T035 [P] [US1] 新建 Unit 测试 `tests/Unit/Payment/PaymentActivationServiceTest.php`：条件更新命中 → `provision` 被调 1 次；`affected=0` → 返回 false 且**不重复接线**；事务内异常 → 支付单状态回滚（FR-006）
- [x] T036 [US1] 新建 Integration 测试 `tests/Integration/Payment/FakeChannelCheckoutFlowTest.php`：US1 全链路（建单 → fake webhook → confirmed → 订阅 `active` + `tenants.plan_code='pro'` + 配额按新套餐放行），并断言平台侧「确认到账 → 激活 → 配额可用」耗时 ≤ 30 秒（SC-001）；tearDown 按 `ref`/`tenant_id` 精确清三表，`try/catch` 包住且先于 `parent::tearDown()`

**Checkpoint**: US1 独立可交付 —— 这是一笔可收费 MVP。可用命令行（`billing:fake-webhook`）+ API 完成全链路验证，无需 UI。

---

## Phase 4: User Story 2 - Webhook 安全与幂等 (Priority: P2)

**Goal**: 平台验证 webhook 签名真实性（伪造一律拒绝并留审计）；同一笔支付的重复通知（网络重试/人工重放/恶意重放）不导致重复激活、重复续期或重复记账；并发同 ref 只处理一次。

**Independent Test**: 对同一支付单 `ref` 连续重放 3 次相同 webhook，断言订阅激活记录恰好 1 条、配额恰好刷新 1 次（即零增量）、`processed_key` 计数 = 1；用错误签名重放，断言 100% 拒绝且零状态变更。

### Implementation for User Story 2（在 T028 端点之上的加固）

- [x] T037 [US2] 在 `app/Http/Controllers/Api/V1/BillingWebhookController.php` 接入 Redis 单飞锁：键 `tenant:{tid}:payment:webhook:{ref}`，`Redis::connection('default')->set($k,$v,'EX',15,'NX')`（**phpredis 参数位顺序**，先例 `app/Domain/Tenancy/TenantKeyResolver.php#L97`）；拿不到锁 → 200 + `idempotent_skipped`；锁**非正确性依赖**（仅削峰），Redis 不可用时降级为直接处理；锁键名固化为类常量以便测试按已知逻辑名精确清理（FR-005 / constitution VI）
- [x] T038 [US2] 硬化 `BillingWebhookController` 安全红线（webhook-contract.md §7）：`ref` 长度与字符集白名单校验（界值：长度 ≤64 且匹配 `^pi_[0-9a-z]{26}$`，防注入/超长）、`raw_payload` **原样**落库（含签名字头，即使验签失败）、请求体不参与 SQL 拼接、未知 `channel_code` 抛异常而非回落默认通道（FR-004 / constitution V）

### Tests for User Story 2

- [x] T039 [P] [US2] 新建 Unit 测试 `tests/Unit/Payment/PaymentStatusMachineTest.php`：以 `#[DataProvider]` 遍历 data-model.md §5 迁移表逐行（含 `pending/processing/partial → confirmed`、`expired → manual`），断言**终态拒绝更新**（`confirmed → *` 禁止）、乱序 `processing` 丢弃、过期后到账 → `manual` 不自动激活（FR-003 / FR-012 / FR-013）
- [x] T040 [P] [US2] 新建 Integration 测试 `tests/Integration/Payment/WebhookSignatureRejectionTest.php`：错误签名重放 → HTTP **401**、支付单状态零变更、`signature_valid=false` 审计行 100% 留存且 `raw_payload` 完整（SC-003）
- [x] T041 [US2] 新建 Integration 测试 `tests/Integration/Payment/WebhookIdempotencyTest.php`：同 ref 重放 3 次 → `subscriptions` 该租户仅新增 1 条 active 行、`payment_webhook_events WHERE processed_key='{ref}:{event_type}'` 计数 = 1、`payment_invoices.confirmed_at` 仅一次迁移、当日 Redis 计数与 `meter_events` **零增量**（SC-002 / FR-005）；setUp 与 tearDown 均按已知键名 `tenant:{tid}:payment:webhook:{ref}` 精确清单飞锁键，`try/catch` 包住且先于 `parent::tearDown()`（constitution VI）
- [x] T042 [US2] 新建 Integration 测试 `tests/Integration/Payment/WebhookOrderingAndUnknownRefTest.php`：先 `confirmed` 后到 `processing` → 状态不回退且落 `idempotent_skipped`；`ref` 不存在 / `metadata.ref` 缺失 → 200 + `rejected` 且不影响其他支付单（PoC B3 语义，FR-003）
- [x] T043 [US2] 新建 Integration 测试 `tests/Integration/Payment/WebhookConcurrencyTest.php`（`@group redis`，带 `tests/Concerns/ChecksRedisAvailability` 守卫）：同 ref 并发投递 → `confirmed_at` 仅一次迁移，锁冲突方返回 `idempotent_skipped`；setUp 与 tearDown 双清单飞锁键 `tenant:{tid}:payment:webhook:{ref}`（FR-005 / constitution VI）

**Checkpoint**: US2 独立可验证 —— 资金安全红线与幂等证据链闭合，可脱离 UI 单独交付（对应 `docs/p9-btcpay-poc-plan.md` 阶段 B）。

---

## Phase 5: User Story 3 - 订阅续费与失败流转 (Priority: P2)

**Goal**: 订阅到期前按 7/3/1 天节奏提醒租户续费（含一键续费入口）；到期未续费沿 P3 状态机流转 `past_due → 宽限期 → suspended`，宽限期内补缴成功则恢复 active 且周期顺延。

**Independent Test**: `RenewalRecoveryTimelineTest` —— 构造 3 天后到期订阅 → 提醒行产生 → `billing:refresh-status` 推至 `past_due` → 超宽限转 `suspended` → 宽限期内 fake webhook 补缴 → 恢复 `active` 且 `ends_at` 顺延一个周期、提醒不重复发送。

### Implementation for User Story 3（WS5）

- [x] T044 [P] [US3] 新建 `app/Domain/Notification/Payloads/RenewalReminderPayload.php`（与 `RiskNotificationPayload` 同构，字段：套餐名、到期日 UTC、剩余天数、应付金额 bcmath 字符串、**一键续费链接** = Filament 计费页绝对 URL + `plan_code`）（FR-007）
- [x] T045 [P] [US3] 新建 `app/Jobs/RenewalReminderJob.php`（镜像 `app/Jobs/RiskNotificationJob.php` 三段式：读 `queued` 行 → `NotifierChannel::send()` → 置 `sent`/`failed`；队列 `notifications`，`tries=3`，backoff `[10,30,90]`）
- [x] T046 [US3] 实现 `app/Domain/Payment/RenewalReminderService.php::dispatch(?int $tenantId=null, ?CarbonImmutable $nowUtc=null): int`：取最新订阅行（`status ∈ ('active','trialing')` 且 `ends_at` 未来）→ `days_left = ends_at->diffInDays(now)` 命中 `config('payment.renewal_reminder_days')` → 按 `config('payment.reminder_channels')` 在套餐 `channels` 白名单内（`PlanService::allowsChannel()`，`log` 系统渠道恒放行）落 `queued` 行（唯一索引幂等，续费顺延即新周期）→ 触发 `RenewalReminderJob`（FR-007 / FR-008）
- [x] T047 [US3] 新建命令 `app/Console/Commands/BillingRenewalRemindersCommand.php`（签名 `billing:renewal-reminders`，薄命令范式对齐 `BillingRefreshStatusCommand.php`）
- [x] T048 [US3] 在 `routes/console.php` 追加调度 `Schedule::command('billing:renewal-reminders')->dailyAt('00:10')->timezone('UTC')->withoutOverlapping(10)`（紧邻既有两条调度，同范式），并追加 `Schedule::command('billing:expire-invoices')->everyFiveMinutes()->withoutOverlapping(5)`（FR-012 过期单自动失效，契约 §7）；**不改** `billing:refresh-status` 既有调度与 P3 状态机（FR-008）

### Tests for User Story 3

- [x] T049 [P] [US3] 新建 Unit 测试 `tests/Unit/Payment/RenewalReminderServiceTest.php`：档位命中/不命中边界（7/3/1 与非档位日）、同周期重复调用零新增行、续费顺延后新周期可再发、套餐渠道白名单过滤（FR-007）
- [x] T050 [US3] 新建 Integration 测试 `tests/Integration/Payment/RenewalRecoveryTimelineTest.php`：完整时间线 提醒 → `past_due` → 宽限 → `suspended` → 补缴恢复 + 周期顺延，各转换时点与 `config('plans.past_due_grace_days')` 一致、误差 ≤ 1 个调度周期，且不产生双重计费（SC-004 / FR-008）
- [x] T051 [US3] 扩展既有 `tests/Integration/Console/ScheduleRegistrationTest.php`：断言 `billing:renewal-reminders` 已注册且为 `dailyAt('00:10')` UTC + `withoutOverlapping(10)`，且 `billing:expire-invoices` 已注册为 `everyFiveMinutes`（FR-007 / FR-012）

**Checkpoint**: US1 + US3 可协同 —— 续费留存闭环成立（提醒 → 手工续付 → 恢复）。

---

## Phase 6: User Story 4 - Filament 计费页 (Priority: P3)

**Goal**: 租户在 Admin 面板一站式完成查看套餐与配额用量看板、发起升级、查看历史支付单与状态、进入续费入口；数据严格租户隔离。

**Independent Test**: 以两个租户分别登录 `/admin` 计费页，各自完成升级发起与支付单查询，验证数据互不可见；用量看板数字与 `TwoPhaseMeter::usedToday()` 一致。

### Implementation for User Story 4（WS6）

- [x] T052 [US4] 新建 `app/Filament/Admin/Widgets/QuotaUsageWidget.php`：计量事件 `ai_call` / `order_placed` 当日用量与上限 + 资源数（交易所账户/策略实例/信号订阅）；**复用** `PlanService` + `QuotaGuard` + `TwoPhaseMeter::usedToday()`，不另算口径（FR-009）
- [x] T053 [US4] 新建 `app/Filament/Admin/Pages/Billing.php`：导航组「计费」；展示当前套餐/周期起止/用量看板（内嵌 `QuotaUsageWidget`）；页面内查询一律显式 `TenantContext::getTenantId()`（FR-009 / FR-010）
- [x] T054 [US4] 新建 `app/Filament/Admin/Resources/PaymentInvoiceResource.php` + `app/Filament/Admin/Resources/PaymentInvoiceResource/Pages/ListPaymentInvoices.php`：时间倒序展示金额/状态/创建时间；**只读**（禁 create/edit/delete，policy 仅 `viewAny`/`view`）且**无任何退款动作/路径**（FR-014 反向断言：退款人工线下，平台侧不提供入口）；隔离走 `BelongsToTenant` + `TenantScope` 自动过滤（FR-009 / FR-010）
- [x] T055 [US4] 在 `app/Filament/Admin/Pages/Billing.php` 实现升级 Action：选中套餐 → `PaymentInvoiceService::create()` → 展示 USDT 金额、收款地址、二维码（`payUri`）与倒计时；已有待支付单 → **引导至既有单**（US4-3）；满足「点击升级 → 选套餐 → 见收款信息」≤ 3 步（SC-005）
- [x] T056 [P] [US4] 新建 Feature 测试 `tests/Feature/Payment/BillingPageTest.php`：计费页渲染 + 用量数字与 `TwoPhaseMeter` 一致 + 双租户零串扰 + 产物只读（`RefreshDatabase`）（FR-009 / SC-005 / SC-006）
- [x] T057 [P] [US4] 新建 Feature 测试 `tests/Feature/Payment/BillingApiTest.php`：`summary`/`invoices`/`show` 返回结构、跨租户 `{ref}` → 404（不泄露存在性）、`POST invoices` 参数校验（FR-009 / FR-010）
- [x] T058 [US4] 新建 Feature 测试 `tests/Feature/Payment/BillingTenantIsolationTest.php`：双租户并发各完成一笔支付 → 双方支付单/订阅/用量零串扰、跨租户访问 100% 拒绝（扩展既有 `tests/Integration/Billing/TenantScopeIsolationTest.php` 范式）（SC-006 / FR-010）

**Checkpoint**: US1–US4 全部独立可用 —— 自助化运营前界面就绪。

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: 收口冒烟、真实通道占位用例与文档同步（plan.md WS7）

- [x] T059 [P] 新建命令 `app/Console/Commands/PocVerifyPaymentCommand.php`（签名 `poc:verify-payment`，离线全链路断言，输出 `ALL CHECKS PASSED`；风格对齐既有 `PocVerifyDslCommand`）：覆盖建单 → 验签 → 状态推进 → 订阅激活 → 配额生效 → 幂等重放 → 伪造签名拒绝 → 提醒判定（SC-001~006 平台侧），并断言 webhook 单请求处理耗时 < 200ms（离线基准，承接 plan.md Performance Goals 的 p95 设计目标）
- [x] T060 [P] 新建 Integration 测试 `tests/Integration/Payment/BtcPayChannelLiveTest.php`：真实 BTCPay 端到端用例，以**可用性标志** skip（节点/BTCPay 不可达即跳过，沿用既有 56 skipped 惯例，**不得让基线变红**，坑 #23）
- [x] T061 [P] 更新 `README.md`：P9 行状态改写为「我方实现已交付（离线轨道已验证），真实链路待节点」，并同步技术债索引
- [x] T062 [P] 在 `docs/p9-btcpay-poc-plan.md` 追加归档标注：说明阶段 A/B/C 待节点执行、BtcPayChannel 类与验签纯函数已随本期交付
- [x] T063 执行 quickstart.md 三层验证：§2 离线断言测试、§3 集成测试（需 ServBay MySQL/Redis 在线；Redis 停机时仅 `@redis` 用例 skip）、§4 手工端到端（含重放 3 次与伪造签名对抗）、§5 计费页手工点检（需 UI 会话）、§6 续费失败流转时间线
- [x] T064 全量回归 `php artisan test` 并核对基线无新增 failed；按 plan.md §5 提交节奏收口（每条提交含实现 + 测试 + 文档同步，消息格式 `feat(spec-007): 中文简述`）

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup（Phase 1）**: 无依赖，可立即开始
- **Foundational（Phase 2）**: 依赖 Setup 完成 —— **阻塞全部用户故事**
- **User Stories（Phase 3~6）**: 均依赖 Foundational 完成
  - US1（P1）与 US3（P2）、US4（P3）之间无强依赖：US3 只依赖 Foundational + US1 的 `PaymentInvoiceService`（补缴走同链路），US4 只依赖 Foundational + `PaymentInvoiceService`；故 **US3/US4 可与 US1 部分并行**，但 US3 的端到端时间线用例需 US1 的 webhook 端点就位
  - **US2 依赖 US1 的 `BillingWebhookController` / `PaymentActivationService` 文件就位**（二者共用端点文件；US2 是同一端点的并发削峰与安全加固 + 证据链断言，无法先于 US1 交付）
- **Polish（Phase 7）**: 依赖所需用户故事完成

### User Story Dependencies

- **US1（P1）**: Foundational 后即可开始 —— MVP，无其他故事依赖
- **US2（P2）**: Foundational + US1 的 webhook 端点（**注：共用两个文件，不可先于 US1**）
- **US3（P2）**: Foundational + US1 的 `PaymentInvoiceService`（提醒命令本身可独立先做，端到端恢复用例依赖 US1）
- **US4（P3）**: Foundational + US1 的 `PaymentInvoiceService` 与 `BillingController` 数据源

### Within Each User Story

- 数据/模型 → 服务 → 端点/UI；核心实现先于集成用例
- 用例复用 Domain 层公开方法（`PaymentInvoiceService` / `PaymentActivationService`），不直改内部状态
- 测试 tearDown 按 `ref` / `tenant_id` 精确清三张新表，`try/catch` 包住且先于 `parent::tearDown()`

### Parallel Opportunities

- Phase 1：T002 / T003 并行
- Phase 2：T005/T006（两张 migration）、T007-T011（模型与配置）、T012-T018（契约与 DTO）、T020/T021（两个通道）、T023/T024（两个 Unit 用例）均可并行；T019 依赖 T012/T013；T022 依赖 T019
- Phase 3：T034/T035 并行；T029/T030 并行于 T033
- Phase 4：T039/T040 并行；T041/T042/T043 在 T037/T038 后
- Phase 5：T044/T045 并行；T049 并行于 T046
- Phase 6：T056/T057 并行；T054 与 T052/T053 并行
- Phase 7：T059/T060/T061/T062 并行

---

## Parallel Example: Phase 2（Foundational）

```bash
# 三张 migration 与模型可同时开工（不同文件）：
Task: "新建 migration payment_invoices（T004）"
Task: "新建 migration payment_webhook_events（T005）"
Task: "新建 migration payment_renewal_reminders（T006）"

# 契约与 DTO 可同时开工：
Task: "新建 Contracts/PaymentChannel 接口（T012）"
Task: "新建 Contracts/PaymentChannelRegistry 接口（T013）"
Task: "新建 Dto/PaymentStatus enum（T014）"
Task: "新建 Dto/PaymentInvoiceRequest / PaymentInvoiceData / WebhookEvent（T015/T016/T017）"

# 两个通道驱动可同时开工：
Task: "新建 Channels/FakePaymentChannel（T020）"
Task: "新建 Channels/BtcPayChannel（T021）"
```

## Parallel Example: User Story 1

```bash
# 两个 Unit 用例可同时开工：
Task: "Unit PaymentInvoiceServiceTest（T034）"
Task: "Unit PaymentActivationServiceTest（T035）"

# 路由注册与 bootstrap 核对可与 API 控制器并行：
Task: "routes/api.php 注册 webhook（T029）"
Task: "bootstrap/app.php CSRF 核对（T030）"
```

---

## Implementation Strategy

### MVP First（User Story 1 Only）

1. 完成 Phase 1: Setup
2. 完成 Phase 2: Foundational（**CRITICAL**，阻塞全部故事）
3. 完成 Phase 3: US1
4. **STOP and VALIDATE**：跑 `poc:verify-payment` 骨架级断言 + `FakeChannelCheckoutFlowTest`，确认「建单 → 确认 → 订阅 active → 配额可用」闭环
5. 此处即构成**可收费 MVP**（第一笔收入通路）

### Incremental Delivery

1. Setup + Foundational → 地基就绪
2. US1 → 独立验证 → 交付（MVP：首笔收入通路）
3. US2 → 独立验证 → 交付（资金安全红线：验签 + 幂等 + 并发）
4. US3 → 独立验证 → 交付（续费留存闭环）
5. US4 → 独立验证 → 交付（自助化界面）
6. Polish → 冒烟命令 + 文档同步 + 真实通道占位用例

### 与节点依赖轨道的关系

plan.md §6 的真实 BTCPay 验收（`docs/p9-btcpay-poc-plan.md` 阶段 A/B/C：A1/A2 部署冒烟、B1 真实验签、B2 重放 3 次幂等、B3 metadata 伪造拒收、C1/C2 状态机时间线）**不在本期交付门禁内**，不产生任何编码任务；节点就绪后独立执行并销账。

---

## Notes

- [P] 任务 = 不同文件、无未完成依赖
- [Story] 标签用于回溯到 spec.md 的用户故事
- 全部任务遵循 checklist 格式（复选框 + 任务 ID + [P?] + [Story?] + 精确文件路径）
- 每个用户故事在 Checkpoint 处可停下单独验证
- 提交粒度按 plan.md §5 的 7 条节奏（按工作流而非按任务）
- 避免：模糊任务、同文件冲突、破坏故事独立性的跨故事依赖
