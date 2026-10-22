# 运维部署文档

> 数字货币交易 SaaS 平台生产部署与运维指南。涵盖基础设施、进程管理、数据库、
> 调度、监控、备份与故障排查。海外行情节点专属内容见
> [overseas-node-runbook.md](overseas-node-runbook.md)。

## 1. 架构概览

```
进程 A：Laravel Octane (Swoole HTTP)
├── REST API (Sanctum)        端口 8000
├── Filament 后台             端口 8000
├── Queue Worker              orders/aggregation/default + ai/notifications
├── Reverb WebSocket          端口 8080（租户实时推送）
└── Nginx 反向代理            80/443 → 127.0.0.1:8000

进程 B：MarketFeedWorker (artisan market:feed)
├── 交易所 WS 订阅（多租户去重）
├── K 线聚合（bar close → event → Queue）
├── 大户爆仓流订阅
└── 策略调度器（onTick/onBar 派发到协程池）

跨进程通信：Redis（共享状态）+ Laravel Queue（跨进程任务）。
```

## 2. 基础设施要求

### 2.1 硬件

| 角色 | 最低配置 | 推荐配置 |
|---|---|---|
| 全栈一体（≤20 租户） | 2 vCPU / 4 GB RAM / 80 GB SSD | 4 vCPU / 8 GB RAM / 160 GB SSD |
| 应用节点（进程 A） | 2 vCPU / 4 GB RAM / 40 GB SSD | 4 vCPU / 8 GB RAM |
| 行情节点（进程 B，海外） | 1 vCPU / 2 GB RAM / 40 GB SSD | 2 vCPU / 4 GB RAM |
| 数据库节点 | 2 vCPU / 4 GB RAM / 100 GB SSD | 4 vCPU / 8 GB RAM / 200 GB SSD |

### 2.2 软件依赖

- **OS**：Ubuntu 22.04/24.04 LTS（其他发行版需自行适配包名）
- **PHP 8.4**：cli、mysql、redis、bcmath、mbstring、xml、curl、zip、swoole
- **MySQL 8.4**：业务主库
- **Redis 7+**：phpredis 客户端，必须设密码
- **ClickHouse**（可选，P8 冷归档）：单节点 Docker 即可
- **Nginx**：反向代理 + TLS 终止
- **Supervisor**：进程托管与自动拉起
- **Composer 2.x**

### 2.3 网络

- 应用节点安全组：22（限源 IP）、80、443
- 数据库端口 3306/6379 仅监听内网或 127.0.0.1，**禁止公网暴露**
- 行情节点必须能直连 Binance/OKX/Bybit/Bitget 的 REST 与 WS 端点（见 runbook 第 0 节探测）

## 3. 部署步骤

### 3.1 代码与依赖

```bash
sudo mkdir -p /var/www/crypto-saas /var/log/crypto-saas
sudo chown -R $USER:www-data /var/www/crypto-saas /var/log/crypto-saas
cd /var/www/crypto-saas
git clone <repo-url> .
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
chmod 600 .env
```

### 3.2 环境变量

编辑 `.env`，生产环境关键项：

| 变量 | 说明 |
|---|---|
| `APP_ENV=production` | 生产环境 |
| `APP_DEBUG=false` | 关闭调试 |
| `LOG_STACK=json` | 结构化 JSON 日志（含 env/tenant_id/request_id） |
| `DB_*` | MySQL 连接 |
| `REDIS_PASSWORD` | **必须设密码** |
| `QUEUE_CONNECTION=redis` | 队列走 Redis |
| `CACHE_STORE=redis` | 缓存走 Redis |
| `EXCHANGE_MASTER_KEY` | KEK，`php -r "echo bin2hex(random_bytes(32));"` 生成，**绝不入库/不进 git/不进日志** |
| `ALERTS_ENABLED=true` | 启用告警 |
| `ALERTS_CHANNELS=log,telegram,wechat_work,mail` | 告警投递渠道 |
| `PAYMENT_CHANNEL=btcpay` | 生产 MUST 显式设 btcpay |

### 3.3 数据库迁移与缓存

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

ClickHouse 冷层建表（若启用 P8）：

```bash
php artisan archive:schema
```

## 4. 进程管理（Supervisor）

共 6 个进程，配置模板在 `deploy/supervisor/`：

