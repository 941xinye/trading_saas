# 海外节点部署 Runbook（market:feed 节点）

> P5.5 步骤 4 交付物。目的：在可直连 Binance 主网的海外轻量节点上运行进程 B（`market:feed`）
> 与队列 worker，销掉 P5 遗留的 B8 债务（WSL 出口被 Binance 封 IP，真实强平流无法验证）。
>
> 适用：新加坡 / 日本东京轻量 VPS，Ubuntu 22.04/24.04，UTC 时区。所有命令以 `www-data`
> 或普通部署用户执行，涉及 root 的步骤显式标注 `sudo`。

## 0. 节点选型与网络前置

- 机房优先新加坡（Binance AWS 东京/新加坡撮合边缘延迟低），确认厂商 IP 段**不在 Binance
  封禁名单**（部分廉价厂商的整段 IP 被风控）。开通后第一件事做出口探测：

  ```bash
  nc -vz fstream.binance.com 443      # 强平/行情 WebSocket
  nc -vz stream.binance.com 443       # 现货行情 WebSocket
  nc -vz api.binance.com 443          # 现货 REST（对账/下单）
  nc -vz fapi.binance.com 443         # U 本位合约 REST
  ```

  四条全部 `succeeded` 才继续；任一失败换机房/换 IP，不要在应用层绕过。

- 最低配置：1 vCPU / 2 GB RAM / 40 GB SSD；单机同跑 MySQL+Redis 建议 2 vCPU / 4 GB。
- 系统时区统一 UTC：`sudo timedatectl set-timezone UTC`（全链路时间口径，禁止本地时区）。

## 1. 运行时依赖

```bash
sudo apt update
sudo apt install -y php8.4-cli php8.4-mysql php8.4-redis php8.4-bcmath \
    php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip unzip mysql-client redis-tools \
    supervisor nginx cron
# Swoole（market:feed 与 octane 必需；用与 CLI 相同的 PHP）
sudo pecl install swoole && echo "extension=swoole.so" | sudo tee /etc/php/8.4/mods-available/swoole.ini
sudo phpenmod swoole
php -m | grep swoole   # 必须看到 swoole
```

## 2. 拓扑选择

| 拓扑 | 适用 | 要点 |
|---|---|---|
| **同机一体**（推荐入门） | 1–20 租户试运行 | MySQL/Redis 只绑 `127.0.0.1`；VPS 安全组只放 22/80/443 |
| **节点回连中心 DB/Redis** | 多节点 / 已有数据库主机 | 必须走 SSH 隧道或 TLS（stunnel/云内网），**禁止把 3306/6379 暴露公网**；`DB_HOST`/`REDIS_HOST` 指向隧道本地端 |

> 多节点跑多个 `market:feed` 时，策略实例分配必须互斥（同一实例只在一个节点运行），
> 本期靠人工分配 + `strategy_instances` 单实例语义保证；不做自动选主（Out of Scope）。

## 3. 部署步骤

> **一键部署（推荐）**：第 3–5 节的命令已脚本化为 [deploy/bootstrap-node.sh](../deploy/bootstrap-node.sh)，
> 按阶段幂等执行（重复跑不会覆盖已有 `.env` / supervisor / Nginx 配置）：
>
> ```bash
> sudo bash deploy/bootstrap-node.sh --stage=preflight          # 只检查，不改系统
> sudo bash deploy/bootstrap-node.sh --stage=all \
>   --repo=https://github.com/941xinye/trading_saas.git \
>   --domain=trade.example.com --with-db --redis-password='<强密码>'
> sudo bash deploy/bootstrap-node.sh --stage=verify             # 部署后自检
> ```
>
> 分阶段：`deps / code / env / database / supervisor / nginx / cron / verify`。
> 参数说明与常见报错处理见 [部署脚本使用文档](deploy-script-guide.md)。
> 手工部署仍可按本节与第 4、5 节逐步执行。

