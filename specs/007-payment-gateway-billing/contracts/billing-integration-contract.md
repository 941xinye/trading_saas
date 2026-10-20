# Contract: 计费服务、提醒链与界面通路（P9）

> spec 007 plan 阶段契约之三。定义支付单服务、续费提醒链、订阅到期流转接线、Filament 计费页与 API 通路的契约面。回溯 FR-002/007/008/009/010/011/012/013/014 + US1/US3/US4 + SC-004/SC-005/SC-006。

## 1. 支付单服务（FR-002 / 012 / 013）

```php
namespace App\Domain\Payment;

final class PaymentInvoiceService
{
    public function __construct(
        private readonly PaymentChannelRegistry $channels,
        private readonly PlanService $plans,
    ) {}

    /**
     * 发起升级/续费：生成或复用待支付单（同租户 + 同目标套餐 至多 1 笔非终态单，US4-3）。
     *
     * @throws \RuntimeException 未知 plan_code（不降级，constitution V）
     * @throws PaymentChannelException 通道不可用（不产生半状态支付单，spec Edge）
     */
    public function create(int $tenantId, string $planCode, ?string $channelCode = null): PaymentInvoice;

    /** 过期清扫：非终态且 expires_at < now → expired（命令 billing:expire-invoices 调用）。 */
    public function expireStale(?int $tenantId = null): int;

    /** 本租户支付单倒序（时间倒序：金额/状态/创建时间，FR-009）。 */
    public function listForTenant(int $tenantId, int $limit = 20): Collection;

    /** 当前待支付单（若有；UI 引导复用，US4-3）。 */
    public function pendingFor(int $tenantId, ?string $planCode = null): ?PaymentInvoice;
}
```

| 规则 | 约定 |
|---|---|
| `ref` 生成 | `'pi_' . strtolower((string) Str::ulid())`（26 字符 ULID），全局唯一（DB unique 兜底） |
| 金额来源 | `PlanService::planConfig($tenant)['price_monthly']` → `number_format(...,8,'.','')` 字符串；**浮点禁入比较与运算**（FR-011） |
| 计费周期 | 本期仅月度（`price_monthly`）；年付不在本 spec（spec Assumptions） |
| 建单事务 | 先落 `pending` 行 → 再调通道 `createInvoice()` → 回填 `channel_invoice_id`/`pay_address`/`pay_uri`/`meta`；通道失败 → **删除刚落的行**（或标记并抛，保证无半状态单；实现期取删除，简单可测） |
| 待支付单唯一性 | 查询 `status IN ('pending','processing','partial')`；存在则**原样返回既有单**并刷新展示倒计时（不堆叠） |
| 过期 | 命令 `billing:expire-invoices`（签名风格对齐 `billing:refresh-status`），调度 `*/5 * * * *` 或并入 `billing:renewal-reminders`（实现期二选一，见 §3） |
| 过期后到账 | 不自动激活：状态 `expired` → `manual`（FR-012）；仅人工线下处理 |
| 部分支付 | 保持 `partial`，有效期内补齐至足额 → 正常确认（Q2 决策）；过期未补齐 → `expired`/`manual` |
| 退款 | **不提供自动退款**（FR-014）；列表仅展示，人工线下处理 |

## 2. 订阅接线契约（FR-006 / FR-008）

```php
namespace App\Domain\Payment;

final class PaymentActivationService
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * 支付确认 → 激活/续期（唯一入口）。幂等：条件更新命中失败即返回 false，不重复接线。
     * 见 webhook-contract.md §4 的固定执行序。
     */
    public function activate(PaymentInvoice $invoice, string $paidAmount, string $eventType): bool;
}
```

**接线映射（复用 P3，不新造状态）**：

| 场景 | 动作 | 依据 |
|---|---|---|
| free → pro 首购 | `provision($tenantId, 'pro', trialDays: 0)` → 订阅 `active`、`tenants.plan_code='pro'`、`tenants.status='active'` | FR-006 / US1 |
| 到期续费 | 同上一路径（新建 active 订阅行、周期顺延），**不产生双重计费** | US3-3 |
| `past_due` / `suspended` 期间补缴 | 同上路径天然恢复（`provision` 已把 `tenants.status` 置 `active`） | FR-008 / US3-3 |
| `canceled` 后重新购买 | 同上路径（新订阅行），无特殊分支 | FR-012 |

**未续费流转（既有机制零改动，FR-008）**：`SubscriptionService::refreshStatus()` + 调度 `billing:refresh-status`（每日 00:05 UTC，`routes/console.php#L17-20`）继续承担 `trialing/active → past_due → （宽限 `plans.past_due_grace_days`）→ tenants.status=suspended`。本 feature **只新增提醒**，不改状态机。

## 3. 续费提醒契约（FR-007）

```php
namespace App\Domain\Payment;

final class RenewalReminderService
{
    /**
     * 扫描订阅，产生到期前 7/3/1 天提醒行（幂等：唯一索引 (subscription_id, ends_at, days_before, channel)）。
     * @return int 本次新建的提醒行数
     */
    public function dispatch(?int $tenantId = null, ?CarbonImmutable $nowUtc = null): int;
}
```