| 进程 | 队列/端口 | 说明 |
|---|---|---|
| octane | :8000 | HTTP 入口（API + Filament） |
| market-feed | — | 行情/交易协程容器 |
| worker-default | orders,aggregation,default | 下单与 K 线聚合 |
| worker-ai | ai,notifications | AI 分析与通知外发 |
| worker-backtest | backtest | 回测与寻优（CPU 密集，单进程） |
| reverb | :8080 | WebSocket 实时推送 |

安装：

```bash
sudo cp deploy/supervisor/*.conf.example /etc/supervisor/conf.d/
# 逐个修改路径 /var/www/crypto-saas 与 /usr/bin/php 为本机实际值
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

自愈验收：

```bash
sudo kill -9 $(pgrep -f "market:feed" | head -1)
sleep 12
sudo supervisorctl status market-feed   # 10 秒内重新 RUNNING
```

## 5. Nginx 反向代理

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    root /var/www/crypto-saas/public;

    # 静态资源直接返回
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Octane 反代
    location @octane {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Reverb WebSocket
    location /app/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_read_timeout 3600s;
    }
}
```

## 6. 定时调度

Laravel 调度注册在 [routes/console.php](../routes/console.php)（UTC 时区）：

| 频率 | 命令 | 作用 |
|---|---|---|
| 每日 00:05 | `billing:refresh-status` | 订阅状态流转（到期 → past_due → suspended） |
| 每日 00:10 | `billing:renewal-reminders` | 续费提醒（到期前 7/3/1 天） |
| 每 5 分钟 | `reconcile:run --period=5m` | 账实对账（只报告+暂停，不自动改账） |
| 每 5 分钟 | `billing:expire-invoices` | 非终态过期支付单清扫 |
| 每 5 分钟 | `backtest:reap-stale` | 回测崩溃收割（running 停滞 > 240s → failed） |
| 每分钟 | `ops:alerts:check` | 告警评估（冷却去重） |

挂系统 cron：

```bash
echo "* * * * * www-data /usr/bin/php /var/www/crypto-saas/artisan schedule:run >> /dev/null 2>&1" \
  | sudo tee /etc/cron.d/crypto-saas
```

验证：

```bash
php artisan schedule:list
```

## 7. 监控与可观测性

### 7.1 健康检查

`GET /api/v1/health`（公开），overall=down 返回 503。负载均衡据此摘除节点。

### 7.2 指标

`GET /api/v1/metrics`（公开），Prometheus 文本格式。当前暴露：

| 指标 | 类型 | 标签 | 说明 |
|---|---|---|---|
| `trading_orders_total` | counter | tenant_id, side, status | 下单总量（按状态分桶） |
| `trading_signals_total` | counter | tenant_id, source, decision | 信号处理量（queued/skipped/executed/rejected/failed） |
| `trading_queue_backlog` | gauge | queue | 队列积压 |
| `trading_breaker_open` | gauge | tenant_id, instance_id | 熔断实例数 |

Prometheus 抓取配置：

```yaml
scrape_configs:
  - job_name: crypto-saas
    metrics_path: /api/v1/metrics
    static_configs:
      - targets: ['your-domain.com:443']
    scheme: https
```

### 7.3 告警

`config/alerts.php` 默认 4 条规则：

| 规则 | 类型 | 阈值 | 说明 |
|---|---|---|---|
| order_failure_rate_high | rate | > 10%（min_total=10） | 订单失败率过高 |
| queue_backlog_high | max | > 1000 | 队列积压 |
| breaker_open | sum | > 0 | 存在熔断实例 |
| signal_skip_rate_high | rate | > 50% | 自动跟单跳过率过高 |

告警通过 `ops:alerts:check` 每分钟评估，命中后经 `ALERTS_CHANNELS` 投递，
同规则 `ALERTS_COOLDOWN_MINUTES`（默认 15 分钟）内不重复通知。

### 7.4 日志

- `LOG_STACK=json` 输出每行一条 JSON，含 `env` / `tenant_id` / `request_id`
- 日志路径 `/var/log/crypto-saas/*.log`，supervisor 自带 100MB × 5 轮转
- 建议额外配置 logrotate：`daily rotate 14 missingok notifempty copytruncate compress`

## 8. 备份与恢复

### 8.1 MySQL

```bash
# 备份
mysqldump -u root -p crypto_saas --single-transaction --routines \
  | gzip > /backup/crypto_saas_$(date +%F).sql.gz

# 恢复
gunzip < /backup/crypto_saas_YYYY-MM-DD.sql.gz | mysql -u root -p crypto_saas
```

建议每日全量 + binlog 增量，保留 30 天。

