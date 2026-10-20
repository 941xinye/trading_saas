# Contract: 支付 webhook 端点与幂等语义（P9）

> spec 007 plan 阶段契约之二。定义通道回调的 HTTP 契约、验签、幂等与状态推进语义。回溯 spec FR-003/004/005/012 + US2 + SC-002/SC-003，以及 `docs/p9-btcpay-poc-plan.md` 阶段 B 的 PoC 断言口径。

## 1. 端点

```
POST /api/v1/billing/webhook/{channel}
Content-Type: application/json
BTCPay-Sig: sha256=<hex>        # btcpay 通道；fake 通道用 X-Fake-Sig
```

| 项 | 约定 |
|---|---|
| 注册位置 | `routes/api.php` **既有分组之外**独立注册（`Route::post('v1/billing/webhook/{channel}', ...)`），**不挂** `auth:sanctum` / `tenant` 中间件 |
| 鉴权 | **验签即鉴权**：通道回调无平台身份；`{channel}` 非法 → 404 |
| 租户 | 由 payload `ref` → `payment_invoices.tenant_id` 反查（服务端权威，**不接受**客户端 tenant 字段；PoC B3 红线） |
| CSRF | 该路由 MUST 加入 `bootstrap/app.php` 的 `validateCsrfTokens(except:)` 或 api 组天然豁免（实现期核对，避免回调用 419） |
| 幂等 | 以支付单 `ref` 为幂等键（FR-005） |
| 并发 | Redis 单飞锁 `tenant:{tid}:payment:webhook:{ref}`（`set($k,$v,'EX',15,'NX')`，phpredis 参数位顺序，constitution VII）——**非正确性依赖**，仅削峰 |

## 2. 处理流水线（固定顺序）

```
① 解析 {channel} → PaymentChannelRegistry::for(code)      未知 → 404
② channel->verifyWebhook(rawBody, headers)
      失败 → 401 + 落 payment_webhook_events(signature_valid=false, status=rejected) + 原始载荷，零状态变更
③ channel->parseWebhook(rawBody, headers)                   不可解析 → 200 + status=rejected + 审计
④ 定位支付单：ref 优先；缺失/不存在 → 200 + status=rejected + error 留痕（PoC B3，不重试）
      命中 → 回填 tenant_id / payment_invoice_id
⑤ 单飞锁（拿不到 → 200 + status=idempotent_skipped，不阻塞）
⑥ 事务内：PaymentActivationService::apply(invoice, event)   （见 §3）
      已处理/乱序丢弃 → 200 + status=idempotent_skipped
      成功 → 200 + status=processed + processed_key 写入 + tenant_id/foreign key 回填
      异常 → 事务回滚 → 500（让通道按 BTCPay 重试策略重投，FR-006 可重试性依赖此）
⑦ 释放锁
```

## 3. webhook → 支付单状态推进（FR-003）

**统一以「条件更新」表达，先更新后接线**（research R-003）：

```sql
-- 足额确认（含补齐后足额）
UPDATE payment_invoices
   SET status='confirmed', paid_amount_usdt=?, confirmed_at=NOW(), updated_at=NOW()
 WHERE id=? AND ref=? AND status IN ('pending','processing','partial');
-- affected=0 ⇒ 幂等跳过或终态拒绝，直接返回，不接线
```

| 事件类型 | 目标状态 | 条件（`WHERE status IN ...`） | 接线 |
|---|---|---|---|
| 支付中 / 确认数未达 | `processing` | `('pending')` | 否 |
| 部分支付（`0 < paid < amount`） | `partial` | `('pending','processing')` | 否 |
| 足额到账 | `confirmed` | `('pending','processing','partial')` | **是**（§4） |
| 过期（通道 `InvoiceExpired`） | `expired` | `('pending','processing','partial')` | 否 |
| 过期后足额到账（当前状态 `expired`） | `manual` | `('expired')` | 否（FR-012：不自动激活） |
| 超额到账 | `confirmed` | `('pending','processing','partial')` | 是（超额不退款不记账，仅列表展示供人工） |
| 任何终态再收到事件 | 不变 | — | 否（**只进不退**，US2-3 乱序防护） |

**乱序处理**：先 `confirmed` 后到达 `processing` → 条件更新 `WHERE status='pending'` 不命中 → `idempotent_skipped`；状态不回退。

