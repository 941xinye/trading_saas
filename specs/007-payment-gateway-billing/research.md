# Research: P9 支付网关 + 计费 UI

> spec 007 plan 阶段研究。目标：收敛 spec「Clarifications Resolved」之外的**实现级待定项**（支付通道抽象形态、支付单/事件表结构、支付确认→订阅接线的原子边界、真实通道与离线通道的双轨交付、webhook 验签与并发防重、续费提醒调度落点），为 data-model / contracts / plan 提供决策依据。所有决策回溯 spec.md FR-001~014 / SC-001~006 / US1~US4。
>
> 前置合规基线已定论（不重开）：BTCPay Server 自托管 USDT 收单 + 手工续费；备选 NOWPayments/BitPay/Binance Pay 留作通道扩展；Stripe 为远期并行线；支付宝/微信已排除。PoC 执行方案见 `docs/p9-btcpay-poc-plan.md`。

## R-001 支付通道抽象层形态：镜像既有 Exchange Adapter 范式

**Decision**：新增 `App\Domain\Payment\Contracts\PaymentChannel` 接口 + `PaymentChannelManager`（按 `code` 路由到 driver），落地方式**镜像**既有 `app/Domain/Exchange/Contracts/ExchangeAdapter.php` + `app/Domain/Exchange/ExchangeManager.php`（FR-001 的「纯增量接入」即由此保证：新通道 = 实现接口 + `config/payment.php` 注册一行，零改动既有通道与订阅接线代码）。

**接口最小面（3 方法 + 1 标识）**：

| 方法 | 语义 | 依据 |
|---|---|---|
| `code(): string` | 通道标识（`fake` / `btcpay`），与 `payment_invoices.channel_code` 一致 | FR-001 |
| `createInvoice(PaymentInvoiceRequest): PaymentInvoiceData` | 建通道发票，返回 `channelInvoiceId` + 收款地址/URI + 通道侧过期时间 | FR-002 |
| `verifyWebhook(string $rawBody, array $headers): bool` | 验签（BTCPay 为 `BTCPay-Sig` HMAC-SHA256） | FR-004 |
| `parseWebhook(string $rawBody, array $headers): WebhookEvent` | 规范化事件（ref / channelInvoiceId / 通道状态 / 实收金额字符串） | FR-004/FR-005 |

**Rationale**：
- 既有 Exchange 域已验证「接口 + Manager 按 code 路由 + 未知 code 抛异常」这套范式（`PlanService::allowsStrategy` 同思路的防降级），支付域复用同一心智模型，评审成本最低。
- 通道间**无共享状态**（spec Key Entities）：通道只做「建单 / 验签 / 解析」三件纯函数式动作，状态机与订阅接线全在平台侧，通道可整体替换而不动业务。
- **不含主动查单（`fetchInvoice`）**：本期依赖通道 webhook 覆盖终态（BTCPay 对 `InvoiceExpired`→`InvoiceSettled` 的过期后到账同样投递），主动对账兜底留后续运维扩展点。避免为「可能有用」而先造无消费方的接口（不预先抽象）。

**Alternatives**：
| 备选 | 结论 | 理由 |
|---|---|---|
| 接口 + Manager 按 code 路由（镜像 Exchange） | 采纳 | FR-001 纯增量；与既有一致 |
| 单一 `PaymentGateway` 大接口内 if/else 分通道 | 拒绝 | 违背 FR-001（新通道要改既有代码） |
| 每通道一个完整 Service（各自建单+接线） | 拒绝 | 状态机/订阅接线会被复制 N 份，且违反 FR-006 复用 P3 |

## R-002 支付单与事件表结构：两表 + 幂等锚在支付单

**Decision**：新增两张表 `payment_invoices`（支付单）与 `payment_webhook_events`（webhook 接收审计），**不新增**订阅/配额相关表（复用 P3 既有实体，spec Key Entities 明示）。

**关键结构决策**：