```bash
sudo mkdir -p /var/www/crypto-saas /var/log/crypto-saas
sudo chown -R $USER:www-data /var/www/crypto-saas /var/log/crypto-saas
cd /var/www/crypto-saas
git clone <repo-url> .
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

编辑 `.env`（`chmod 600 .env`）：

- `APP_ENV=production`、`APP_DEBUG=false`、`QUEUE_CONNECTION=redis`；
- `DB_*` / `REDIS_*` 按拓扑填写；生产 Redis 必须设 `REDIS_PASSWORD`；
- **`EXCHANGE_MASTER_KEY`（KEK）**：`php -r "echo bin2hex(random_bytes(32));"` 生成 64 位 hex，
  只存在于节点 `.env`，**绝不入库、不进 git、不进日志**。KEK 丢失 = 租户交易所凭据全部不可解；
  更换 KEK 等价于 DEK 轮换（本期未做热轮换，C4）。

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache
```

## 4. supervisor 托管（kill -9 自动拉起验收）

```bash
sudo cp deploy/supervisor/*.conf.example /etc/supervisor/conf.d/
# 逐个把 /var/www/crypto-saas、/usr/bin/php、user=www-data 按本机实际修改
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status        # octane / market-feed / worker-ai / worker-default / reverb 全 RUNNING
```

验收（步骤 4 验收 #1）：

```bash
sudo kill -9 $(pgrep -f "market:feed" | head -1)
sleep 12
sudo supervisorctl status market-feed   # 10 秒内重新 RUNNING
tail -n 50 /var/log/crypto-saas/market-feed.log   # 观察崩溃恢复日志（hydrate 未闭 bar/pending 核实）
```

日志轮转：在 `/etc/logrotate.d/crypto-saas` 为 `/var/log/crypto-saas/*.log` 配置
`daily rotate 14 missingok notifempty copytruncate compress`。

## 5. 调度（订阅刷新 + 账实对账）

Laravel 调度注册在 [routes/console.php](../routes/console.php)（UTC）：

| 时刻 | 命令 | 作用 |
|---|---|---|
| 每日 00:05 | `billing:refresh-status` | trialing/active 到期 → past_due；超 `plans.past_due_grace_days` 宽限 → suspended |
| 每 5 分钟 | `reconcile:run --period=5m` | 账实对账（只报告+暂停，**绝不自动改账**） |

挂系统 cron（schedule:work 与 crontab 二选一，推荐 crontab）：

```bash
echo "* * * * * www-data /usr/bin/php /var/www/crypto-saas/artisan schedule:run >> /dev/null 2>&1" \
  | sudo tee /etc/cron.d/crypto-saas
```

验收（步骤 4 验收 #2）：

```bash
php artisan schedule:list                       # 必须含 billing:refresh-status 与 reconcile:run 两条
php artisan billing:refresh-status             # 手工执行成功，无迁移时输出 No subscription status transitions.
php artisan reconcile:run --period=5m          # 无实例时正常退出；有实例时 reconciliation_reports 落 ok/drift 行
php artisan reconcile:status <instanceId>      # 查看最近 5 次对账与差异明细
```

过期订阅流转验证（在测试库演练）：构造 `trialing` 且 `ends_at` 昨天的订阅，连跑两次
`billing:refresh-status`：第一次订阅转 `past_due`，第二次（已过 3 天宽限口径）租户转
`suspended`；正式回归对应 `tests/Integration/Billing/SubscriptionLifecycleTest.php`。

## 6. B8 销债：真实强平流 120 秒实跑

网络探测（第 0 节）通过后：

```bash
php artisan poc:liquidation-demo --duration=120
```

证据归档（步骤 4 验收 #3，截图/日志保存到运维 wiki）：

1. 命令输出 `total` 持续增长（若非交易时段可延长到 300s 或加 `--symbols=BTCUSDT,SOLUSDT,XRPUSDT`）；
2. 真实事件入库：

   ```sql
   SELECT COUNT(*) FROM liquidation_events WHERE event_ts > UNIX_TIMESTAMP(NOW())*1000 - 600000;
   ```

3. 三档窗口 Redis 有数据（key 前缀 `liquidation:`，5m/15m/60m ZSET + bucket HASH）；
4. 强平热力 Map 接口（Filament/API）返回 topN 密集区，`distance_pct` 上正下负；
5. 归档后更新 README P5 区块「已知边界：Binance 主网网络阻塞」一条为「已在海外节点验证」。

## 7. 安全检查清单