| 项 | 约定 |
|---|---|
| 判定 | 最新订阅行 `status ∈ ('active','trialing')` 且 `ends_at` 未来；`days_left = ends_at->diffInDays(now)`，命中 `config('payment.renewal_reminder_days')` = `[7,3,1]` |
| 落库 | `payment_renewal_reminders`（每档每渠道一行，`status=queued`） |
| 发送 | `RenewalReminderJob`（`app/Jobs/`，镜像 `RiskNotificationJob` 三段式：读 queued → `NotifierChannel::send()` → 置 sent/failed），队列 `notifications`，`tries=3`，backoff `[10,30,90]` |
| 载荷 | 新增 `RenewalReminderPayload`（`app/Domain/Notification/Payloads/`），字段：套餐名、到期日（UTC）、剩余天数、应付金额（bcmath 字符串）、**一键续费链接**（Filament 计费页绝对 URL + `plan_code`） |
| 渠道 | `config('payment.reminder_channels')`，须在租户套餐 `channels` 白名单内（`PlanService::allowsChannel()`；`log` 系统渠道恒放行，constitution V） |
| 命令 | `billing:renewal-reminders`（含过期清扫，或拆 `billing:expire-invoices`；实现期定） |
| 调度 | `routes/console.php`：`dailyAt('00:10')->timezone('UTC')->withoutOverlapping(10)`（紧邻既有两条调度，同范式） |

## 4. 通知域复用（不新增渠道）

| 既有件 | 复用点 |
|---|---|
| `App\Domain\Notification\Contracts\NotifierChannel` | 渠道发送契约（`send(NotificationPayload): ?string`） |
| `App\Domain\Notification\Contracts\NotificationPayload` | 载荷 DTO（`title` / `fields` / `footer` / `context`） |
| `config('notifications.channel_classes')` | 渠道实现映射（log/telegram/wechat_work/mail） |
| `app/Jobs/RiskNotificationJob` | 结构镜像（新 Job 独立，**不改**既有 Job） |
| **新增** | `Payloads/RenewalReminderPayload.php`（仅此一件新增物） |

## 5. Filament 计费页契约（FR-009 / FR-010）

| 产出 | 路径 | 契约要点 |
|---|---|---|
| 计费页 | `app/Filament/Admin/Pages/Billing.php` | 导航组「计费」；展示当前套餐/周期起止/各计量用量与上限（**复用 `PlanService` + `QuotaGuard` + `TwoPhaseMeter::usedToday`**，不另算口径） |
| 用量看板 widget | `app/Filament/Admin/Widgets/QuotaUsageWidget.php` | 计量事件 `ai_call` / `order_placed` 日用量 + 资源数（交易所账户/策略实例/信号订阅） |
| 升级 Action | 同上 Page | 选套餐 → `PaymentInvoiceService::create()` → 展示 USDT 金额、收款地址、倒计时、二维码（`payUri`）；已有待支付单 → 引导至既有单（US4-3） |
| 支付单列表 | `app/Filament/Admin/Resources/PaymentInvoiceResource.php`（+ `Pages/ListPaymentInvoices.php`） | 时间倒序：金额/状态/创建时间；**只读**（禁 create/edit/delete）；租户隔离走 `BelongsToTenant` 自动 `TenantScope` |
| 隔离 | 全部 | 跨租户不可见（SC-006）；policy 仅 `viewAny`/`view` |

**SC-005 步数约束**：页面首屏即含「升级」按钮，点击后弹层直接出收款信息 —— 点击升级 → 选套餐 → 见收款信息 = 3 步内、1 分钟内。</br>
**不做的界面**：Invoice 展示页（税务语义）、对账报表、多币种切换（spec Assumptions 明确排除）。

## 6. API 通路契约（US1–US3 可先行验证，UI 后置）

挂在既有 `auth:sanctum + tenant` 分组内（`routes/api.php#L21-24`），`api.` 名前缀同惯例：

| 方法 | 路径 | 语义 |
|---|---|---|
| `GET` | `/v1/billing/summary` | 当前套餐、周期起止、各计量用量/上限（与计费页同数据源） |
| `POST` | `/v1/billing/invoices` | body `{plan_code}` → 生成/复用待支付单，返回 `ref`/金额/地址/`expires_at` |
| `GET` | `/v1/billing/invoices` | 本租户支付单，时间倒序（分页） |
| `GET` | `/v1/billing/invoices/{ref}` | 单笔详情（跨租户 → 404） |
| `POST` | `/v1/billing/webhook/{channel}` | 通道回调（**分组外**，见 `webhook-contract.md`） |

审计与隔离：所有查询经服务层显式 `tenant_id`（双保险：`BootstrapTenancy` 上下文 + 服务层参数），跨租户一律 404 而非 403（不泄露存在性）。

## 7. 命令契约汇总

| 命令 | 语义 | 调度 |
|---|---|---|
| `billing:renewal-reminders` | 产生 7/3/1 提醒行 + 投递 Job | 每日 00:10 UTC |
| `billing:expire-invoices` | 非终态过期单 → `expired`（可选并入上命令） | 每 5 分钟或每日 |
| `billing:refresh-status`（既有） | 订阅到期 → past_due → suspended | 每日 00:05 UTC（**零改动**） |
| `billing:fake-webhook` | dev-only：构造带签名的载荷并投递至本平台 webhook 端点（等价真实通道投递；非 local/testing 环境禁用） | 手动 |
| `poc:verify-payment` | 离线全链路断言（SC-001~006 平台侧），见 quickstart §5 | 手动 |

## 8. 配置契约

`config('payment')`（见 `data-model.md` §6.1）：`default_channel` / `invoice_ttl_minutes` / `renewal_reminder_days` / `reminder_channels` / `channels.{code}.{driver, ...}`。

**不新增** `config('plans')` 项（套餐与价格沿用 P3），**不修改** `config('notifications')` 结构（仅新增 Payload 类）。