| 决策项 | 结论 | 理由 |
|---|---|---|
| 金额列类型 | `decimal(20,8)` + Eloquent `decimal:8` cast（读出即 string） | constitution II：bcmath 字符串域；与 P8「Decimal 逐列镜像」同口径；浮点禁入 |
| 平台单号 vs 通道发票号 | 双 id：`ref`（平台侧，唯一，幂等键与对外展示）+ `channel_invoice_id`（通道侧，可空，回读用） | BTCPay 发票 id 由通道生成，平台不能事先预知；两者一对多语义为零（1:1），需分别索引 |
| webhook 幂等锚点 | **支付单上的独立一次性列 + 事件表 `processed_key`**（见 R-006），**不用** 事件表 (`ref`, `event_type`) 唯一索引 | BTCPay 重试可能携带变化字段；幂等必须锚在「平台自己的业务事件」而非通道报文形态 |
| 待支付单唯一性 | 应用层保证：同租户 + 同目标套餐 最多 1 笔 `pending/processing/partial`；再次发起返回既有单（US4-3） | MySQL 无 partial unique index；条件与状态耦合，放服务层显式校验更可读 |
| 原始载荷 | `raw_payload` JSON 列存全文（含签名头） | FR-004 审计要求「含原始载荷」；对齐 `risk_notifications` 审计风格 |

**Alternatives**：把 webhook 事件与支付单合成一表（支付单上追 `last_webhook_raw`）→ 拒绝：重放/洪流会覆盖历史，审计不可追溯（FR-004 需逐次留痕）。

## R-003 支付确认 → 订阅接线：单事务 + 条件更新守卫，通道 IO 拒于事务外

**Decision**：`PaymentActivationService::activate(PaymentInvoice $invoice, string $paidAmount, string $eventType): bool` 的执行序固定为

```
[事务外] 验签 → parseWebhook → 按 ref/channelInvoiceId 定位支付单（显式 tenant_id，不依赖 TenantContext）
[事务内] 1. 条件更新支付单：UPDATE ... SET status='confirmed', paid_amount_usdt=?, confirmed_at=now()
            WHERE id=? AND status IN ('pending','processing','partial')
            —— affected=0 ⇒ 幂等跳过，直接返回（不抛异常、不重复接线）
         2. SubscriptionService::provision($tenantId, $planCode, trialDays: 0)（复用 P3，不新造状态）
         3. 写 payment_webhook_events processed 行（processed_key 唯一，见 R-006）
[事务外] 4. 队列投递续费/开通成功通知（可选，不阻塞）
```

**Rationale**：
- 条件更新是**独立一次性幂等守卫**（constitution IV：不复用会被正常流程改写的业务字段）——它同时承担「防重复激活」与「防并发」两职，且不依赖 Redis 可用性（Redis 停机时仍正确，Redis 锁只是削峰）。
- 事务边界**只包住 DB 写入**：通道 HTTP（验签是纯计算无 IO；建单 HTTP 在 WS3，不在本事务）与通知投递都在事务外，避免长事务占用连接（constitution III 的协程/连接边界精神）。
- 失败可重试且不半激活（FR-006）：任一步异常 → 整个事务回滚 → 支付单保持原状态 → webhook 端点返回 5xx → BTCPay 重试 → 再次进入本流程并成功。**不存在**「订阅已激活但支付单没确认」的中间态。
- `provision(trialDays: 0)` 直接落 `active`，并顺带把 `tenants.status` 置回 `active`、`plan_code` 更新 —— 这**天然复用** P3 的「suspended 恢复」路径（FR-008 宽限期内补缴恢复），无需新增恢复逻辑。

**配额「刷新」的确切口径（避免实现期造无谓缓存）**：P3 的配额是「限额实时读 `config/plans.php` + 当日用量计数在 Redis（`tenant:{tid}:meter:{event}:{UTCYmd}`）」两段结构（`TwoPhaseMeter` / `QuotaGuard`）。`tenants.plan_code` 一变，`PlanService::limit()` 下一次调用即返回新套餐限额 —— **刷新 = 无需任何额外动作**，且**不追溯/不清零当日已用计数**（不双计）。FR-006 所要求的「配额刷新」由此满足；实现期 MUST NOT 为该语义新增缓存或清零逻辑。