- [ ] `.env` 权限 600，KEK 不在任何聊天/工单/日志中出现
- [ ] MySQL/Redis 仅监听 127.0.0.1 或隧道端，`ss -tlnp` 无 0.0.0.0:3306/6379
- [ ] 安全组仅放行 22（限源 IP）、80、443
- [ ] `APP_DEBUG=false`；Filament 后台仅经 HTTPS 访问
- [ ] 节点只放需要的交易所 API key 权限（现货交易，禁提现/禁合约）
- [ ] supervisor 五个程序（octane / market-feed / worker-ai / worker-default / reverb）RUNNING，logrotate 已生效，`schedule:list` 两条调度在列
- [ ] `.env` 已配 `BROADCAST_CONNECTION=reverb` + `REVERB_APP_ID/KEY/SECRET`，Admin 面板登录后可收到熔断告警实时推送（B7 前端订阅已接）

## 8. 真实链路验收总清单（OKX / Bybit / Bitget / Telegram / 对账）

> 2026-10-18 增补：把散落在 spec 001/002/003 quickstart、P5.5 待办与技术债表（A1/B6/B8）中的
> 「待真实链路」项收敛为一份清单。节点网络与 key 到位后**一次性按序执行**，逐项销账；
> 每项完成后同步 README 对应区块与技术债表。

### 8.0 前置：key 与网络

| 凭据 | 来源 | 配置 |
|---|---|---|
| OKX demo 三段（key/secret/passphrase） | OKX 模拟盘账户 | `EXCHANGE_OKX_TESTNET=true` + `EXCHANGE_OKX_KEY/SECRET/PASSPHRASE` |
| Bybit testnet 两段（key/secret） | testnet.bybit.com | `EXCHANGE_BYBIT_TESTNET=true` + `EXCHANGE_BYBIT_KEY/SECRET` |
| Bitget 主网三段（key/secret/passphrase，**现货交易权限、禁提现**，小额资金） | Bitget 主网 | `EXCHANGE_BITGET_KEY/SECRET/PASSPHRASE`（无 testnet 键，003 澄清决定 A） |
| Telegram Bot | @BotFather | `TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID` |
| 企微群机器人 | 群设置 → 群机器人 → 添加（任一群） | `WECHAT_WORK_WEBHOOK`（完整 webhook 地址） |
| 邮件告警收件 | 任意可收信邮箱 + SMTP 配置 | `NOTIFY_MAIL_TO` + 框架 `MAIL_*` |

出口探测（全部 `succeeded` 才继续，任一失败换机房，与第 0 节同规）：

```bash
nc -vz www.okx.com 443 && nc -vz ws.okx.com 8443          # OKX 主网（demo 同域）
nc -vz api-testnet.bybit.com 443 && nc -vz stream-testnet.bybit.com 443   # Bybit testnet
nc -vz api.bitget.com 443 && nc -vz ws.bitget.com 443     # Bitget 主网
```

> **开发机本地预检记录（2026-10-18）**：Windows 侧与 WSL 侧 curl 探测 OKX（www/ws.okx.com）、
> Bybit（api/api-testnet.bybit.com）、Bitget（api/ws.bitget.com）共 7 端点全部超时（000），
> 仅 Binance testnet 可达（200）——与坑 #23 同源（宽带出口对境外交易所端点被网络层阻断）。
> 结论：8.1 矩阵 11 项全部只能在海外节点执行，本地无任何可提前销账项，勿重复探测。

### 8.1 验收矩阵

> **半自动化入口（2026-10-22 增补）**：下表 11 项已脚本化为 [deploy/acceptance.sh](../deploy/acceptance.sh)，
> 在节点项目根执行 `bash deploy/acceptance.sh` 逐项跑批，自动判定 PASS/FAIL/SKIP 并把证据
> 日志与 summary.txt 落到 `storage/acceptance/<时间戳>/`。下单项（5/7/9）需 `.env` 配好 key 后
> 逐项确认（或 `--yes`）；第 1 项 kill -9 破坏性验收需显式 `--with-destructive`；第 11 项为
> 半自动（基线自动跑 → 人工制造 drift → 复跑）。全部 PASS 后按下表逐行销账。

