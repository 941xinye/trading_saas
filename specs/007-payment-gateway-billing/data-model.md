# Data Model: P9 支付网关 + 计费 UI

> spec 007 plan 阶段数据模型设计。**新增三张表**（`payment_invoices` / `payment_webhook_events` / `payment_renewal_reminders`），**不新增**订阅、套餐、配额相关实体（Subscription / Plan / Tenant / meter_events 全部复用 P3，只新增激活触发源）。

## 1. 总览

| 数据 | 载体 | 说明 |
|---|---|---|
| 支付单 | **新表** `payment_invoices` | 一笔套餐购买/续费收款单据，`ref` 为幂等键（FR-002） |
| webhook 接收审计 | **新表** `payment_webhook_events` | 每次投递留痕；`processed_key` 承担「processed 行至多 1 条」证据（FR-004/005） |
| 续费提醒发送事实 | **新表** `payment_renewal_reminders` | 唯一索引承担每周期每档提醒的幂等（FR-007） |
| 通道定义 | `config/payment.php` | 通道 driver 映射与凭据（仿 `config/exchange.php` 结构） |
| 订阅/租户 | 既有 `subscriptions` / `tenants` | 接线只经 `SubscriptionService::provision()`，不改表结构 |
| 配额与计量 | 既有 `meter_events` + Redis 日计数键 | 口径不变；`plan_code` 变更即生效（research R-003） |
| 通知发送 | 既有通知域（`NotifierChannel` + Jobs + Payloads） | 提醒复用，不新建渠道 |

**为何不新增订阅状态字段**：spec Key Entities 明确「复用既有实体，不新增字段语义，只新增激活触发源」；支付确认走 `SubscriptionService::provision()` 的既有 provision/恢复路径（`app/Domain/Billing/SubscriptionService.php#L38-L77`），P3 状态机 `trialing/active/past_due/canceled(→suspended 走 tenants.status)` 零改动（FR-008）。

## 2. 表 `payment_invoices`（支付单）

| 列 | 类型 | 约束 | 说明 |
|---|---|---|---|
| `id` | bigint PK | | |
| `tenant_id` | unsignedBigInteger | FK → `tenants.id` cascadeOnDelete；`index(tenant_id, status)` | 行级隔离（FR-010） |
| `ref` | string(64) | **unique** | 平台支付单号（对外展示 + webhook 幂等键）。生成：`Str::ulid()` 前缀化（如 `pi_01J...`） |
| `channel_code` | string(30) | | `fake` / `btcpay`（FR-001）；未知值抛异常 |
| `channel_invoice_id` | string(120) | nullable, index | 通道侧发票 id（建单后回填，webhook 回读用） |
| `plan_code` | string(30) | | 目标套餐（`PlanService::planExists()` 校验，未知抛异常） |
| `currency` | string(10) | default `USDT` | 计价币种（本期常量） |
| `chain` | string(10) | default `TRC20` | 收单链（Q1 决策，单值） |
| `amount_usdt` | **decimal(20,8)** | NOT NULL | 应付金额；bcmath 比较（FR-011） |
| `paid_amount_usdt` | **decimal(20,8)** | nullable | 通道上报实收；部分支付/超额判定依据 |
| `pay_address` | string(120) | nullable | 收款地址（通道返回，展示给租户） |
| `pay_uri` | string(255) | nullable | 通道支付 URI / 二维码内容 |
| `status` | enum | `pending,processing,partial,confirmed,expired,manual`，default `pending`；`index(tenant_id,status)` | 状态机（FR-003，见 §5） |
| `expires_at` | timestamp | NOT NULL | 支付有效期（FR-012）；`config('payment.invoice_ttl_minutes')` |
| `confirmed_at` | timestamp | nullable | 确认时点（**独立一次性标志**，constitution IV） |
| `meta` | json | nullable | 通道建单原始响应摘要（排障） |
| `created_at` / `updated_at` | timestamps | | |

**索引**：`unique(ref)`；`index(tenant_id, status)`；`index(tenant_id, plan_code, status)`（待支付单唯一性查询）；`index(channel_invoice_id)`；`index(status, expires_at)`（过期清扫批）。

**金额列落地方式**：migration 用 `$table->decimal('amount_usdt', 20, 8)`；模型 `$casts = ['amount_usdt' => 'decimal:8', 'paid_amount_usdt' => 'decimal:8']`（Eloquent `decimal:8` 读出 `string`，直接喂 `bccomp`/`bcsub`，浮点全程不参与）。**禁止** 用 float cast。

## 3. 表 `payment_webhook_events`（webhook 接收审计）