**Alternatives**：
| 备选 | 结论 | 理由 |
|---|---|---|
| 条件更新 + 单事务（采纳） | 采纳 | 幂等锚独立、Redis 无关、无半激活 |
| Redis 锁 + 「先查状态再更新」 | 拒绝 | 锁失效即有竞态（constitution IV 反面教材）；Redis 停机则全线不可用 |
| 事件表唯一索引做幂等 | 拒绝 | 通道报文形态可变（重试字段差异），锚点不稳；且无法阻止「已处理但接线失败」的重入 |

## R-004 真实通道依赖节点 → Fake 通道先行，双轨交付不阻塞

**Decision**：**先交付 `FakePaymentChannel`**，用它完成全部接线、状态机、幂等、事务边界与 UI 的测试；`BtcPayChannel` 与同一契约并行实现，其**真实**链路验证挂可用性标志（节点/BTCPay 不可达即 skip，与既有 56 skipped 同惯例，不阻塞 CI 基线）。

| 轨道 | 通道 | 可立即交付 | 节点就绪后补 |
|---|---|---|---|
| A（离线，本机可完成） | `FakePaymentChannel` | 建单/验签/解析/状态机/幂等/订阅接线/UI/命令全链路 | — |
| B（真实，待海外节点） | `BtcPayChannel` | 类实现 + 验签纯函数单测（HMAC-SHA256 正负用例，无需联网） | `docs/p9-btcpay-poc-plan.md` 阶段 A/B/C 重跑（A1/A2/B1/B2/B3/C1/C2） |

**Rationale**：本机（Windows，无 Docker）与 WSL 对境外端点普遍不可达（坑 #23 同源），真实通道无法在本地验证；但**契约、验签算法、幂等语义、状态机**全部可在 Fake 通道上按同一断言验证——真实通道替换时只换 driver，断言不变。
**Fake 通道的红线**：`config('payment.default_channel')` 默认 `fake`，且 **`fake` 不得在生产环境可通过配置静默启用**——生产切换 MUST 显式设置 `PAYMENT_CHANNEL=btcpay`；`FakePaymentChannel` 在被用于非 testing/local 环境时抛异常（防「假通道收单」事故，类比 constitution V 的防降级）。

**Alternatives**：先做 BTCPay 真实通道再补 Fake → 拒绝：本机不可验证，全部交付被节点阻塞，违背本轮「先推进本地可做项」的路线。

## R-005 webhook 验签与防重（含并发）

**Decision**：分三层，由强到弱：

1. **验签（FR-004，不可协商）**：BTCPay 走 `BTCPay-Sig` 头，`hash_hmac('sha256', $rawBody, $webhookSecret)` 与头值**常量时间比较**（`hash_equals`）；失败 → **401**，零状态变更，落 `payment_webhook_events`（`signature_valid=false` + 原始载荷）。密钥来自 `config('payment.channels.btcpay.webhook_secret')`（`.env`，不入库不入 git）。
2. **幂等（FR-005，DB 层）**：以支付单 `ref` 为幂等键 —— 由 R-003 的条件更新守卫承担；重复投递返回 **200**（让通道停止重试）+ 事件落 `idempotent_skipped`。
3. **并发防重（FR-005 并发分支）**：Redis 单飞锁 `tenant:{tid}:payment:webhook:{ref}`，`Redis::connection('default')->set($key, $token, 'EX', $ttl, 'NX')` —— **注意 phpredis 参数位顺序**（`set($k,$v,'EX',$ttl,'NX')`，与 `app/Domain/Tenancy/TenantKeyResolver.php#L97`、`app/Domain/Risk/Position/PositionRiskMonitor.php#L122` 既有写法一致，constitution VII）。锁**不是正确性依赖**（第 2 层已保证），仅作洪流削峰；拿不到锁直接返回 200 + 事件落 `idempotent_skipped`。

**状态机单调性（FR-003 / US2-3，乱序不回退）**：终态 = `confirmed | expired | manual`；`processing`/`partial` 为中间态。乱序到达 `processing`（晚于 `confirmed`）时**丢弃**——以「条件更新只接受非终态」实现，不需要额外状态表。