| # | 验收项 | 命令 / 操作 | 通过标准 | 销账 |
|---|---|---|---|---|
| 1 | supervisor kill -9 自愈 | 第 4 节验收 #1 | 10s 内重新 RUNNING，hydrate 日志正常 | P5.5 待办 |
| 2 | 调度双命令 | 第 5 节验收 #2 | `schedule:list` 含 billing+reconcile | P5.5 待办 |
| 3 | B8 真实强平流 | 第 6 节 `poc:liquidation-demo --duration=120` | total 增长 + 事件入库 + 三档 Redis 有数 | 技术债 B8、README P5 已知边界 |
| 4 | OKX 公共行情 | `poc:verify-okx-feed --symbol=BTCUSDT --interval=1m --wait=120` | REST+WS PASS（001 SC-001/002） | — |
| 5 | OKX 模拟盘 + 真实下单 | 001 quickstart 场景 4/5 | `x-simulated-trading:1` 生效；1 笔市价单按 clientOrderId 查回成交（SC-003/004） | spec 001 T037 |
| 6 | Bybit 公共行情 | `poc:verify-bybit-feed --symbol=BTCUSDT --interval=1m --wait=120` | REST+WS PASS（002 SC-001/002） | — |
| 7 | Bybit testnet 下单 | 002 quickstart 场景 5 | 1 笔 testnet 市价单查回成交（SC-003） | spec 002 场景 5 |
| 8 | Bitget 公共行情 | `poc:verify-bitget-feed --symbol=BTCUSDT --interval=1m --wait=120` | REST+WS PASS；WS 连续帧证据佐证 bar 切换检测语义（003 R-006 验证指针） | 003 已知边界 |
| 9 | Bitget 主网小额下单 | 003 quickstart 主网小额场景 | 1 笔 BTCUSDT 小额市价单按 clientOrderId 查回成交，余额变动吻合（SC-003） | 003 已知边界 |
| 10 | 通知渠道实测（Telegram / 企微 / 邮件） | 分别配 `TELEGRAM_*` / `WECHAT_WORK_WEBHOOK` / `NOTIFY_MAIL_TO` 后触发一次信号通知（`poc:signal-setup` 探测或等真实信号） | 三渠道各收到消息；`notifications` 落库 sent | 技术债 B6、B5 |
| 11 | 账实对账 testnet 实跑 | Binance testnet 实例跑 `reconcile:run`，手工转入一笔制造 drift | drift/critical 报告正确、实例按配置暂停 | 技术债 A1 尾巴 |

证据归档：每项截图/日志/订单号存档；全部通过后 README 技术债表 B6/B8/A1 行销账、
P6 行「待 key」措辞移除、本清单各行标 ✅ 并注明日期。

### 8.2 验收执行顺序（节点到位后按 A→G 一次跑完）

> 前置：`sudo bash deploy/bootstrap-node.sh --stage=verify` 全绿（6 个 supervisor 程序 RUNNING、
> 6 条调度在列、`/api/v1/health` 返回 ok）。每阶段结束保留 `storage/acceptance/<时间戳>/summary.txt`
> 与截图；任一项 FAIL 时用 `--only=<n>` 单项重跑，不要全量反复跑。

#### 阶段 A：部署基线（无凭据，约 10 分钟）

| 步骤 | 命令 | 期望 |
|---|---|---|
| A1 | `sudo bash deploy/bootstrap-node.sh --stage=preflight` | 10 个交易所端点全 `OK`，时区 `UTC` |
| A2 | `bash deploy/acceptance.sh --only=1,2 --with-destructive` | item-1 PASS（kill -9 后 12s 内 RUNNING）、item-2 PASS |
| A3 | `sudo logrotate -f /etc/logrotate.d/crypto-saas && ls /var/log/crypto-saas/*.log.1` | 轮转文件生成 |

- item-1 FAIL：`sudo supervisorctl status market-feed`，看 `$LOG_DIR/market-feed.log` 是否 `FATAL`；
  常见原因是 PHP 路径、`swoole` 扩展或目录属主不对（脚本已统一替换，手工改过则重跑 `--stage=supervisor --force`）。
- item-2 FAIL：`php artisan schedule:list` 无输出 = `.env` 未生效或 `config:cache` 过期，跑 `php artisan config:clear`。
- A3 无 `.log.1`：logrotate 的 `copytruncate` 需进程有写权限，确认日志属主为 `--run-user`。

#### 阶段 B：行情与公开数据（无凭据，约 15 分钟）

```bash
bash deploy/acceptance.sh --only=3,4,6,8 --liq-duration=180
```

