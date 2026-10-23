# 部署与运维速查卡

> 三种角色各一页，按需直接跳到对应卡片。完整参数与排错见
> [部署脚本使用文档](deploy-script-guide.md)；验收矩阵见
> [海外节点 Runbook](overseas-node-runbook.md#8-真实链路验收总清单okx--bybit--bitget--telegram--对账)。

| 卡片 | 使用场景 | 目标耗时 |
|---|---|---|
| [卡 1 首次部署](#卡-1首次部署) | 新 VPS 从零到可验收 | 20–30 分钟 |
| [卡 2 日常更新](#卡-2日常更新) | 代码/配置变更上线 | 10 分钟 |
| [卡 3 故障恢复](#卡-3故障恢复) | 进程、依赖、对账异常 | 5 分钟止血 |

统一变量（下文命令默认已设置）：

```bash
APP=/var/www/crypto-saas
PHP=/usr/bin/php8.4
ART="sudo -u www-data $PHP $APP/artisan"
```

---

## 卡 1：首次部署

### 前置条件（任一不满足不要开始）

- [ ] Ubuntu 22.04 / 24.04，root 或 sudo
- [ ] 2 vCPU / 4 GB（同机跑 MySQL+Redis 建议），磁盘 ≥ 40 GB
- [ ] 时区 `UTC`：`timedatectl show -p Timezone --value`
- [ ] 4 个 Binance 端点可达：`fstream / stream / api / fapi.binance.com:443`
- [ ] 域名 A 记录已指向本机公网 IP（要 HTTPS 才需要）
- [ ] 已准备：`EXCHANGE_MASTER_KEY` 备份位置（离线）

### 执行

```bash
cd $APP

# 1) 只检查，不改系统（首次裸机无 nc 会提示，可先跑 deps）
sudo bash deploy/bootstrap-node.sh --stage=preflight

# 2) 全量部署
sudo bash deploy/bootstrap-node.sh --stage=all \
  --repo=https://github.com/941xinye/trading_saas.git \
  --domain=trade.example.com \
  --with-db --redis-password='<强密码>'

# 3) 自检
sudo bash deploy/bootstrap-node.sh --stage=verify
```

### 人工补齐（脚本不代填）

```bash
sudo -u www-data $PHP $APP/artisan config:clear
sudo nano $APP/.env
```

- [ ] 交易所：`EXCHANGE_TESTNET_KEY/SECRET`、`EXCHANGE_OKX_*`、`EXCHANGE_BYBIT_*`、`EXCHANGE_BITGET_*`
- [ ] 通知：`TELEGRAM_BOT_TOKEN/CHAT_ID`、`WECHAT_WORK_WEBHOOK`、`NOTIFY_MAIL_TO` + `MAIL_*`
- [ ] 支付（如启用）：`PAYMENT_CHANNEL=btcpay` + `BTCPAY_*`
- [ ] 归档（如启用）：`ARCHIVE_CH_*`
- [ ] 填完执行 `sudo bash deploy/bootstrap-node.sh --stage=database`（重建 config cache）

### 完成标准

```bash
sudo supervisorctl status                    # 6 个 RUNNING
$ART schedule:list                           # 6 条在列
curl -fsS http://127.0.0.1:8000/api/v1/health | grep -E '"status":"(ok|degraded)"'
ls -l $APP/.env                              # -rw------- www-data
```

- [ ] 上述 4 条全绿（`degraded` 仅代表 CH/队列 optional 项异常，可继续）
- [ ] `bash deploy/acceptance.sh --only=1,2 --with-destructive` PASS
- [ ] `EXCHANGE_MASTER_KEY` 已离线备份（丢失=全部租户凭据不可解）

### 卡住时先查这 5 条

| 现象 | 先做什么 |
|---|---|
| 端点不可达 | 换机房/换 IP，不加代理绕过 |
| Swoole 未加载 | 确认 `--php-bin` 与 `pecl` 用的 PHP 同版本 |
| supervisor FATAL | `sudo supervisorctl tail -100 market-feed stderr` |
| health 503 | 看返回体 `checks.database` / `checks.redis` |
| `.env` 读不到 | `chown www-data:www-data .env && chmod 600 .env` |

---

## 卡 2：日常更新

### 0. 变更前确认

- [ ] 当前无大批手动平仓操作
- [ ] 记录当前版本：`git -C $APP log --oneline -1`
- [ ] 重要变更前备份：`sudo mkdir -p /var/backups/crypto-saas && mysqldump -uroot -p --single-transaction crypto_saas > /var/backups/crypto-saas/crypto_$(date +%F_%H%M).sql`
- [ ] 变更窗口内 `$ART ops:kill-switch status` 为预期状态

### 1. 更新代码与依赖

```bash
# 停交易与异步消费（HTTP 保留，队列在 Redis 中不会丢，恢复后自动排空）
sudo supervisorctl stop market-feed worker-default worker-ai worker-backtest

cd $APP
sudo bash deploy/bootstrap-node.sh --stage=code
sudo bash deploy/bootstrap-node.sh --stage=database
```

- 只改 `.env`：跑 `--stage=env` 后再跑 `--stage=database`（否则 `config:cache` 不更新）
- `--stage=code` 用 `git pull --ff-only`；有本地改动会中止，**不要**在节点上 `git reset --hard`

### 2. 重启进程（顺序固定）

```bash
sudo supervisorctl restart worker-default worker-ai worker-backtest
sudo supervisorctl restart reverb octane
sudo supervisorctl start market-feed
```

先 worker 后行情：避免 bar 闭合并发时旧 Job 打到新代码。

### 3. 验证（3 分钟）

```bash
sudo supervisorctl status
$ART schedule:list | head -20
curl -fsS http://127.0.0.1:8000/api/v1/health | grep -E '"status":"(ok|degraded)"'
tail -n 50 /var/log/crypto-saas/market-feed.log
$ART reconcile:run --period=5m
```

- [ ] 无 `FATAL` / `BACKOFF`
- [ ] 日志无 `Call to undefined` / `Class not found`
- [ ] 策略实例已 `hydrate`（行情进程日志可见）

### 4. 回滚（2 分钟）

```bash
cd $APP
git checkout <上一个稳定 commit>
composer install --no-dev --optimize-autoloader
sudo -u www-data $PHP artisan config:cache
sudo -u www-data $PHP artisan route:cache
sudo supervisorctl restart worker-default worker-ai worker-backtest reverb octane
sudo supervisorctl start market-feed
```

> 迁移为向前兼容设计，日常回滚**不要**执行 `migrate:rollback`；涉及数据结构变更时走备份恢复。

---

## 卡 3：故障恢复

### 0. 先止血（30 秒）

```bash
$ART ops:kill-switch on --reason="incident $(date -u +%FT%TZ)" --actor="oncall"
$ART ops:kill-switch status
```

> kill switch 只停**开新仓**，平仓与 reduceOnly 始终放行。先止血再定位，不要在交易运行中调试代码。

### 1. 定位（按顺序）

```bash
curl -fsS http://127.0.0.1:8000/api/v1/health | head -c 800
sudo supervisorctl status
sudo ss -tlnp | grep -E '3306|6379|8000|8080'
df -h / && free -m
tail -n 100 /var/log/crypto-saas/market-feed.log
tail -n 100 $APP/storage/logs/laravel.log
```

### 2. 分型处理

| 故障 | 判定 | 处理 |
|---|---|---|
| 进程退出 | `supervisorctl` 显示 `FATAL` | `supervisorctl tail -100 <name> stderr` 看首行错误；修配置/依赖后 `start` |
| 行情反复重启 | `market-feed` 起来即退 | 查 swoole 扩展与 PHP 路径；确认日志目录可写；重跑 `--stage=supervisor --force` |
| MySQL 不可用 | health `checks.database` failed | `systemctl status mysql`；恢复后 `$ART reconcile:run --period=5m` |
| Redis 不可用 | health `checks.redis` failed | 此时 kill switch 写入也会失败、读取按未开启处理（fail-open），直接 `supervisorctl stop market-feed` 止血；恢复 Redis 后再走正常流程 |
| 交易所不可达 | 行情无数据、下单超时 | `nc -vz fapi.binance.com 443`；换出口/机房，不在应用层代理 |
| 对账暂停实例 | 实例 `paused_by_reconcile` | `$ART reconcile:status <id>`；资金对齐后再 `$ART strategy:schedule start <id>` |
| 仓位风控触发 | `paused_by_position_risk` | `$ART risk:status <id>`；补保证金或减仓后 `$ART risk:recover <id>` |
| 熔断 OPEN | `$ART risk:status <id>` | 等冷却转 HALF_OPEN 探测；紧急可 `$ART risk:recover <id>` |
| 磁盘写满 | `df -h /` ≥ 90% | 清 `storage/logs`、旧备份、`/var/log/crypto-saas`；确认 logrotate 生效 |
| 队列堆积 | health `checks.queue` failed | `$ART queue:failed` 看失败原因；修复后 `$ART queue:retry all`；必要时 `supervisorctl restart worker-default` |

### 3. 恢复顺序

```bash
# 1) 依赖先恢复：MySQL → Redis → ClickHouse（可选）
# 2) 进程：worker → octane/reverb → market-feed
sudo supervisorctl start worker-default worker-ai worker-backtest reverb octane
sudo supervisorctl start market-feed

# 3) 账实对齐
$ART reconcile:run --period=5m

# 4) 解除止血
$ART ops:kill-switch off --actor="oncall"
$ART ops:kill-switch status
```

- 有 drift/critical 未处理时**不要**解除 kill switch
- 有实例被对账暂停时，先平账再 `strategy:schedule start`

### 4. 证据与复盘（10 分钟内）

```bash
mkdir -p /tmp/incident-$(date -u +%Y%m%d-%H%M)
sudo supervisorctl status > /tmp/incident-*/supervisor.txt
tail -n 500 /var/log/crypto-saas/*.log > /tmp/incident-*/process.log
tail -n 500 $APP/storage/logs/laravel.log > /tmp/incident-*/laravel.log
$ART reconcile:run --period=5m > /tmp/incident-*/reconcile.txt
```

- [ ] 记录故障起止时间（UTC）、影响实例、是否有真实下单
- [ ] 记录 kill switch 开启/解除时间与操作人
- [ ] 新踩坑按格式追加到 [pitfalls.md](pitfalls.md)

### 5. 绝对不要做

- 不要用 `migrate:fresh` / `migrate:rollback` 处理生产故障
- 不要在未平账时恢复被对账暂停的实例
- 不要删除 `storage/logs` 后再重启（证据丢失）
- 不要在 Redis 不可用时反复重启全部进程（可能放大队列重复消费风险）