**端点与鉴权**：`POST /api/v1/billing/webhook/{channel}` **不挂** `auth:sanctum` 与 `tenant` 中间件（`routes/api.php#L21-24` 的既有分组是平台侧用户链路；通道回调无平台身份，**验签即鉴权**）。租户由 payload 里的 `ref` → `payment_invoices.tenant_id` 反查，**显式 tenant_id**，不依赖 `TenantContext`（与 `TwoPhaseMeter` 的 L25-26 注释同一理由，且 webhook 请求本无租户上下文）。参照 `payment_invoices.tenant_id` 写入审计与通知，杜绝客户端伪造（PoC 阶段 B 的 metadata 红线）。

**响应码约定**（决定通道重试行为）：

| 场景 | 码 | 后续 |
|---|---|---|
| 验签失败 | 401 | 通道不重试（BTCPay 视为无效投递）；落审计 |
| 已处理/幂等跳过/乱序丢弃 | 200 | 通道停止重试 |
| 验签过但无法定位支付单（脏数据/伪造 metadata） | 200 + `rejected` | 不重试；落审计与明确错误（PoC B3） |
| 业务处理异常（DB 故障等） | 500 | 通道重试（FR-006 可重试性依赖此） |

## R-006 幂等键的具体形态（DB 可表达性核查）

**Decision**：MySQL 8.4 无 partial unique index，采用**可空唯一列**方案：

- `payment_webhook_events.processed_key`（`string(120)`, nullable, **unique**；上界 = ref ≤29 + `:` + event_type ≤60，留余量）：仅在实际处理成功时写入 `"{ref}:{event_type}"`；未处理/拒绝/跳过时保持 NULL。MySQL 唯一索引对多个 NULL 视为互不相同 → **多个未处理事件行合法，同一 (ref,event_type) 的 processed 行至多 1 条**。
- 支付单侧守卫仍为 R-003 的条件更新（不依赖该列，二者互补：条件更新防重入接线，`processed_key` 提供「事件表只有 1 条 processed」的 PoC B2 可断言证据）。

**Alternatives**：`INSERT ... ON DUPLICATE KEY` 去重 → 拒绝：会把「审计留痕」误伤成「只留第一条」，与 FR-004 的逐次留痕冲突。

## R-007 续费提醒的调度与落点

**Decision**：新增 `payment_renewal_reminders` 表（记录每周期每档提醒的发送事实，**唯一索引** `(subscription_id, subscription_ends_at, days_before)` 承担幂等），新增

- 命令 `billing:renewal-reminders`（签名风格对齐既有 `billing:refresh-status`，`app/Console/Commands/BillingRefreshStatusCommand.php` 的薄命令范式）；
- 调度落点 `routes/console.php`，紧邻既有两条（L17-24），同风格：

```php
Schedule::command('billing:renewal-reminders')
    ->dailyAt('00:10')
    ->timezone('UTC')
    ->withoutOverlapping(10);
```

- 发送走既有通知域：新增 `RenewalReminderPayload`（`app/Domain/Notification/Payloads/`，与 `RiskNotificationPayload` 同构）+ 新增 `RenewalReminderJob`（`app/Jobs/`，镜像 `RiskNotificationJob` 的「读 queued 行 → `NotifierChannel::send()` → 置 sent/failed」三段式，`notifications` 队列）。

**Rationale**：
- 「到期前 7/3/1 天」是**日粒度**判定，`dailyAt` UTC 一次即可；`withoutOverlapping(10)` 沿用既有防重入。
- 幂等锚是独立业务事实（subscription 周期 + 档位），**不复用** `subscriptions.ends_at` 改写（constitution IV）；续费后 `ends_at` 顺延 → 新周期自然产生新提醒行（不会漏发，也不会重发）。
- 提醒载荷含**一键续费入口**（FR-007）：Filament 计费页绝对 URL + 目标 `plan_code`（`fields` 放纯文本，符合 `NotificationPayload` 的 Telegram/Log 渲染约定，`NotificationPayload.php#L19-30`）。

