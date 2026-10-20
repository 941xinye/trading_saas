# Quickstart: P9 支付网关 + 计费 UI

> spec 007 验证指南。分三层：**离线断言（无外部依赖，本机即可全跑）** + **集成（需 Redis/MySQL）** + **真实链路（需海外节点/BTCPay，本机不可达时按 skip 处理，坑 #23）**。
>
> 本机现状（2026-10-20）：Windows，无 Docker；Redis 6379 与 MySQL 3306 在 ServBay 未启动时不可达 → 需先启动 ServBay 再跑集成层。

## 前置条件

- PHP 8.4+ / Composer
- MySQL 8.4（新增三张表，`php artisan migrate`）
- Redis 7+（webhook 单飞锁、日配额计数；不可达时 `@redis` 用例 skip，其余照跑）
- **无需 BTCPay、无需节点、无需外网** 即可完成全部平台侧断言（Fake 通道）

## 1. 一键离线全链路断言

```bash
php artisan poc:verify-payment
```

预期：`ALL CHECKS PASSED`，覆盖建单 → 验签 → 状态推进 → 订阅激活 → 配额生效 → 幂等重放 → 伪造签名拒绝 → 提醒判定（SC-001~006 平台侧）。

## 2. 离线断言测试

```bash
# 支付单创建/唯一性/过期清扫 + bcmath 金额判定（足额/不足额/超额/过期到账）
php artisan test tests/Unit/Payment/PaymentInvoiceServiceTest tests/Unit/Payment/PaymentStatusMachineTest

# BTCPay 验签（HMAC-SHA256 正/负用例，纯函数无需联网）+ 未知通道抛异常
php artisan test tests/Unit/Payment/WebhookSignatureTest tests/Unit/Payment/PaymentChannelConfigTest

# 续费提醒 7/3/1 判定 + 同周期幂等
php artisan test tests/Unit/Payment/RenewalReminderServiceTest
```

预期：全部 PASS，无 skipped。

## 3. 集成测试（Fake 通道，需 MySQL）

```bash
# US1 全链路：建单 → fake webhook → confirmed → 订阅 active + 配额按新套餐放行
php artisan test tests/Integration/Payment/FakeChannelCheckoutFlowTest

# 同 ref 重放 3 次 → 激活 1 条、processed 事件 1 条、配额零增量（SC-002）
php artisan test tests/Integration/Payment/WebhookIdempotencyTest

# 错误签名 → 100% 拒绝 + 零状态变更 + 原始载荷审计留存（SC-003）
php artisan test tests/Integration/Payment/WebhookSignatureRejectionTest

# 乱序 webhook 不回退；unknown ref → rejected 且不影响其他事件（PoC B3 语义）
php artisan test tests/Integration/Payment/WebhookOrderingAndUnknownRefTest

# 宽限期内续费恢复 + 周期顺延；提醒 → past_due → 宽限 → suspended → 恢复时间线（SC-004）
php artisan test tests/Integration/Payment/RenewalRecoveryTimelineTest

# 计费页渲染 + 双租户零串扰（SC-005/SC-006）
php artisan test tests/Feature/Payment/BillingPageTest tests/Feature/Payment/BillingApiTest

# 并发同 ref 只处理一次 + 单飞锁（需 Redis）
php artisan test --group=redis tests/Integration/Payment/WebhookConcurrencyTest
```

预期：MySQL 在线全绿；Redis 停机时最后一条 skipped，其余 PASS。

## 4. 手工端到端（Fake 通道，无 UI）

```bash
# ① 建一笔 pro 升级支付单（租户 1）
php artisan tinker --execute="app(App\Domain\Payment\PaymentInvoiceService::class)->create(1,'pro','fake')->toArray()"
# 记下返回的 ref / amount_usdt / pay_address / expires_at

# ② 模拟通道回调（dev 命令构造并投递带签名的 webhook，等价于真实 BTCPay 投递）
php artisan billing:fake-webhook --ref=<ref> --event=fake.settled --paid=<amount_usdt>

# ③ 断言订阅已激活、套餐已切换
php artisan tinker --execute="DB::table('tenants')->find(1); DB::table('subscriptions')->where('tenant_id',1)->orderByDesc('id')->first()"
```

预期：`subscriptions` 新增一条 `status=active`、`tenants.plan_code='pro'` 且 `status='active'`；`payment_invoices.status='confirmed'` 且 `confirmed_at` 非空。

**幂等对抗（PoC B2 口径）**：对同一 `<ref>` 把 ② 连跑 3 次，断言 `subscriptions` 只多 1 条、`payment_webhook_events WHERE processed_key='<ref>:fake.settled'` 计数 = 1、当日配额计数未变。

**伪造签名**：把 ② 的 `--paid` 改掉并手工篡改签名字段（或直接 `curl` 不带 `X-Fake-Sig`）→ 断言 `401` + 支付单状态不变 + `signature_valid=false` 审计行已落。

