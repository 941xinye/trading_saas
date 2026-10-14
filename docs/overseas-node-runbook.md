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
sudo supervisorctl status        # octane / market-feed / worker-ai / worker-default 全 RUNNING
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
- [ ] supervisor 四个程序 RUNNING，logrotate 已生效，`schedule:list` 两条调度在列
