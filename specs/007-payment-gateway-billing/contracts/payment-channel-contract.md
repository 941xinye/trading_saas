# Contract: 支付通道抽象层（P9）

> spec 007 plan 阶段契约之一。定义 `App\Domain\Payment` 的通道抽象、DTO 与注册表；FR-001「新增通道只允许纯增量接入」由本契约的形态保证（新通道 = 实现接口 + `config/payment.php` 注册一行，零改动既有通道与订阅接线代码）。
>
> 范式镜像既有 `app/Domain/Exchange/Contracts/ExchangeAdapter.php` + `app/Domain/Exchange/ExchangeManager.php`（接口 + 按 code 路由 + 未知 code 抛异常）。

## 1. 通道契约

```php
namespace App\Domain\Payment\Contracts;

interface PaymentChannel
{
    /** 通道标识，与 payment_invoices.channel_code / config('payment.channels') 键一致。 */
    public function code(): string;

    /**
     * 创建通道侧发票。
     *
     * @throws PaymentChannelException 通道不可用/配置缺失/应答非法（不产生半状态支付单，spec Edge）
     */
    public function createInvoice(PaymentInvoiceRequest $request): PaymentInvoiceData;

    /**
     * webhook 验签（纯计算，无 IO）。
     * 失败 MUST 返回 false（调用方据此 401 + 落审计），不得抛异常吞掉判定。
     */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /**
     * 规范化 webhook 事件。MUST NOT 在此做状态迁移或写库（纯解析）。
     *
     * @throws PaymentChannelException 载荷无法解析为已知事件
     */
    public function parseWebhook(string $rawBody, array $headers): WebhookEvent;
}
```

**决策：不含主动查单 `fetchInvoice`**。本期终态由通道 webhook 覆盖（BTCPay 对「过期后到账」同样投递）；主动对账兜底作为**后续运维扩展点**显式登记，避免先造无消费方的接口（research R-001）。新增该动作属纯增量，不构成破坏性变更。

## 2. DTO（一文件一类，constitution VII）

```php
namespace App\Domain\Payment\Dto;

final readonly class PaymentInvoiceRequest
{
    public function __construct(
        public int $tenantId,
        public string $ref,               // 平台支付单号（写入通道 metadata，回读防伪造）
        public string $planCode,
        public string $symbol,            // 'USDT'（本期常量）
        public string $chain,             // 'TRC20'（Q1 决策）
        public string $amount,            // bcmath 字符串（decimal(20,8)）
        public string $description,       // 展示文案，如 "专业版 月度订阅"
        public int $ttlMinutes,           // 支付有效期
    ) {}
}

final readonly class PaymentInvoiceData
{
    public function __construct(
        public string $channelInvoiceId,
        public string $payAddress,        // 收款地址（展示给租户）
        public ?string $payUri,           // 支付 URI / 二维码内容
        public string $amount,            // 通道侧金额复核值（bcmath 字符串，MUST 与请求一致否则拒建单）
        public ?string $expiresAt,        // 通道侧过期时间（UTC ISO-8601）
        public array $raw = [],           // 建单原始响应摘要（落 payment_invoices.meta）
    ) {}
}

final readonly class WebhookEvent
{
    public function __construct(
        public string $eventType,         // InvoiceSettled / InvoiceProcessing / InvoiceExpired / fake.settled ...
        public ?string $ref,              // 平台单号（通道 metadata 回读；取不到为 null）
        public ?string $channelInvoiceId, // 通道侧发票 id
        public ?string $paidAmount,       // 实收金额（bcmath 字符串；无金额事件为 null）
        public ?string $currency,         // 'USDT'
        public array $raw,                // 原始载荷（落审计）
    ) {}
}

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Partial = 'partial';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case Manual = 'manual';

    public function isTerminal(): bool; // confirmed|expired|manual
}
```

## 3. 通道注册表与管理器

```php
namespace App\Domain\Payment\Contracts;

interface PaymentChannelRegistry
{
    /**
     * @throws \RuntimeException 未知 channel_code 或 driver 未配置（**绝不降级**，constitution V）
     */
    public function for(string $code): PaymentChannel;

    /** @return string[] 已注册通道 code */
    public function codes(): array;

    public function default(): PaymentChannel;   // config('payment.default_channel')
}
```