## 5. 计费页点检（需 UI 会话）

1. 以租户用户登录 `/admin` → 侧边「计费」；
2. 首屏应显示当前套餐、周期起止、`ai_call`/`order_placed` 当日用量与上限（数字须与 `php artisan tinker` 读 `TwoPhaseMeter::usedToday()` 一致）；
3. 点「升级」→ 选目标套餐 → **3 步内**出现 USDT 金额/收款地址/倒计时（SC-005）；
4. 再点一次「升级」→ 应引导至既有待支付单（US4-3，不堆叠）；
5. 换第二个租户登录 → 支付单列表看不到第一个租户任何数据（SC-006）。

## 6. 续费失败流转时间线复现（SC-004，全离线可验）

```bash
# 构造一个 3 天后到期的订阅（tinker 直接写 subscriptions.ends_at）
php artisan billing:renewal-reminders          # 应产生 days_before=3 的提醒行
php artisan billing:refresh-status             # 到期后：active → past_due
php artisan billing:refresh-status             # 超过宽限：→ tenants.status=suspended（配额收缩，策略实例按 P3 语义停止）
php artisan billing:fake-webhook --ref=<new_ref> --event=fake.settled --paid=<amount>   # 宽限期内补缴
# 断言：subscriptions 回到 active、ends_at 顺延一个周期、reminders 未重复发送
```

预期：各转换时点与 `config('plans.past_due_grace_days')` 一致，误差 ≤ 1 个调度周期。

## 7. 真实 BTCPay 链路（**待海外节点，本机不可达**）

```bash
# 节点就绪后执行 docs/p9-btcpay-poc-plan.md 阶段 A/B/C：
#   A1/A2  docker ps 全 healthy + Store 建钱包 + Invoice 支付到 Settled
#   B1     真实 InvoiceSettled 推达本平台 /api/v1/billing/webhook/btcpay，签名校验通过
#   B2     同 ref 重放 3 次仅一次生效
#   B3     metadata 缺失/伪造（验签过但 tenant 不存在）→ 事件落 rejected + 明确错误
#   C1/C2  active → 到期未付 → past_due → suspended → 补缴 → 恢复 active 完整时间线
# 本平台侧准备：.env 设 PAYMENT_CHANNEL=btcpay + BTCPAY_* 四项；隧道（ngrok/cloudflared）暴露 webhook 端点
```

**当前状态**：轨道 B（`BtcPayChannel` 类 + 验签纯函数单测）随本期交付；**真实投递验收**在节点就绪后执行，届时 README P9 注记追加「BTCPay PoC 已验证（日期）」。

## 8. 验收清单（对照 SC-001~006）

| SC | 验证方式 | 状态 |
|---|---|---|
| SC-001 全流程 ≤10min / 平台侧 ≤30s | `poc:verify-payment` + `FakeChannelCheckoutFlowTest`（平台侧耗时断言） | 本期离线 ✅ |
| SC-002 同 ref 重放 3 次仅生效一次 | `WebhookIdempotencyTest` + 手工 §4 | 本期 ✅ |
| SC-003 无效签名 100% 拒绝 + 审计留存 | `WebhookSignatureRejectionTest` | 本期 ✅ |
| SC-004 提醒→past_due→宽限→suspended→恢复 | `RenewalRecoveryTimelineTest` + 手工 §6 | 本期 ✅ |
| SC-005 计费页 ≤3 步 ≤1 分钟 | `BillingPageTest` + 手工 §5 | 本期 ✅ |
| SC-006 双租户零串扰 | `BillingPageTest` / `BillingApiTest` / `TenantScope` 隔离用例 | 本期 ✅ |
| （真实链路）单笔真实 USDT 收单闭环 | `docs/p9-btcpay-poc-plan.md` 阶段 A/B/C | **待节点** ⏳ |

## 9. 已知边界

- 收单链**仅 TRC20-USDT**（Q1 决策）；多链/EVM 属后续通道扩展。
- 不足额支付**有效期内可补齐**，不引入账户余额体系（Q2 决策）。
- 不提供自动退款（FR-014）；超额不记账不退款，人工线下。
- 过期后到账转 `manual`，**不自动激活**（FR-012）。
- 计费周期仅月度（DAL `plans.php` 既有 `price_monthly`）；不做年付/折扣/税额。
- 平台支付单是收款凭证，不含税务发票语义（spec Assumptions）。
- 通道主动查单（`fetchInvoice`）与对账报表**不在本期**（research R-001 扩展点）。
- `fake` 通道仅允许在 `local/testing` 环境启用（research R-004 红线），生产 MUST 显式 `PAYMENT_CHANNEL=btcpay`。
- 真实 BTCPay 部署、密钥管理与隧道不在本期代码范围（运营侧，见 PoC 方案 §1）。
