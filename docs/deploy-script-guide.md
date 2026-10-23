# 海外节点部署脚本使用文档

> 对应脚本：[deploy/bootstrap-node.sh](../deploy/bootstrap-node.sh)
> 配套文档：[海外节点 Runbook](overseas-node-runbook.md)（拓扑、验收矩阵、销账流程）、
> [运维部署文档](operations.md)（进程/Nginx/监控/备份基线）、
> [部署与运维速查卡](deploy-quick-cards.md)（首次部署 / 日常更新 / 故障恢复一页纸）。
>
> 适用环境：Ubuntu 22.04 / 24.04 LTS，root 或具备 sudo 的部署用户。

## 目录

1. [脚本定位与设计约束](#1-脚本定位与设计约束)
2. [快速开始](#2-快速开始)
3. [参数说明](#3-参数说明)
4. [阶段说明](#4-阶段说明)
5. [典型使用场景](#5-典型使用场景)
6. [输出与退出码约定](#6-输出与退出码约定)
7. [常见报错处理](#7-常见报错处理)
8. [部署后校验清单](#8-部署后校验清单)
9. [重跑、覆盖与回滚](#9-重跑覆盖与回滚)
10. [安全注意事项](#10-安全注意事项)

---

## 1. 脚本定位与设计约束

脚本把 Runbook 第 3–5 节的手工步骤收敛为 8 个阶段，用于**新节点首次部署**与**版本更新后的重复部署**。

设计约束（理解这些能避免误用）：

| 约束 | 说明 |
|---|---|
| 幂等优先 | 重复执行不会覆盖已有 `.env`、supervisor 配置、Nginx 站点；需覆盖时显式 `--force` |
| 不自动建库 | `--with-db` 只安装并绑定 MySQL/Redis 到 `127.0.0.1`，**数据库与账号需手工创建** |
| 不碰租户数据 | 不执行 `migrate:fresh`、不执行 `db:seed`、不做数据回滚 |
| 密钥只生成一次 | `APP_KEY` / `EXCHANGE_MASTER_KEY` / `REVERB_*` 仅在为空时生成，已存在不轮换 |
| 失败即停 | 安装/迁移类命令失败直接退出（`die`），不会继续后续阶段 |
| 阶段可拆分 | 任一阶段都可单独重跑，适合"只修某一层"的排障场景 |

---

## 2. 快速开始

### 2.1 首次部署（三步）

```bash
# 1) 前置检查：不改系统，先确认网络能直连交易所
sudo bash deploy/bootstrap-node.sh --stage=preflight

# 2) 全量部署（约 10–20 分钟，Swoole 编译占大头）
sudo bash deploy/bootstrap-node.sh --stage=all \
  --repo=https://github.com/941xinye/trading_saas.git \
  --domain=trade.example.com \
  --with-db --redis-password='<强密码>'

# 3) 部署后自检
sudo bash deploy/bootstrap-node.sh --stage=verify
```

### 2.2 已有代码、只补齐运行环境

```bash
sudo bash deploy/bootstrap-node.sh --stage=deps
sudo bash deploy/bootstrap-node.sh --stage=env --domain=trade.example.com
sudo bash deploy/bootstrap-node.sh --stage=database
sudo bash deploy/bootstrap-node.sh --stage=supervisor
sudo bash deploy/bootstrap-node.sh --stage=nginx --domain=trade.example.com --with-tls
sudo bash deploy/bootstrap-node.sh --stage=cron
sudo bash deploy/bootstrap-node.sh --stage=verify
```

### 2.3 部署完成后

```bash
cd /var/www/crypto-saas
bash deploy/acceptance.sh                     # 验收矩阵 11 项
bash deploy/acceptance.sh --only=4,6,8        # 只跑无凭据的行情项
```

---

## 3. 参数说明

### 3.1 参数总表

| 参数 | 默认值 | 作用 | 注意 |
|---|---|---|---|
| `--stage=<name>` | `all` | 执行阶段，见第 4 节 | 未知阶段退出码 2 |
| `--app-root=<path>` | `/var/www/crypto-saas` | 项目根目录 | supervisor/Nginx/cron 均按此路径渲染 |
| `--run-user=<user>` | `www-data` | PHP 进程与目录属主 | 用户必须已存在，否则 `sudo -u` 失败 |
| `--php-bin=<path>` | `/usr/bin/php8.4` | PHP 二进制 | **必须与装 Swoole 的 PHP 一致**，否则运行时报未定义函数 |
| `--log-dir=<path>` | `/var/log/crypto-saas` | 进程日志目录 | 会 chown 给 `--run-user` |
| `--domain=<host>` | 空 | Nginx `server_name`，同时写入 `APP_URL` / `REVERB_HOST` | 为空则跳过 Nginx 站点生成 |
| `--repo=<git-url>` | 空 | 代码仓库地址 | 仅在 `app-root` 非 git 仓库时使用 |
| `--with-db` | 关 | 安装 mysql-server + redis-server 并绑定 `127.0.0.1` | 不自动建库、不自动建账号 |
| `--redis-password=<pwd>` | 空 | 设置 Redis `requirepass` 并写入 `.env` | 生产强烈建议；密码含 `$` 时用单引号包裹 |
| `--with-tls` | 关 | certbot 申请 Let's Encrypt 证书并强制跳转 HTTPS | 需 `--domain` 且域名 A 记录已指向本机 |
| `--force` | 关 | 覆盖已存在的 supervisor / Nginx 配置 | 不影响 `.env` 已有值 |
| `--dry-run` | 关 | 只打印将执行的安装/写文件命令 | 检查类与自检类命令仍会实际执行 |
| `-h` / `--help` | — | 打印脚本头部用法 | — |

### 3.2 参数组合建议

| 场景 | 推荐组合 |
|---|---|
| 试运行 / 先看会做什么 | `--stage=all --dry-run` |
| 只有 HTTP，证书由外层 LB 终止 | `--domain=...`，不加 `--with-tls` |
| DB/Redis 在独立主机 | 不加 `--with-db`，部署后手工填 `DB_*` / `REDIS_*` |
| 已有 systemd/自管进程 | 分步执行 `--stage=deps`、`--stage=code`、`--stage=env`、`--stage=database`、`--stage=verify`，跳过 supervisor |
| 目录已有代码但配置需重刷 | 依次执行 `--stage=env`、`--stage=database`、`--stage=verify` |

> 注意：`--stage` 只接受单个值，不支持逗号分隔。多阶段请分多次执行。

### 3.3 `--stage=env` 会改写的键

每次执行都会**覆盖**以下键（这是生产基线的强制值）：

```
APP_ENV=production
APP_DEBUG=false
LOG_STACK=json
LOG_LEVEL=info
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
BROADCAST_CONNECTION=reverb
HEALTH_CHECK_CLICKHOUSE=true
ALERTS_ENABLED=true
APP_URL（仅当传了 --domain）
```

仅在为空时**生成**的键：

```
APP_KEY、EXCHANGE_MASTER_KEY
REVERB_APP_ID、REVERB_APP_KEY、REVERB_APP_SECRET
```

`--with-db` 时额外改写 `DB_HOST=127.0.0.1`，并在传了 `--redis-password` 时改写 `REDIS_PASSWORD`。

---

## 4. 阶段说明

| 阶段 | 需要 root | 主要动作 | 副作用 | 幂等性 |
|---|---|---|---|---|
| `preflight` | 否 | OS/时区/PHP 扩展/内存磁盘/10 个交易所端点探测 | 无 | 只读 |
| `deps` | 是 | 装 PHP 8.4 全家桶、Swoole、supervisor/nginx/cron；可选 MySQL/Redis | 改系统包与服务 | 可重复；已装则跳过编译 |
| `code` | 是 | clone 或 `git pull --ff-only`、`composer install --no-dev`、目录权限、`storage:link` | 改代码与 vendor | 可重复 |
| `env` | 是 | 创建/补齐 `.env`，生成密钥，`chmod 600` | 改 `.env` | 可重复；不轮换已有密钥 |
| `database` | 是 | `migrate --force`、`config:cache`、`route:cache` | 改数据库与缓存 | 可重复；迁移只跑未执行项 |
| `supervisor` | 是 | 渲染 6 份样板到 `/etc/supervisor/conf.d`，`reread/update` | 启停进程 | 已存在则跳过（`--force` 覆盖） |
| `nginx` | 是 | 生成站点、禁用 default、`nginx -t`、reload；可选 certbot | 改 Nginx | 已存在则跳过（`--force` 覆盖） |
| `cron` | 是 | 写 `/etc/cron.d/crypto-saas` 与 logrotate | 改系统调度 | 每次覆盖 |
| `verify` | 否 | 检查 `.env` 权限、6 条调度、6 个进程、健康接口 | 无 | 只读 |

### 4.1 各阶段关键行为

**preflight**
- 端点清单：`fstream/stream/api/fapi.binance.com`、`www.okx.com`、`ws.okx.com:8443`、
  `api-testnet.bybit.com`、`stream-testnet.bybit.com`、`api.bitget.com`、`ws.bitget.com`。
- 任一不可达只 `WARN` 不中断；但**上线前必须全通**，否则对应验收项必然失败。
- 首次裸机若未装 `nc`，会报"未安装 nc"并计入失败项；先跑 `--stage=deps` 再复跑本阶段。

**deps**
- Swoole 通过 `pecl install swoole` 编译，需 `php8.4-dev` + `libssl-dev` + `build-essential`。
- 已检测到 `swoole` 扩展则跳过编译，不会重复耗时。
- `--with-db` 只把 `mysqld` / `redis` 绑定到 `127.0.0.1`，不创建数据库。

**code**
- 目录已是 git 仓库时执行 `git fetch --all --prune && git pull --ff-only`。
- 有未提交改动会导致 `pull --ff-only` 失败并中止（保护现场，不自动 reset）。

**env**
- 先 `cp .env.example .env`（仅当不存在），再补齐键。
- `key:generate --force` 仅在 `APP_KEY` 为空时执行。
- 结束前重新 `chown` 给 `--run-user`：`sed -i` 会以 root 重建文件，不 chown 会导致 PHP 读不到配置。

**database**
- `migrate --force` 不带 `--seed`，不创建演示数据。
- 执行 `config:cache` / `route:cache` 后，**再改 `.env` 必须重跑本阶段或 `config:clear`**。

**supervisor**
- 安装 6 个程序：`octane`、`market-feed`、`worker-ai`、`worker-default`、`reverb`、`worker-backtest`。
- 把样板里的 `/var/www/crypto-saas`、`/usr/bin/php`、`user=www-data`、`/var/log/crypto-saas`
  替换为本机实际值。

**nginx**
- 生成 `sites-available/crypto-saas` 并软链到 `sites-enabled`，**删除 `sites-enabled/default`**。
- `/` 反代 8000；`/app`、`/apps` 反代 8080（Reverb WebSocket）。
- `--with-tls` 使用 `--register-unsafely-without-email`；如需证书到期通知，部署后手工重跑 certbot 并登记邮箱。

**cron**
- 写入：`* * * * * www-data /usr/bin/php8.4 <app-root>/artisan schedule:run`。
- 覆盖写，文件权限 644。

**verify**
- 检查 `.env` 600、调度 6 条、supervisor 6 个 RUNNING、`http://127.0.0.1:8000/api/v1/health`。
- 有 WARN 时**退出码仍为 0**，需人工看输出判断。

---

## 5. 典型使用场景

### 5.1 只想知道会执行什么

```bash
sudo bash deploy/bootstrap-node.sh --stage=all --dry-run
```

`--dry-run` 只拦截 `run/run_sh/env_set/artisan`；`preflight`、`verify`、`stat`、`curl`
仍会真实执行，因此会看到实际检查结果。

### 5.2 只换代码，不动进程

```bash
sudo bash deploy/bootstrap-node.sh --stage=code
sudo bash deploy/bootstrap-node.sh --stage=database
sudo supervisorctl restart market-feed octane worker-ai worker-default reverb worker-backtest
```

### 5.3 已手工改过 supervisor，想恢复样板

```bash
sudo bash deploy/bootstrap-node.sh --stage=supervisor --force
```

### 5.4 只重刷 `.env` 基线值

```bash
sudo bash deploy/bootstrap-node.sh --stage=env --domain=trade.example.com
sudo bash deploy/bootstrap-node.sh --stage=database   # 重新 config:cache
sudo supervisorctl restart octane market-feed worker-ai worker-default reverb worker-backtest
```

### 5.5 本机 DB/Redis 一体化

```bash
sudo bash deploy/bootstrap-node.sh --stage=deps --with-db --redis-password='S3cure!Pass'
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS crypto_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

随后在 `.env` 填 `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`，再跑 `--stage=database`。

---

## 6. 输出与退出码约定

| 前缀 | 含义 |
|---|---|
| `[OK]` | 检查通过或动作完成 |
| `[WARN]` | 不阻塞部署，但上线前需处理（端点不可达、内存不足、未申请 TLS 等） |
| `[FAIL]` | 致命错误，立即退出 |
| `==> 阶段 x/8` | 当前阶段标题 |
| `[dry-run]` | 未实际执行的命令 |

| 退出码 | 触发条件 |
|---|---|
| `0` | 阶段执行完成（`verify` 即使有 WARN 也返回 0） |
| `1` | `die`：非 root 执行需 root 阶段、缺少 `composer.json`、`.env` 缺失等 |
| `2` | 未知参数或未知 `--stage` |
| `1`（preflight 特例） | 前置检查存在问题；单独执行时返回 1，`--stage=all` 中仅 WARN 并继续 |

---

## 7. 常见报错处理

### 7.1 依赖安装类

| 症状 | 根因 | 处理 |
|---|---|---|
| `add-apt-repository: command not found` | 缺 `software-properties-common` | `sudo apt-get install -y software-properties-common` 后重跑 `--stage=deps` |
| `ppa.launchpadcontent.net ... does not have a Release file` | 非 Ubuntu 或版本不被 PPA 支持 | 确认 `/etc/os-release` 为 `ubuntu 22.04/24.04`；其他发行版需手工适配包名 |
| `pecl: command not found` | 未装 `php-pear` | `sudo apt-get install -y php-pear php8.4-dev` |
| `Cannot find php-config` | 缺 `php8.4-dev` 或 `--php-bin` 与 pecl 绑定的 PHP 不一致 | 装 `php8.4-dev`；用 `pecl config-show` 核对 `php_ini`/`php_bin` |
| `configure: error: openssl ... not found` | 缺 `libssl-dev` / `pkg-config` | `sudo apt-get install -y libssl-dev pkg-config build-essential` |
| Swoole 编译被 `Killed` | 小内存机型 OOM | 临时加 2G swap：`sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile` |
| `php -m` 有 swoole 但进程报 `undefined function Swoole\Coroutine\run` | supervisor 用的 PHP 与装扩展的 PHP 不是同一个 | 用 `--php-bin` 指向同版本，重跑 `--stage=supervisor --force`；`php -v` 与 `php8.4 -v` 分别核对 |

### 7.2 网络类

| 症状 | 根因 | 处理 |
|---|---|---|
| `端点不可达 fstream.binance.com:443` | 机房/IP 被交易所风控 | **换机房或换 IP**，不要加代理绕过；廉价厂商整段 IP 常被封 |
| 全部端点 DNS 解析失败 | `/etc/resolv.conf` 或 VPC DNS 异常 | `getent hosts api.binance.com`；修 DNS 后复跑 `--stage=preflight` |
| 仅 WS 端点不可达（8443/9443） | 安全组只放行 443 | 放行出站 8443/9443，或换支持全出站的机房 |
| 签名报 `-1022` 且服务器时间正常 | 时钟漂移 | `sudo apt-get install -y chrony && sudo systemctl enable --now chrony`；`date -u` 核对 |

### 7.3 代码与依赖类

| 症状 | 根因 | 处理 |
|---|---|---|
| `[FAIL] 未找到 composer.json，代码未就位` | 目录为空且未传 `--repo` | 补传 `--repo=<git-url>`，或手工放代码后重跑 `--stage=code` |
| `git pull --ff-only` 失败 | 节点上有本地改动或分支分叉 | 登录节点 `git status` 人工处理；**不要**在脚本外自动 `reset --hard` |
| `composer install` 内存耗尽 | 小内存机型 | `COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader` |
| `composer` 报 `proc_open` 被禁用 | PHP `disable_functions` 含 `proc_open` | 从 `php.ini` 移除后重启 PHP |
| `storage/logs` 写入失败 | 目录属主/权限不对 | `sudo chown -R www-data:www-data <app-root>/storage <app-root>/bootstrap/cache && sudo chmod -R ug+rwX ...` |
| `storage:link` 报已存在 | 正常现象 | 忽略；必要时 `rm public/storage` 后重跑 |

### 7.4 配置与密钥类

| 症状 | 根因 | 处理 |
|---|---|---|
| `No application encryption key has been specified` | `APP_KEY` 为空 | `sudo -u www-data php8.4 <app-root>/artisan key:generate --force`，或重跑 `--stage=env` |
| 改了 `.env` 但行为没变 | 已执行 `config:cache`，环境值被冻结 | `php artisan config:clear` 或重跑 `--stage=database` |
| `sudo: unknown user: www-data` | 自定义 `--run-user` 不存在 | `sudo useradd -r -s /usr/sbin/nologin <user>` 或改用 `www-data` |
| artisan 报 `.env` 读不到、权限拒绝 | `sed -i` 后 `.env` 变成 root 属主 | `sudo chown <run-user>:<run-user> .env && sudo chmod 600 .env`（脚本已内置，手工改动后需自查） |
| `EXCHANGE_MASTER_KEY` 为空启动报错 | 密钥未生成或 `.env` 被覆盖 | 重跑 `--stage=env` 生成；**若原 KEK 已丢失，历史租户凭据不可恢复**，只能重录 |
| `Permission denied (publickey)` 拉代码 | 私有仓库未配部署密钥 | 配 SSH deploy key，或改用 HTTPS + token |

### 7.5 数据库与 Redis 类

| 症状 | 根因 | 处理 |
|---|---|---|
| `SQLSTATE[HY000] [2002] Connection refused` | MySQL 未启动/端口错/仅监听 socket | `systemctl status mysql`；`ss -tlnp \| grep 3306`；确认 `DB_HOST`/`DB_PORT` |
| `Unknown database 'crypto_saas'` | 未建库 | `mysql -uroot -e "CREATE DATABASE crypto_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"` |
| `Access denied for user` | 账号/密码/授权 host 不匹配 | 建专用账号并授权：`CREATE USER 'crypto'@'127.0.0.1' IDENTIFIED BY '...'; GRANT ALL ON crypto_saas.* TO 'crypto'@'127.0.0.1';` |
| `NOAUTH Authentication required` | Redis 设了密码但 `.env` 未填 | `.env` 填 `REDIS_PASSWORD` 后 `config:cache`；`redis-cli -a '<pwd>' ping` 验证 |
| `Connection refused` 连 Redis | 未启动或绑定地址错 | `systemctl status redis-server`；`ss -tlnp \| grep 6379` |
| 迁移报外键/字符集错误 | 库字符集非 utf8mb4 | 建库时显式 `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` |

### 7.6 进程与调度类

| 症状 | 根因 | 处理 |
|---|---|---|
| `supervisorctl status` 显示 `FATAL` | 命令路径/用户/目录错误，启动即退 | `sudo supervisorctl tail -100 <program> stderr`；核对 `/etc/supervisor/conf.d/<program>.conf` 的 `command`/`directory`/`user` |
| `market-feed` 启动即退 | 缺 swoole、日志目录不可写、cwd 错 | 看 `<log-dir>/market-feed.log`；确认 `php -m \| grep swoole`；重跑 `--stage=supervisor --force` |
| kill -9 后 12s 未恢复 | `startretries` 用尽或 `startsecs` 内再次崩溃 | `supervisorctl status` 看状态；先修启动错误，再 `supervisorctl start`。连续崩溃是真实故障，不是自愈失败 |
| `schedule:list` 为空 | `.env`/APP_KEY 异常、`config:cache` 过期 | `php artisan config:clear && php artisan schedule:list` |
| 调度不触发 | cron 服务未启动或 cron.d 权限不对 | `systemctl status cron`；`ls -l /etc/cron.d/crypto-saas`（需 644，属主 root）；`grep CRON /var/log/syslog` |
| `health` 返回 503 | DB/Redis 为 critical 检查失败 | 看返回体 `checks.database` / `checks.redis`；修连接后复跑 `--stage=verify` |
| `health` 无响应 | Octane 未启动或端口非 8000 | `supervisorctl status octane`；`ss -tlnp \| grep 8000` |
| 8000 端口被占用 | 旧进程残留 | `sudo ss -tlnp \| grep 8000` 找 PID 后 `kill`，或改 `OCTANE_PORT` 并同步 Nginx |

### 7.7 Nginx 与 TLS 类

| 症状 | 根因 | 处理 |
|---|---|---|
| `nginx: [emerg] duplicate default server` | `sites-enabled/default` 仍存在 | `sudo rm -f /etc/nginx/sites-enabled/default && sudo nginx -t` |
| `nginx: [emerg] bind() to 0.0.0.0:80 failed` | 80 被 Apache/Nginx 旧实例占用 | `sudo ss -tlnp \| grep :80`，停掉冲突服务 |
| `502 Bad Gateway` | Octane 未起或端口不匹配 | `curl -i http://127.0.0.1:8000/api/v1/health`；`supervisorctl status octane` |
| certbot 失败：`DNS problem: NXDOMAIN` | 域名未解析到本机 | 先配 A 记录，`dig +short <domain>` 确认指向本机公网 IP |
| certbot 失败：`Timeout during connect` | 安全组未放行 80 或 CDN 代理 | 放行入站 80/443；关闭 CDN 代理后重试 |
| WebSocket 连不上 | `/app` 未反代或 Reverb 未启动 | `supervisorctl status reverb`；`curl -i http://127.0.0.1:8080`；核对 Nginx `location /app` |

### 7.8 脚本自身类

| 症状 | 根因 | 处理 |
|---|---|---|
| `$'\r': command not found` | 文件被 Windows 转成 CRLF | `sed -i 's/\r$//' deploy/bootstrap-node.sh` |
| `未知参数: --xxx` 且退出码 2 | 参数拼写错或用了未支持的 `--stage=a,b` | 对照第 3 节；多阶段请分次执行 |
| `--dry-run` 仍探测端点/跑健康检查 | dry-run 只拦截写操作 | 设计如此；用 `--stage=preflight` 单独检查更清晰 |
| 脚本在中途退出，未提示原因 | 某条外部命令返回非零 | 看最后一行 `==> 阶段` 与上一条命令；可单阶段重跑定位 |

---

## 8. 部署后校验清单

```bash
APP=/var/www/crypto-saas

# 1) 配置与密钥
ls -l $APP/.env                                  # 期望 -rw------- www-data
sudo -u www-data php8.4 $APP/artisan about --only=environment

# 2) 调度
sudo -u www-data php8.4 $APP/artisan schedule:list   # 6 条在列

# 3) 进程
sudo supervisorctl status                       # 6 个 RUNNING

# 4) 健康
curl -fsS http://127.0.0.1:8000/api/v1/health | head -c 500

# 5) 日志
ls -l /var/log/crypto-saas/
tail -n 50 /var/log/crypto-saas/market-feed.log

# 6) 调度与轮转
systemctl status cron
sudo logrotate -d /etc/logrotate.d/crypto-saas
```

随后按 Runbook §8.2 执行验收矩阵：

```bash
bash deploy/acceptance.sh --only=1,2 --with-destructive
bash deploy/acceptance.sh --only=3,4,6,8
```

---

## 9. 重跑、覆盖与回滚

### 9.1 重跑影响面

| 操作 | 会覆盖什么 | 不会覆盖什么 |
|---|---|---|
| `--stage=env` | 第 3.3 节的固定基线键 | 已有 `APP_KEY` / `EXCHANGE_MASTER_KEY` / `REVERB_*`、交易所与通知凭据 |
| `--stage=supervisor --force` | `/etc/supervisor/conf.d/*.conf` | 进程运行中的内存状态（update 后按需重启） |
| `--stage=nginx --force` | `sites-available/crypto-saas` | TLS 证书文件 |
| `--stage=cron` | `/etc/cron.d/crypto-saas`、logrotate 配置 | 其他 cron 任务 |
| `--stage=database` | `config:cache` / `route:cache` | 数据库数据（只跑未执行迁移） |

### 9.2 回滚步骤

```bash
# 1) 停交易与行情（先停开仓入口，再停行情）
sudo supervisorctl stop market-feed
sudo supervisorctl stop octane worker-ai worker-default reverb worker-backtest

# 2) 回滚代码
cd /var/www/crypto-saas && git log --oneline -5
git checkout <上一个稳定 commit>

# 3) 重建依赖与缓存
composer install --no-dev --optimize-autoloader
sudo -u www-data php8.4 artisan config:cache
sudo -u www-data php8.4 artisan route:cache

# 4) 按顺序拉起
sudo supervisorctl start octane reverb worker-default worker-ai worker-backtest
sudo supervisorctl start market-feed
sudo -u www-data php8.4 artisan reconcile:run --period=5m
```

数据库回滚不在脚本能力内：迁移均为向前兼容设计，**不要**用 `migrate:rollback` 处理生产故障；
需要回退时走备份恢复流程（见 [operations.md](operations.md) 备份章节）。

---

## 10. 安全注意事项

- `EXCHANGE_MASTER_KEY` 一旦生成必须离线备份。丢失等于所有租户交易所凭据不可解，只能重录。
- `.env` 必须 600 且属主为 `--run-user`；禁止把 `.env` 内容贴进聊天、工单、日志。
- 生产 Redis 必须设 `REDIS_PASSWORD`；MySQL/Redis 只监听 `127.0.0.1`，安全组仅放 22/80/443。
- 交易所 API key 按验收项最小授权：现货交易 + 读权限，**禁止提现**，绑定节点出口 IP。
- `--with-tls` 默认不带邮箱注册；需要证书到期通知时，部署后手工执行一次
  `sudo certbot --nginx -d <domain>` 并登记邮箱。
- 脚本不会自动创建数据库账号；生产应使用独立最小权限账号，不用 root 连库。
