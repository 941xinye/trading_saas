# P9 前置：BTCPay Server 支付 PoC 执行方案

> 2026-10-18 立项。目的：在 P9 正式启动前，用最小成本验证「BTCPay Server 自托管 USDT 收单 + 手工续费」基线方案的两条核心链路——**收单确认**与 **webhook 幂等接线**，确保 P9 启动时合规与链路都已验证，只剩 UI。
> 背景决策见 README 路线图 P9 前置注记（Stripe 受限品类不首发、支付宝/微信排除、Coinbase Commerce 已关停、BTCPay 自托管为零费率无 KYC 基线）。

## 1. 环境要求

| 项 | 要求 | 本机现状（2026-10-18 预检） |
|---|---|---|
| Docker + docker compose | BTCPay 官方 docker-deployment | Windows/WSL 均未安装（WSL 12G 内存 / 237G 盘，够用） |
| 网络 | testnet 选举区块与 BTCPay 镜像拉取 | 开发机出口对境外普遍受限，建议海外节点或本地代理环境执行 |
| 磁盘 | testnet 全节点约 50-80GB（BTC）；USDT 走 BTC testnet Omni/Liquid 或直接用 BTCPay 内置的测试链 | 预留 100GB |
| PHP 侧 | 无新依赖，webhook 接收复用现有 Octane HTTP + 队列 | 就绪 |

**部署位置建议**：与真实链路验收（runbook 第 8 节）同一海外节点部署，一次解决两件事；或本地装 Docker Desktop 后用 regtest 模式（免网络依赖、出块即时）。

## 2. PoC 范围（做什么 / 不做什么）

**做**：
1. BTCPay testnet（或 regtest）实例部署，建 Store，开 BTC/USDT 测试币种
2. 创建一笔测试 Invoice 并完成链上支付确认
3. 接收 webhook（InvoiceSettled 事件），验签 + 幂等处理
4. webhook → P3 订阅状态机接线验证：settled → 手工续费语义的自动流转

**不做**（留 P9 正式阶段）：Filament 计费页、Invoice 展示页、用量看板、多币种、真实主网收单、汇率/税额、对账报表。

## 3. 执行步骤

### 阶段 A：部署与收单冒烟

```bash
# 海外节点 / 本地 docker 环境
git clone https://github.com/btcpayserver/btcpayserver-docker.git
cd btcpayserver-docker
# testnet 最小化部署（禁用无关服务省资源）
export BTCPAY_HOST="btcpay.example.com"    # 或本地 IP
export NBITCOIN_NETWORK="testnet"
export BTCPAYGEN_CRYPTO1="btc"
export BTCPAYGEN_ADDITIONAL_FRAGMENTS="opt-save-storage"
. ./btcpay-setup.sh -i
```

验收 A1：`docker ps` 全容器 healthy；BTCPay 管理界面可登录。
验收 A2：Store → Wallets → 导入 testnet 钱包（热钱包即可）；从 testnet faucet 取测试 BTC；创建一笔 Invoice 并支付；等待确认后 Invoice 状态变 **Settled**。

### 阶段 B：webhook 幂等接线（核心）

1. BTCPay Store → Settings → Webhooks：配置指向本平台 `POST /api/v1/billing/btcpay/webhook`（测试环境可用 ngrok/cloudflared 隧道），勾选 `InvoiceSettled`，记录自动生成的 secret。
2. 平台侧最小接收端（P9 正式实现的前身，PoC 期可先做成命令行可触发的事件落库）：
   - **验签**：`BTCPay-Sig: sha256=...` 头，用 secret 做 HMAC-SHA256 比对，失败 401。
   - **幂等**：以 `invoiceId + deliveryId` 为 ref 落 `payment_webhook_events`（PoC 期可先复用 meter_events 的 ref 幂等思路建临时表或日志断言），**同 ref 重复投递只处理一次**——BTCPay 会自动重试未 2xx 的投递，幂等必须扛住重复。
   - **接线**：验签通过且首次处理 → 按 `invoiceId` 上的 metadata（`tenant_id` + `plan_code`，创建 Invoice 时由我方写入）调用 `SubscriptionService` 续费语义 → 断言订阅到期时间延后 / trial 转 active。
3. 幂等对抗测试：同一 webhook payload 手工重放 3 次，断言：订阅只续期一次、事件表只有 1 条 processed。

验收 B1：真实 InvoiceSettled 推达，验签通过。
验收 B2：重放 3 次仅一次生效（幂等 PASS）。
验收 B3：metadata 缺失/伪造（验签过但 tenant 不存在）→ 事件落库标记 failed + 明确错误，不影响其他事件处理。

### 阶段 C：与 P3 状态机对齐验证

- 已有语义映射：**BTCPay push 模式无代扣** → 到期前提醒 → 用户手工付款 → InvoiceSettled → 自动续费；未续费 → 既有 `billing:refresh-status` 调度走 `past_due → 宽限 3 天 → suspended`。
- 验证 C1：sandbox 一条完整时间线：active → 到期未付（手工触发 refresh）→ past_due → suspended → 补一笔 Invoice 支付 → webhook → 恢复 active。
- 验证 C2：确认 InvoiceSettled 在 suspended 状态下也能正确恢复订阅（对应 P3「续费恢复」路径）。

## 4. 与 P3 现有代码的接线点（P9 正式实现时的映射）

| BTCPay 概念 | 平台侧落点 |
|---|---|
| Invoice metadata（tenant_id, plan_code） | 创建 Invoice 时由我方服务端写入，webhook 回读，杜绝客户端伪造 |
| InvoiceSettled webhook | 新 `BillingWebhookController`（验签 + ref 幂等 + 分发） |
| 续费语义 | `SubscriptionService` 既有 provision/恢复路径复用，不新造状态 |
| 未付到期 | 既有 `billing:refresh-status` 每日 UTC 00:05 调度，零改动 |
| 审计 | webhook 原始报文 + 处理结果落库（对齐 risk_notifications 审计风格） |

## 5. 风险与注记

- **USDT 不是 BTCPay 原生主网币种**：主网 USDT 收单需 BTCPay 的 Liquid/侧链插件或换 NOWPayments 托管通道；**PoC 阶段用 BTC testnet 验证链路与幂等即可**，币种差异不影响 webhook 契约。主网币种选型（BTC 收单 vs USDT via 插件 vs 托管通道）留 P9 specify 决策。
- webhook 密钥与 BTCPay 管理凭据不入库不入 git；海外节点部署走 runbook 第 0 节同规（nc 探测 BTCPay 所需域名）。
- BTCPay 需要 SMTP 通知可关；`opt-save-storage` 分片可大幅省磁盘。
- PoC 全程不产生真实资金；testnet faucet 币有水龙头依赖，regtest 模式可作为 faucet 不可用时的替代。

## 6. 完成定义（PoC 销账标准）

A1 + A2 + B1 + B2 + B3 + C1 全部 PASS → README P9 注记追加「BTCPay PoC 已验证（日期）」，P9 行风险列移除「webhook 幂等」项；本文件归档为 P9 specify 的 implementation context。