实现 `App\Domain\Payment\PaymentChannelManager`：读 `config('payment.channels.{code}.driver')` → `app($driverClass)`；缺 code 或 driver 未实现 `PaymentChannel` → 抛异常。绑定为 **bind 非 singleton**（Octane 长驻禁 static 缓存，沿用 `AppServiceProvider.php#L39-L52` 惯例）。

## 4. Fake 通道（离线轨道，本期交付）

`App\Domain\Payment\Channels\FakePaymentChannel`

| 方法 | 行为 |
|---|---|
| `code()` | `'fake'` |
| `createInvoice()` | 返回确定性 `channelInvoiceId = 'fake_' . $request->ref`、固定假地址 `TFAKE...`、`payUri`；`expiresAt` = now + ttl |
| `verifyWebhook()` | 与固定 secret（`config('payment.channels.fake.secret')`，测试可注入）做 `hash_hmac('sha256', $rawBody, $secret)` 比对（常量时间） |
| `parseWebhook()` | 解析测试构造的 JSON `{event, ref, invoice_id, paid_amount, currency}` |

**红线**：`allowed_env = ['local','testing']`，在其它环境被解析（`default()` 或显式 code）时**抛异常**，防「假通道收单」资损事故（research R-004，与 constitution V 防降级同源）。

## 5. BtcPay 通道（真实轨道，本类交付；真实链路待节点）

`App\Domain\Payment\Channels\BtcPayChannel`

| 方法 | 行为 |
|---|---|
| `createInvoice()` | `POST {base_url}/api/v1/stores/{store_id}/invoices`（`Http` facade，超时 10s），body 含 `amount`/`currency`/`metadata:{ref,tenant_id,plan_code}`；返回 `id` → `channelInvoiceId`，`checkoutLink`/`addresses` → `payUri`/`payAddress` |
| `verifyWebhook()` | 读 `BTCPay-Sig` 头（形如 `sha256=...`），`hash_hmac('sha256', $rawBody, $webhook_secret)` + `hash_equals`（**常量时间**，FR-004） |
| `parseWebhook()` | 事件名取 `type` 字段；`ref` 取 `metadata.ref`（缺失 → null → 上层落 `rejected`，PoC B3）；`paidAmount` 取事件金额字段；`raw` 存全文 |

**离线可验部分**：验签（纯函数）+ 事件解析（固定报文样本）均有 Unit 测试，**无需联网**；联网部分（建单 HTTP、真实投递）由可用性标志守卫 skip，节点就绪后按 `docs/p9-btcpay-poc-plan.md` 阶段 A/B 重跑。

## 6. 与既有契约的关系

| 既有契约 | 复用/关系 |
|---|---|
| `App\Domain\Exchange\ExchangeManager` | 形态参照（按 code 路由、未知 code 抛异常）；支付域不依赖交易所域，二者零耦合 |
| `App\Domain\Notification\Contracts\NotifierChannel` | 提醒发送链复用（见 `billing-integration-contract.md` §4） |
| `App\Domain\Billing\SubscriptionService` | 激活/续期唯一入口（见 `billing-integration-contract.md` §2） |
| `App\Domain\Billing\Plans\PlanService` | `planExists()` 校验 `plan_code`，未知抛异常 |
| 配置 | `config('payment')` 新增；`config('exchange')` 零改动 |

## 7. 错误语义

| 场景 | 行为 |
|---|---|
| 未知 `channel_code` | 抛 `RuntimeException`（不降级） |
| 通道配置缺失 / 不可达 | `createInvoice` 抛 `PaymentChannelException`；上层返回明确错误且**不产生半状态支付单**（spec Edge） |
| Fake 于非 local/testing 环境 | 抛异常（红线） |
| 验签失败 | `verifyWebhook` 返回 false（不抛异常），由上层统一 401 + 审计 |
| 载荷不可解析 | `parseWebhook` 抛 `PaymentChannelException` → 上层 200 + `rejected` + 审计（不触发通道重试风暴） |
| 通道金额 ≠ 应付金额 | 建单即拒（复核），不落支付单 |