## 4. 支付确认 → 订阅接线（FR-006）

`App\Domain\Payment\PaymentActivationService::activate(PaymentInvoice $invoice): bool`

```
DB::transaction:
  1. 支付单条件更新（§3，affected=1 才继续）      ← 幂等守卫（独立一次性标志 confirmed_at，constitution IV）
  2. SubscriptionService::provision($invoice->tenant_id, $invoice->plan_code, trialDays: 0)
     —— 复用 P3：租户 → active、plan_code 更新、subscriptions 新建 active 行；天然覆盖「suspended 恢复」路径
  3. payment_webhook_events.processed_key 写入 "{ref}:{event_type}"（唯一，至多 1 条 processed）
```

**事务边界**：通道 HTTP 与通知投递**一律在事务外**；事务内只做 DB 写入（research R-003）。任一步失败 → 整体回滚 → 支付单回原状态 → 上层 500 → 通道重投 → 重入本流程并成功，**不存在半激活**。

**配额刷新口径（实现期红线）**：`plan_code` 变更后 `PlanService::limit()`/`QuotaGuard` 下一次读取即按新套餐限额生效——**无需任何刷新动作，也不清零当日已用计数**（不追溯、不双计）。MUST NOT 为此新增缓存或清零逻辑。

**审计**：`payment_webhook_events` 承载原始载荷与处理结果；订阅侧事实由既有 `subscriptions` 行承载（不另造激活表）。

## 5. 响应码表（决定通道重试行为）

| 场景 | 码 | body | 是否重试 |
|---|---|---|---|
| 验签失败 | **401** | `{"ok":false,"reason":"invalid_signature"}` | 否（无效投递） |
| 未知 `{channel}` | 404 | `{"ok":false}` | 否 |
| 载荷不可解析 | 200 | `{"ok":false,"reason":"unparsable"}` | 否 |
| 定位不到支付单（脏数据/伪造 metadata） | 200 | `{"ok":false,"reason":"invoice_not_found"}` | 否（PoC B3） |
| 重复投递 / 乱序丢弃 / 锁冲突 | 200 | `{"ok":true,"result":"idempotent_skipped"}` | 否（SC-002 前提） |
| 首次处理成功 | 200 | `{"ok":true,"result":"processed"}` | 否 |
| 处理异常（DB 故障等） | **500** | `{"ok":false,"reason":"processing_error"}` | 是（FR-006 可重试） |

> 200 语义为「已确认收到并作出终局处理（含幂等跳过）」，用于让通道停止重试；401 表示签名不合法、通道不应重投。

## 6. 幂等断言口径（SC-002 / SC-003）

| 断言 | 位置 | 期望 |
|---|---|---|
| 同一 ref 重放 3 次 | `payment_invoices` | `confirmed_at` 非空且**只有一次迁移**；状态停在 `confirmed` |
| 同一 ref 重放 3 次 | `subscriptions` | 该租户新增 active 行**恰好 1 条**；`tenant.plan_code` 为目标套餐 |
| 同一 ref 重放 3 次 | `payment_webhook_events` | `WHERE processed_key='{ref}:{event_type}'` 计数 **= 1**（research R-006 的可断言证据） |
| 同一 ref 重放 3 次 | 配额 | 当日 Redis 计数与 `meter_events` **未增量**（配额刷新无副作用） |
| 错误签名重放 | 全部 | 状态零变更；`signature_valid=false` 的审计行 100% 留存（含原始载荷） |
| 并发同 ref（@redis） | `payment_invoices` | `confirmed_at` 仅一次迁移；锁冲突方返回 `idempotent_skipped` |

## 7. 安全红线

- 密钥（`webhook_secret` / `api_key`）来自 `.env`，**不入库不入 git**（`.env.example` 仅留空占位）。
- 验签 MUST 用 `hash_equals` 常量时间比较；MUST NOT 先解析 JSON 再验签（必须对原始字节验签）。
- 审计落库的 `raw_payload` MUST 原样保存（FR-004），即使验签失败。
- 请求体 MUST NOT 参与任何 SQL 拼接；`ref` 长度与字符集做白名单校验（防注入/超长）。
- 未知 `channel_code` 抛异常而非回落默认通道（**永不降级**，constitution V）。