| 判定 | 期望 | 失败处理 |
|---|---|---|
| item-3 B8 强平流 | `db_count>0` 且 `liquidation:*` keys `>0` | 端点可达但无事件 = 非交易时段，延长到 300s 或换 `--symbols=BTCUSDT,ETHUSDT,SOLUSDT` |
| item-4 OKX 行情 | REST+WS PASS | 只有 REST PASS = WS 端口 8443 被拦，换机房 |
| item-6 Bybit 行情 | testnet REST+WS PASS | 检查 `EXCHANGE_BYBIT_TESTNET=true` 是否被 `config:cache` 冻结 |
| item-8 Bitget 行情 | REST+WS PASS | 记录连续帧日志作为 003 R-006 bar 切换语义证据 |

> 阶段 B 的证据日志本身就包含 WS 帧样本，归档时无需额外抓包。

#### 阶段 C：真实下单（需 key，逐项人工确认，约 20 分钟）

1. 在 `.env` 填入 OKX / Bybit / Bitget 凭据后执行 `php artisan config:clear`（`config:cache` 之后改 `.env` 不生效）。
2. 执行并逐项输入 `y` 确认：

   ```bash
   bash deploy/acceptance.sh --only=5,7,9
   ```

3. 每笔记录：交易所订单号、`clientOrderId`、成交价/数量、账户余额前后截图。
4. 安全红线：Bitget 主网密钥只开**现货交易**、禁提现、绑 IP 白名单；单笔 10 USDT；
   避开流动性差的时段，避免市价单滑点扩大。

| 失败码 | 含义 | 处理 |
|---|---|---|
| `-2015` | key/IP/权限识别层 | 核对 IP 白名单、key 是否现货权限 |
| `-1022` | 签名层 | 核对 secret、时间戳偏移（`date -u`）、`EXCHANGE_RECV_WINDOW` |
| 401/签名错误 | passphrase 错（OKX/Bitget） | 重新生成 API key |

#### 阶段 D：通知三渠道（item 10，约 10 分钟）

1. 配 `TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID`、`WECHAT_WORK_WEBHOOK`、`NOTIFY_MAIL_TO` + `MAIL_*`。
2. 执行 `bash deploy/acceptance.sh --only=10`：已配置渠道全部 PASS，并**人工确认实际收到消息**。
3. 落库核对：

   ```bash
   php artisan tinker --execute="echo DB::table('notifications')->where('created_at','>',now()->subMinutes(10))->count();"
   ```

- 未配置渠道抛异常并落 `failed_jobs` 属预期契约（配置缺失=failed），不是缺陷。
- 邮件失败先查 `MAIL_*` 与 25/465/587 端口出站策略。

#### 阶段 E：账实对账 drift（item 11，半自动，约 15 分钟）

1. 确认 Binance testnet 实例 running，取实例 ID 并导出：

   ```bash
   export RECONCILE_INSTANCE_ID=<instanceId>
   ```

2. `bash deploy/acceptance.sh --only=11`：阶段 1 先跑基线；脚本暂停等待人工制造 drift。
3. 在 testnet 手工转入/转出一笔资金后回车：阶段 2 复跑，期望最新报告 `status=drift|critical`，
   实例状态变为 `paused_by_reconcile`。
4. 资金对齐后再恢复实例：`php artisan strategy:schedule start <instanceId>`。
   **禁止在未平账前恢复**，否则下一轮对账会再次暂停并告警。

#### 阶段 F：ClickHouse 冷归档（spec 004，约 30 分钟）

```bash
# 1. 单节点 CH（仅监听本机，不暴露 8123 到公网）
docker run -d --name clickhouse --restart unless-stopped \
  -p 127.0.0.1:8123:8123 -v /var/lib/clickhouse:/var/lib/clickhouse \
  clickhouse/clickhouse-server:24.8

# 2. .env 配 ARCHIVE_CH_HOST/PORT/DATABASE/USER/PASSWORD 后刷新配置
php artisan config:clear

# 3. 建表 → 干跑 → 实跑一批 → 独立复检
php artisan archive:schema
php artisan archive:run kline_bar --max-batches=1
php artisan archive:run kline_bar --max-batches=1 --apply
php artisan archive:verify kline_bar
php artisan archive:status
```

| 判定 | 期望 |
|---|---|
| 建表 | `archive:schema` 四张 MergeTree 表创建成功，重复执行幂等 |
| 干跑 | 批次状态 `copied`，主库行数不变 |
| 实跑 | `--apply` 后主库该时间片行数下降，CH 行数等于归档数 |
| 复检 | `archive:verify` 三级校验全 `passed`，不一致批次 `failed` 并阻断删除 |
| 降级 | `docker stop clickhouse` 后历史查询仍可走热层，`/api/v1/health` 的 CH 为 optional 不返回 503 |