**恢复演练记录（2026-10-22，首次演练 PASS）**：本地 ServBay MySQL 8.x 实测
`mysqldump --single-transaction --routines` 全量 → scratch 库还原 → 核对，
表数 39=39、migrations 无差异、关键表行数全一致（strategy_orders 47、signals 62、
tenants 2、strategy_instances 2、signal_subscriptions 3）。两个实操要点：

1. **禁用 shell 重定向接 dump**：PowerShell `>` 会写 UTF-16 污染 SQL 文件，
   必须用 `mysqldump --result-file=...`（Linux 下 `| gzip` 无此问题）。
2. **核对口径**：`information_schema.tables` 比表数 + 关键业务表精确 `COUNT(*)`；
   InnoDB 的 `table_rows` 是估算值，不能用于演练核对。

### 8.2 Redis

```bash
# RDB 快照（Redis 配置 save 规则自动触发，或手动）
redis-cli -a $REDIS_PASSWORD BGSAVE
# 备份 dump.rdb
cp /var/lib/redis/dump.rdb /backup/redis_$(date +%F).rdb
```

### 8.3 KEK（EXCHANGE_MASTER_KEY）

**KEK 丢失 = 租户交易所凭据全部不可解**。必须：

- 离线备份到密码管理器（如 1Password / Bitwarden）
- 至少 2 份异地副本
- 更换 KEK 等价于 DEK 轮换（本期未做热轮换）

## 9. 日常运维操作

### 9.1 发布更新

```bash
cd /var/www/crypto-saas
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo supervisorctl restart all
```

### 9.2 查看进程状态

```bash
sudo supervisorctl status
```

### 9.3 查看日志

```bash
tail -f /var/log/crypto-saas/market-feed.log
tail -f /var/log/crypto-saas/octane.log
tail -f /var/log/crypto-saas/worker-default.log
```

### 9.4 队列管理

```bash
php artisan queue:monitor orders,ai    # 实时监控积压
php artisan queue:retry all            # 重试失败任务
php artisan queue:flush                # 清空队列（慎用）
```

### 9.5 对账与风控

```bash
php artisan reconcile:run --period=5m           # 手动触发对账
php artisan reconcile:status <instanceId>       # 查看对账历史
php artisan circuit-breaker:list                # 查看熔断状态
php artisan circuit-breaker:reset <instanceId>  # 手动恢复熔断
```

> **对账范围（2026-10-22 起，spec 010）**：`reconcile:run` 覆盖**现货与合约**实例，
> 口径按 `exchange_accounts.market` 分流——现货走 getBalance 资产比对，合约走
> getPositions 签名净持仓比对（hedge 双向模式多腿合成，contracts C1）。
> 此前的现货护栏已由合约原生对账接管并移除（README 技术债 A5 已销）。

## 10. 故障排查

| 症状 | 排查步骤 |
|---|---|
| Octane 502 | `supervisorctl status octane`；查看 octane.log；确认 swoole 扩展已加载 |
| 行情无数据 | 确认 `market-feed` RUNNING；检查 Redis 连接；海外节点确认网络探测通过 |
| 队列积压 | `trading_queue_backlog` 指标；`supervisorctl status worker-*`；检查 Redis 内存 |
| 下单失败 | 查看 `failed_jobs` 表；检查交易所账户状态与余额；`trading_orders_total{status=failed}` 指标 |
| 熔断触发 | `circuit_breaker_events` 表；`trading_breaker_open` 指标；检查仓位与亏损 |
| 告警未通知 | 确认 `ALERTS_ENABLED=true`；检查 `ops:alerts:check` 调度是否运行；查看 `alerts:cooldown:*` Redis 键 |
| WebSocket 断连 | 确认 reverb RUNNING；检查 `REVERB_APP_ID/KEY/SECRET` 配置；检查 Nginx WebSocket 反代 |

## 11. 安全检查清单

- [ ] `.env` 权限 600，KEK 不在任何聊天/工单/日志中
- [ ] MySQL/Redis 仅监听内网，`ss -tlnp` 无 0.0.0.0:3306/6379
- [ ] 安全组仅放行 22（限源 IP）、80、443
- [ ] `APP_DEBUG=false`；全站 HTTPS
- [ ] 交易所 API key 仅授现货交易权限，禁提现/禁合约
- [ ] `LOG_STACK=json`，日志已配置轮转
- [ ] 定时调度 `schedule:list` 含全部 6 条命令
- [ ] `ops:alerts:check` 正常运行，告警渠道可达
- [ ] 数据库定时备份已验证可恢复