**Alternatives**：把提醒混进 `risk_notifications` 表 → 拒绝：该表语义是熔断/对账告警（`circuit_breaker_event_id` 关联），混入计费提醒会污染既有查询与审计口径。

## R-008 Filament 计费页的租户隔离与构成

**Decision**：Admin 面板（`→path('admin')`，`AdminPanelProvider.php#L78` 已挂 `BootstrapTenancy`）新增

| 产出 | 类型 | 隔离机制 |
|---|---|---|
| `App\Filament\Admin\Pages\Billing` | 自定义 Page（导航组「计费」） | 页面内查询一律显式 `TenantContext::getTenantId()` |
| 当前套餐/周期/用量看板 | Blade 区块 + `QuotaUsageWidget` | 复用 `PlanService` + `QuotaGuard` + `TwoPhaseMeter::usedToday`，**不另算口径** |
| `App\Filament\Admin\Resources\PaymentInvoiceResource`（只读列表 + Pages/ListPaymentInvoices） | Resource | 模型挂 `BelongsToTenant` → `TenantScope` 自动 `WHERE tenant_id=?`（`BelongsToTenant.php#L32-49`）；policy 仅 `viewAny/view`，禁 create/delete |
| 升级 Action（生成支付单/展示地址与倒计时） | Page Action | 走 `PaymentInvoiceService::create()`（服务层已强制租户参数） |

**Rationale**：既有 Resource（如 `StrategyInstanceResource`）均不手写 `where tenant_id`，隔离由 trait+scope 自动完成；本 feature 沿用同一机制，US4-2 的「仅可见本租户数据」与 SC-006 的「跨租户零串扰」由框架级 scope 自动化保障。
**用量看板口径**：**直接调用 P3 同一服务**（不复制一套计算），这是 FR-009「与 P3 计量口径一致」的机制性保证。

## R-009 状态机与金额精度口径（复核 constitution II / V）

**Decision**：

| 项 | 口径 |
|---|---|
| 支付单状态枚举 | `pending`（待支付）→ `processing`（支付中/确认数未达）→ `confirmed`（已确认激活）/ `partial`（部分支付，有效期内可补齐）/ `expired`（过期失效）/ `manual`（过期后到账、超额等需人工） |
| 终态 | `confirmed` / `expired` / `manual`（只进不退） |
| 金额比较 | 一律 `bccomp` / `bcsub`（8 位小数），**声明比较**：`bccomp(paid, amount, 8) >= 0` → confirmed；`0 < paid < amount` → partial；`paid == 0` 保持 pending/processing |
| 超额 | 照常 confirmed 激活；`paid_amount_usdt` 记录实收，超额部分**不退款不记账**（spec Edge），仅由列表展示供人工线下处理 |
| 过期后到账 | `expired` 收到足额 webhook → `manual`（不自动激活，FR-012）；**特例**：过期后到账但当期无其他有效单时，仍须人工介入，不自动补激活 |
| 未知 `channel_code` / 未知 `plan_code` | **抛异常，绝不降级**（constitution V / FR-002） |

**Rationale**：把「足额/不足额/超额/过期」四类判定收敛为**一句 bcmath 比较 + 状态迁移表**，实现期无分支歧义；SC-002/SC-003 的断言据此可直接写死。

## R-010 Q1 / Q2 决策回填（保留推翻入口）

| 项 | 决策 | 理由 | 推翻入口 |
|---|---|---|---|
| Q1 收单链 | **仅 TRC20-USDT** | 手续费最低（约 1 USDT/笔）、交易用户持有率最高、BTCPay 支持成熟；`payment_invoices.chain='TRC20'` 单值 | 通道抽象层已按 code 路由，多链 = 新 channel 实现 + 配置一行；需改 `chain` 枚举时在 WS1 migration 前推翻 |
| Q2 不足额支付 | **有效期内可补齐** | 不激活、状态 `partial`、保留待支付；补齐至足额后按正常确认流程激活；过期未补齐 → `expired`/`manual`。**不引入账户余额体系** | 若要改为「不足额即作废重发」只需改 R-009 判定表一行；余额体系属新 feature，不在本期 |