| 列 | 类型 | 约束 | 说明 |
|---|---|---|---|
| `id` | bigint PK | | |
| `tenant_id` | unsignedBigInteger | nullable | 仅在成功定位支付单时回填（无法定位 → NULL） |
| `payment_invoice_id` | unsignedBigInteger | nullable, FK → `payment_invoices.id` nullOnDelete | 定位到的支付单 |
| `channel_code` | string(30) | | 来源通道 |
| `ref` | string(64) | nullable, index | 尽力提取的平台单号 |
| `event_type` | string(60) | | 通道事件名（`InvoiceSettled` / `InvoiceProcessing` / `InvoiceExpired` / fake 事件名） |
| `signature_valid` | boolean | | 验签结果（FR-004） |
| `status` | enum | `received,processed,idempotent_skipped,rejected,error`，default `received` | 处理结果 |
| `processed_key` | string(80) | nullable, **unique** | 仅 `processed` 时写 `"{ref}:{event_type}"`；NULL 可重复（MySQL 唯一索引对 NULL 互不相同）——使「同 (ref,event_type) 的 processed 行至多 1 条」可断言（research R-006） |
| `raw_payload` | json | | 原始载荷全文（含签名字头，FR-004 审计要求） |
| `error` | text | nullable | 失败原因（B3 的 metadata 伪造推断留痕） |
| `received_at` | timestamp | | |
| `processed_at` | timestamp | nullable | |
| `created_at` / `updated_at` | timestamps | | |

**索引**：`unique(processed_key)`；`index(ref, created_at)`；`index(channel_code, status, created_at)`；`index(payment_invoice_id)`。

## 4. 表 `payment_renewal_reminders`（续费提醒发送事实）

| 列 | 类型 | 约束 | 说明 |
|---|---|---|---|
| `id` | bigint PK | | |
| `tenant_id` | unsignedBigInteger | FK → `tenants.id` cascadeOnDelete；index | 隔离 |
| `subscription_id` | unsignedBigInteger | FK → `subscriptions.id` cascadeOnDelete | 被提醒的订阅 |
| `subscription_ends_at` | timestamp | | 该周期的到期时点（判定键组成部分） |
| `days_before` | smallint | | 档位 ∈ `{7,3,1}`（`config('payment.renewal_reminder_days')`） |
| `plan_code` | string(30) | | 当前套餐（载荷展示用） |
| `channel` | string(20) | | 发送渠道（`log`/`mail`/`telegram`/`wechat_work`） |
| `status` | enum | `queued,sent,failed`，default `queued` | 发送结果（镜像 `risk_notifications` 四段式去掉 deduped） |
| `provider_message_id` | string(100) | nullable | 渠道侧消息 id |
| `error` | text | nullable | 失败原因 |
| `sent_at` | timestamp | nullable | |
| `created_at` / `updated_at` | timestamps | | |

**唯一索引**：`unique(subscription_id, subscription_ends_at, days_before, channel, 'idx_prr_cycle_reminder')` —— **每周期每档每渠道至多一行**，续费顺延（`ends_at` 变化）即新周期自然产生新行（research R-007，constitution IV：不复用会被正常流程改写的业务字段做守卫）。

## 5. 状态机（FR-003 / FR-012 / FR-013）

```
                 ┌──────────── 通道 webhook（确认数未达） ────────────┐
                 ▼                                                  │
  pending ──▶ processing ──▶ confirmed(终态)   ← 足额到账（bccomp(paid, amount, 8) >= 0）
     │            │
     │            └──────▶ partial ──▶ confirmed  (有效期内补齐至足额，Q2 决策)
     │                          └─────▶ manual   (过期仍未补齐 → 待人工)
     │
     ├──── 超过 expires_at ────▶ expired(终态) ──(过期后足额到账)──▶ manual(终态，不自动激活)
     └──── 超额到账 ──────────▶ confirmed(终态)  (paid_amount 记实收，超额不退款不记账，仅人工线下)
```

| 迁移 | 触发 | 守卫 |
|---|---|---|
| `pending → processing` | 通道报告支付中/确认数未达 | 条件更新 `WHERE status='pending'` |
| `pending/processing → confirmed` | 足额（`bccomp(...) >= 0`） | 条件更新 `WHERE status IN ('pending','processing','partial')`；**该更新 = 幂等守卫** |
| `pending/processing/partial → confirmed` | 补齐至足额 | 同上 |
| `pending/processing → partial` | `0 < paid < amount` | 条件更新 `WHERE status IN ('pending','processing')` |
| `pending/processing/partial → expired` | 过期清扫（命令 `billing:expire-invoices`） | 条件更新 `WHERE status IN ('pending','processing','partial') AND expires_at < now` |
| `expired → manual` | 过期后到账 | 只允许 `expired → manual`，**不得** `expired → confirmed` |
| `confirmed → *` | 任何 | **禁止**（终态只进不退，US2-3 乱序不回退以「非终态才接受更新」实现） |

**终态集合**：`confirmed` / `expired` / `manual`。`manual` 的解除仅靠人工（本期无自动路径，FR-014 人工线下）。

## 6. 配置扩展

### 6.1 新增 `config/payment.php`