> 首次存量归档用 `--max-batches` 限速，确认无异常后再逐级放大；`apply` 前先跑一次备份。

#### 阶段 G：支付与回测（spec 007 / 008，约 40 分钟）

```bash
php artisan poc:verify-payment       # 35 项离线基线
php artisan poc:verify-backtest      # 55 项离线基线
```

- **BTCPay 真实收单**：按 [P9 前置 PoC 执行方案](p9-btcpay-poc-plan.md) 阶段 A/B/C 执行。
  节点需公网 HTTPS 域名（webhook 回调），`.env` 置 `PAYMENT_CHANNEL=btcpay` + `BTCPAY_*` 四段；
  验收 B1 真实 `InvoiceSettled` 验签通过、B2 重放 3 次只生效 1 次、B3 伪造 metadata 落 failed。
- **回测真实数据**：

  ```bash
  php artisan backtest:run spot_grid BTCUSDT 5m \
    --from="2025-01-01" --to="2025-04-01" --sync \
    --params='{"lower_price":"60000","upper_price":"120000","grid_count":10,"investment_amount":"10000"}'
  ```

  记录耗时（目标 < 30s/年 5m）、9 项指标与净值曲线；与 testnet 实跑净值做对照（误差 < 1%）。

#### 销账与回填

全部阶段 PASS 后：

1. 汇总 `storage/acceptance/<时间戳>/summary.txt`，把 §8.1 表逐行标 ✅ + 日期 + 证据路径；
2. 更新 README「技术债与已知边界」：B6（Telegram）、B8（真实强平流）、A1 尾巴、P6「待 key」措辞；
3. 更新 `docs/architecture.md` 对应阶段状态（spec 004 CH 冒烟、spec 007 真实收单、spec 008 真实净值对照）；
4. 把本次新增踩坑按格式追加到 [pitfalls.md](pitfalls.md)（先更新该文件再同步项目记忆）。

## 9. 回测引擎（spec 008）运维要点

### 9.1 Worker 配置

`deploy/supervisor/worker-backtest.conf.example` 已提供：`--queue=backtest --tries=1`、
`numprocs=1`（CPU 密集，单进程足够）、`--max-time=3600` 防内存累积、
`stopwaitsecs=120` 等待长任务优雅退出。

安装：

```bash
sudo cp deploy/supervisor/worker-backtest.conf.example /etc/supervisor/conf.d/worker-backtest.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status worker-backtest   # RUNNING
```

### 9.2 崩溃收割调度

`routes/console.php` 已注册：`backtest:reap-stale` 每 5 分钟扫描，
running 且 `updated_at` 停滞超 `backtest.stale_after_sec`（默认 240s）→ 标 failed。

验证：

```bash
php artisan backtest:reap-stale   # 无匹配时输出 "reaped 0 stale backtest run(s)"
```

### 9.3 并发上限

`config/backtest.php` concurrency 按套餐差异化：free=1 / pro=2 / enterprise=4。
超限拒绝（BacktestConcurrencyException），不排队。调整上限后需 `config:clear`。

### 9.4 回测发起

```bash
# 异步（默认）
php artisan backtest:run spot_grid BTCUSDT 1h --from="2024-01-01" --to="2024-06-01" \
  --params='{"lower_price":"40000","upper_price":"50000","grid_count":10,"investment_amount":"10000"}'

# 同步调试（--sync，不依赖队列）
php artisan backtest:run spot_grid BTCUSDT 1h --from="2024-01-01" --to="2024-06-01" \
  --params='{"lower_price":"40000","upper_price":"50000","grid_count":10,"investment_amount":"10000"}' --sync
```

### 9.5 故障排查

| 症状 | 排查 |
|---|---|
| 回测 pending 不动 | `supervisorctl status worker-backtest` 是否 RUNNING；`queue:work --queue=backtest` 手动跑一次看报错 |
| 回测 failed 无 error_message | 看 `storage/logs/laravel.log`；检查 K 线数据覆盖率是否 < `backtest.min_coverage_pct` |
| 同一策略重复创建失败 | 单飞锁未释放（`redis-cli KEYS "tenant:*:backtest:lock:*"`），等 TTL 120s 或手工 DEL |