## R-011 测试策略与不可达依赖处理

| 层 | 内容 | 分组 |
|---|---|---|
| Unit | 支付单创建/唯一性/过期；状态机单调性；BC 金额判定（足额/不足额/超额/过期到账） | default |
| Unit | BTCPay 验签（HMAC-SHA256 正/负用例，**纯函数无需联网**）；通道注册表未知 code 抛异常 | default |
| Unit | 续费提醒 7/3/1 判定 + 幂等 | default |
| Integration（Fake 通道） | US1 全链路：建单 → fake webhook → confirmed → 订阅 active + 配额按新套餐放行 | default |
| Integration（Fake 通道） | 同 ref 重放 3 次 → 激活/续期 1 次、配额 1 次（SC-002） | default |
| Integration（Fake 通道） | 错误签名重放 → 100% 拒绝 + 零状态变更 + 审计（SC-003） | default |
| Integration（Fake 通道） | 乱序 webhook 不回退；unknown ref → rejected 不影响他人 | default |
| Integration（@redis） | 同 ref 并发投递只处理一次 + 单飞锁 | redis |
| Integration | 宽限期内续费恢复 active + 周期顺延；提醒→past_due→grace→suspended→恢复时间线（SC-004） | default |
| Feature | 计费页渲染 + 双租户隔离（SC-005/SC-006）；API 通路跨租户拒绝 | default |
| Integration（可跳过） | 真实 BTCPay 端到端（节点/隧道不可达 → skip，带可用性标志） | default |
| Smoke | `poc:verify-payment` 离线全链路断言（SC-001~006 平台侧） | smoke |

> constitution VI：PHPUnit 12 静态 `#[DataProvider]`；Redis 用例 `@group redis`（沿用 `tests/Concerns/ChecksRedisAvailability`）；持久化字段走 Domain 层公开方法；Feature 用 `RefreshDatabase`；tearDown 按 `ref`/`tenant_id` 精确清理新增三表，try/catch 包住且先于 `parent::tearDown()`。

**不可达依赖处理**：本机（Windows，无 Docker）与 WSL 对境外端点不可达是**已知常态**（坑 #23）——真实 BTCPay 用例 MUST 以可用性标志 skip，不得让基线变红；BTCPay 侧验收统一由 `docs/p9-btcpay-poc-plan.md` 阶段 A/B/C 在节点就绪后承担（含 A1/A2 部署冒烟、B1 真实验签、B2 重放 3 次幂等、B3 metadata 伪造拒收、C1/C2 状态机时间线）。

## 风险与未决项

| 项 | 风险 | 缓解 |
|---|---|---|
| 通道契约凭「想象」定形 | 真机接入时发现接口不够用（如需要主动查单/退款字段） | 契约只保留建单/验签/解析三件必需动作；主动对账作为后续扩展点显式登记（R-001） |
| webhook 报文形态与 Fake 不一致 | BTCPay 字段路径/大小写与假设不同 | `parseWebhook` 单测 + 节点就绪后 B1 重跑；真实报文样例入 `payment_webhook_events` 审计可回溯调整 |
| 幂等断言口径含糊 | 「事件表只有 1 条 processed」无法证伪 | `processed_key` 可空唯一列使断言可直接写成计数 =1（R-006） |
| 提醒重复/漏发 | 续费顺延后误判档位 | 唯一索引锚 (subscription_id, ends_at, days_before)；顺延即新周期（R-007） |
| 假通道误入生产 | 用 fake 收单造成资损 | 非 local/testing 环境使用 fake 抛异常 + 生产须显式 `PAYMENT_CHANNEL=btcpay`（R-004） |
| 事务内夹带通道 HTTP | 长事务 + 失败难重试 | 通道 IO 一律拒于事务外（R-003） |
| 配额「刷新」被误解为清零 | 实现期造无谓缓存/清零逻辑 | R-003 明确口径：限额实时读取，无需动作、不追溯 |
| 真实链路无节点 | 交付被阻塞 | 双轨交付（R-004）；离线轨道先完成全部断言 |