```php
return [
    'default_channel' => env('PAYMENT_CHANNEL', 'fake'),   // 生产 MUST 显式 btcpay（research R-004）

    'invoice_ttl_minutes' => (int) env('PAYMENT_INVOICE_TTL_MINUTES', 30),

    'renewal_reminder_days' => [7, 3, 1],                  // FR-007
    'reminder_channels' => ['log', 'mail', 'telegram'],     // 需在套餐 channels 白名单内

    'channels' => [
        'fake' => [
            'driver' => \App\Domain\Payment\Channels\FakePaymentChannel::class,
            'allowed_env' => ['local', 'testing'],          // 非此环境启用即抛异常（R-004 红线）
            'settle_event' => 'fake.settled',
        ],
        'btcpay' => [
            'driver' => \App\Domain\Payment\Channels\BtcPayChannel::class,
            'base_url' => env('BTCPAY_BASE_URL'),
            'store_id' => env('BTCPAY_STORE_ID'),
            'api_key' => env('BTCPAY_API_KEY'),
            'webhook_secret' => env('BTCPAY_WEBHOOK_SECRET'),
            'settled_events' => ['InvoiceSettled'],
            'processing_events' => ['InvoiceProcessing', 'InvoiceReceivedPayment'],
            'expired_events' => ['InvoiceExpired'],
            'timeout' => 10,
        ],
    ],
];
```

`.env.example` 追加：`PAYMENT_CHANNEL` / `PAYMENT_INVOICE_TTL_MINUTES` / `BTCPAY_BASE_URL` / `BTCPAY_STORE_ID` / `BTCPAY_API_KEY` / `BTCPAY_WEBHOOK_SECRET`。

### 6.2 既有配置

| 配置 | 变更 |
|---|---|
| `config/plans.php` | **不变**（套餐/价格/白名单沿用 P3；本功能不引入新套餐，spec Assumptions） |
| `config/plans.past_due_grace_days` | 不变（FR-008 沿用 `BILLING_PAST_DUE_GRACE_DAYS`） |
| `config/notifications.php` | **不变**（复用既有 `channel_classes`；提醒走同域） |
| `routes/console.php` | 追加一条调度（见 §8） |

## 7. 租户隔离口径（FR-010 / SC-006）

| 层 | 机制 |
|---|---|
| 表 | 三张新表均带 `tenant_id`；`payment_invoices` / `payment_renewal_reminders` 用 `BelongsToTenant`（自动 `TenantScope` + 创建填充）；`payment_webhook_events` **不用** trait（回调用无上下文，显式写 tenant_id，与 `TwoPhaseMeter` 同思路） |
| Webhook 路径 | 由 payload `ref` 反查 `payment_invoices.tenant_id`（服务端权威），**不接受**客户端传入的 tenant_id |
| Filament | Resource 走 `TenantScope` 自动过滤（research R-008） |
| API | 走既有 `auth:sanctum + tenant`（`BootstrapTenancy` 注入上下文），服务层仍需显式 tenant 参数双保险 |
| 跨租户访问 | 查询返回空集/404；`TenantScopeIsolationTest`（既有 `tests/Integration/Billing/TenantScopeIsolationTest.php`）范式扩展覆盖 |

## 8. Redis 结构（新增键，均为辅助/削峰）

```
tenant:{tid}:payment:webhook:{ref}        # webhook 单飞锁，String，TTL 15s，SET NX EX（非正确性依赖）
tenant:{tid}:payment:reminder:{sid}:{days} # 提醒调度单次标记（可选，DB 唯一索引已保证幂等）
```

> 写锁 MUST 用 phpredis 参数位顺序 `Redis::connection('default')->set($k, $v, 'EX', $ttl, 'NX')`（constitution VII；先例 `app/Domain/Tenancy/TenantKeyResolver.php#L97`、`app/Domain/Risk/Position/PositionRiskMonitor.php#L122`）。

## 9. 迁移与部署顺序

1. `database/migrations/*_create_payment_invoices_table.php`（含唯一/复合索引）
2. `database/migrations/*_create_payment_webhook_events_table.php`（`processed_key` 唯一）
3. `database/migrations/*_create_payment_renewal_reminders_table.php`（周期唯一索引）
4. `config/payment.php` + `.env.example`
5. 代码层：`app/Domain/Payment/` 包（Contracts/Dto/Channels/Services）+ 命令 + Job/Payload + Filament 页面
6. 调度：`routes/console.php` 追加 `billing:renewal-reminders`（00:10 UTC）

> 无数据回填；既有租户/订阅/配额数据零影响（新增表全部新增语义，不触碰 P3 列）。回滚 = 删除三表 + 移除配置项，无副作用。

## 10. 交付双轨（对应 research R-004）

| 轨道 | 数据影响 | 状态 |
|---|---|---|
| 离线（Fake 通道） | 三表全量可用；断言全部可跑 | 本期交付 |
| 真实（BTCPay，待节点） | 同表同结构；`channel_code='btcpay'` 行 + 真实 `channel_invoice_id`/`pay_address` | 节点就绪后由 `docs/p9-btcpay-poc-plan.md` 阶段 A/B/C 验证 |
