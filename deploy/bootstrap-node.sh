#!/usr/bin/env bash
# =============================================================================
# 海外节点一键部署脚本（Ubuntu 22.04 / 24.04）
#
# 用法：
#   sudo bash deploy/bootstrap-node.sh --stage=preflight
#   sudo bash deploy/bootstrap-node.sh --stage=all --repo=https://github.com/941xinye/trading_saas.git
#   sudo bash deploy/bootstrap-node.sh --stage=deps --with-db
#   sudo bash deploy/bootstrap-node.sh --stage=env --domain=trade.example.com
#   sudo bash deploy/bootstrap-node.sh --stage=verify
#
# 阶段（可单独执行，也可 --stage=all 顺序执行）：
#   preflight   系统/时区/PHP 扩展/资源/交易所端点探测（不改动系统）
#   deps        安装 PHP 8.4 + Swoole + supervisor/nginx/cron（可选本机 MySQL/Redis）
#   code        拉代码 + composer install + 目录权限
#   env         生成 .env（APP_KEY / EXCHANGE_MASTER_KEY / REVERB 三段）
#   database    执行迁移 + 配置/路由缓存
#   supervisor  渲染并安装 6 份 supervisor 程序 + kill -9 自愈前提
#   nginx       Nginx 反代站点（HTTP；带 --domain + --with-tls 时走 certbot）
#   cron        系统 crontab（schedule:run）+ 日志轮转
#   verify      部署后自检（schedule:list / health / supervisor status）
#
# 常用参数：
#   --app-root=/var/www/crypto-saas   项目根目录
#   --run-user=www-data               PHP 进程运行用户
#   --php-bin=/usr/bin/php8.4         PHP 二进制
#   --domain=trade.example.com        Nginx server_name；同时作为 APP_URL
#   --repo=<git url>                  代码仓库（未给且目录非 git 仓库时跳过 clone）
#   --with-db                         安装本机 mysql-server + redis-server（仅监听 127.0.0.1）
#   --redis-password=<pwd>            --with-db 时设置 Redis 密码（强烈建议）
#   --with-tls                        为 --domain 申请 Let's Encrypt 证书
#   --force                           覆盖已存在的 supervisor/Nginx 配置
#   --dry-run                         只打印将执行的命令
#
# 说明：脚本幂等；重复执行不会覆盖已有 .env、不会重建已存在的数据库。
# =============================================================================
set -euo pipefail

APP_ROOT="/var/www/crypto-saas"
RUN_USER="www-data"
PHP_BIN="/usr/bin/php8.4"
DOMAIN=""
REPO=""
STAGE="all"
WITH_DB=0
WITH_TLS=0
FORCE=0
DRY_RUN=0
REDIS_PASSWORD=""
LOG_DIR="/var/log/crypto-saas"

for arg in "$@"; do
    case "$arg" in
        --app-root=*)       APP_ROOT="${arg#*=}" ;;
        --run-user=*)       RUN_USER="${arg#*=}" ;;
        --php-bin=*)        PHP_BIN="${arg#*=}" ;;
        --domain=*)         DOMAIN="${arg#*=}" ;;
        --repo=*)           REPO="${arg#*=}" ;;
        --stage=*)          STAGE="${arg#*=}" ;;
        --redis-password=*) REDIS_PASSWORD="${arg#*=}" ;;
        --with-db)          WITH_DB=1 ;;
        --with-tls)         WITH_TLS=1 ;;
        --force)            FORCE=1 ;;
        --dry-run)          DRY_RUN=1 ;;
        --log-dir=*)        LOG_DIR="${arg#*=}" ;;
        -h|--help)          sed -n '2,35p' "$0"; exit 0 ;;
        *) echo "未知参数: $arg（--help 查看用法）" >&2; exit 2 ;;
    esac
done

# --------------------------------------------------------------------------
# 基础工具
# --------------------------------------------------------------------------
SUDO=""
if [[ "$(id -u)" -ne 0 ]]; then
    SUDO="sudo"
fi

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m[OK]\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m[WARN]\033[0m %s\n' "$*"; }
die()  { printf '  \033[31m[FAIL]\033[0m %s\n' "$*" >&2; exit 1; }

run() {  # run <命令...>
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '  [dry-run] %s\n' "$*"
        return 0
    fi
    "$@"
}

run_sh() {  # run_sh "<shell 片段>"：需要管道/重定向时使用
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '  [dry-run] %s\n' "$1"
        return 0
    fi
    bash -c "$1"
}

# 以应用用户执行 artisan（避免 root 生成 root 属主的 storage 缓存）
artisan() {
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '  [dry-run] %s artisan %s\n' "$PHP_BIN" "$*"
        return 0
    fi
    if [[ "$(id -u)" -eq 0 && "$RUN_USER" != "root" ]]; then
        sudo -u "$RUN_USER" "$PHP_BIN" "$APP_ROOT/artisan" "$@"
    else
        "$PHP_BIN" "$APP_ROOT/artisan" "$@"
    fi
}

env_get() {  # env_get KEY：读取 .env 值（去引号）
    [[ -f "$APP_ROOT/.env" ]] || { printf ''; return; }
    grep -E "^$1=" "$APP_ROOT/.env" 2>/dev/null | tail -1 \
        | cut -d= -f2- | tr -d '\r' \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//" || true
}

env_set() {  # env_set KEY VALUE：存在则替换，不存在则追加
    local key="$1" value="$2" file="$APP_ROOT/.env"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '  [dry-run] .env %s=%s\n' "$key" "$value"
        return 0
    fi
    if grep -qE "^${key}=" "$file"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

hex32() { "$PHP_BIN" -r 'echo bin2hex(random_bytes(32));'; }

need_root() {
    [[ "$(id -u)" -eq 0 ]] || die "该阶段需要 root：请使用 sudo 执行"
}

# --------------------------------------------------------------------------
# 1. preflight
# --------------------------------------------------------------------------
stage_preflight() {
    say "阶段 1/8：前置检查（不修改系统）"
    local fails=0

    # OS
    if [[ -r /etc/os-release ]]; then
        . /etc/os-release
        if [[ "${ID:-}" == "ubuntu" && ( "${VERSION_ID:-}" == "22.04" || "${VERSION_ID:-}" == "24.04" ) ]]; then
            ok "OS: Ubuntu ${VERSION_ID}"
        else
            warn "OS: ${PRETTY_NAME:-unknown}（仅验证 Ubuntu 22.04/24.04）"
        fi
    else
        warn "无法读取 /etc/os-release"
        fails=$((fails + 1))
    fi

    # 时区必须 UTC
    local tz; tz=$(timedatectl show -p Timezone --value 2>/dev/null || echo "unknown")
    if [[ "$tz" == "UTC" ]]; then
        ok "时区: UTC"
    else
        warn "时区: $tz（应为 UTC，全链路时间口径依赖）"
        fails=$((fails + 1))
    fi

    # PHP
    if [[ -x "$PHP_BIN" ]] || command -v "$PHP_BIN" >/dev/null 2>&1; then
        ok "PHP: $("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null)"
        local ext
        for ext in bcmath mbstring xml curl zip redis pdo_mysql swoole; do
            if "$PHP_BIN" -m 2>/dev/null | grep -qi "^${ext}$"; then
                ok "扩展: $ext"
            else
                warn "缺少扩展: $ext"
                fails=$((fails + 1))
            fi
        done
    else
        warn "未找到 PHP: $PHP_BIN（先跑 --stage=deps）"
        fails=$((fails + 1))
    fi

    # 资源
    local mem_mb disk_gb
    mem_mb=$(free -m | awk '/^Mem:/{print $2}')
    disk_gb=$(df -Pm / | awk 'NR==2{print int($4/1024)}')
    ok "内存: ${mem_mb} MB；根分区可用: ${disk_gb} GB"
    if [[ "$mem_mb" -lt 1800 ]]; then
        warn "内存 < 2GB，建议升配"
    fi

    # 交易所端点（任一失败即换机房/换 IP，不在应用层绕过）
    if command -v nc >/dev/null 2>&1; then
        local hp host port
        for hp in \
            "fstream.binance.com:443" "stream.binance.com:443" \
            "api.binance.com:443" "fapi.binance.com:443" \
            "www.okx.com:443" "ws.okx.com:8443" \
            "api-testnet.bybit.com:443" "stream-testnet.bybit.com:443" \
            "api.bitget.com:443" "ws.bitget.com:443"; do
            host="${hp%%:*}"; port="${hp##*:}"
            if nc -vz -w 5 "$host" "$port" >/dev/null 2>&1; then
                ok "端点可达 $host:$port"
            else
                warn "端点不可达 $host:$port"
                fails=$((fails + 1))
            fi
        done
    else
        warn "未安装 nc（netcat-openbsd），跳过端点探测"
        fails=$((fails + 1))
    fi

    echo
    if [[ "$fails" -eq 0 ]]; then
        ok "前置检查全部通过，可继续 --stage=deps"
        return 0
    fi
    warn "前置检查存在 $fails 项问题；端点不可达必须换机房/换 IP"
    return 1
}

# --------------------------------------------------------------------------
# 2. deps
# --------------------------------------------------------------------------
stage_deps() {
    say "阶段 2/8：安装运行时依赖"
    need_root

    run_sh "export DEBIAN_FRONTEND=noninteractive; apt-get update -y"
    run_sh "export DEBIAN_FRONTEND=noninteractive; apt-get install -y software-properties-common ca-certificates lsb-release apt-transport-https"

    if ! grep -rq "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d 2>/dev/null; then
        run_sh "add-apt-repository -y ppa:ondrej/php"
        run_sh "export DEBIAN_FRONTEND=noninteractive; apt-get update -y"
    else
        ok "ondrej/php PPA 已存在"
    fi

    run_sh "export DEBIAN_FRONTEND=noninteractive; apt-get install -y \
        php8.4-cli php8.4-dev php8.4-mysql php8.4-redis php8.4-bcmath \
        php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php-pear \
        unzip git curl netcat-openbsd supervisor nginx cron logrotate \
        build-essential pkg-config libssl-dev"

    if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^swoole$'; then
        say "编译安装 Swoole（约 3-8 分钟）"
        run_sh "yes '' | pecl install swoole"
        run_sh "echo 'extension=swoole.so' > /etc/php/8.4/mods-available/swoole.ini"
        run_sh "phpenmod swoole"
    else
        ok "Swoole 已安装"
    fi

    if [[ "$WITH_DB" -eq 1 ]]; then
        say "安装本机 MySQL / Redis（仅监听 127.0.0.1）"
        run_sh "export DEBIAN_FRONTEND=noninteractive; apt-get install -y mysql-server redis-server"
        run_sh "sed -i 's/^bind-address.*/bind-address = 127.0.0.1/' /etc/mysql/mysql.conf.d/mysqld.cnf || true"
        run_sh "sed -i 's/^bind 127.0.0.1.*/bind 127.0.0.1/' /etc/redis/redis.conf || true"
        if [[ -n "$REDIS_PASSWORD" ]]; then
            run_sh "sed -i 's/^# requirepass .*/requirepass ${REDIS_PASSWORD}/' /etc/redis/redis.conf"
            if ! grep -q '^requirepass ' /etc/redis/redis.conf; then
                run_sh "echo 'requirepass ${REDIS_PASSWORD}' >> /etc/redis/redis.conf"
            fi
            ok "Redis 已设置 requirepass"
        else
            warn "未传 --redis-password；生产 Redis 必须设密码后再启动"
        fi
        run_sh "systemctl enable --now mysql redis-server"
    fi

    run_sh "systemctl enable --now supervisor nginx cron"
    run "$PHP_BIN" -m | grep -qi '^swoole$' && ok "Swoole 加载正常" || warn "Swoole 未加载，检查 phpenmod 与 PHP 版本一致性"
}

# --------------------------------------------------------------------------
# 3. code
# --------------------------------------------------------------------------
stage_code() {
    say "阶段 3/8：部署代码与依赖"
    need_root

    run_sh "mkdir -p '$APP_ROOT' '$LOG_DIR'"
    run_sh "chown -R '$RUN_USER':'$RUN_USER' '$APP_ROOT' '$LOG_DIR'"
    run_sh "chmod 755 '$APP_ROOT' '$LOG_DIR'"

    if [[ -d "$APP_ROOT/.git" ]]; then
        ok "已是 git 仓库，执行 pull --ff-only"
        run_sh "cd '$APP_ROOT' && git fetch --all --prune && git pull --ff-only"
    elif [[ -n "$REPO" ]]; then
        ok "克隆仓库: $REPO"
        run_sh "git clone '$REPO' '$APP_ROOT'"
    else
        warn "目录非 git 仓库且未传 --repo，跳过代码拉取（请手工放置代码）"
    fi

    if [[ -f "$APP_ROOT/composer.json" ]]; then
        run_sh "cd '$APP_ROOT' && composer install --no-dev --optimize-autoloader --no-interaction"
    else
        die "未找到 composer.json，代码未就位"
    fi

    run_sh "chown -R '$RUN_USER':'$RUN_USER' '$APP_ROOT'"
    run_sh "chmod -R ug+rwX '$APP_ROOT/storage' '$APP_ROOT/bootstrap/cache'"
    artisan storage:link || warn "storage:link 失败（可能已存在，可忽略）"
    ok "代码与依赖就绪"
}

# --------------------------------------------------------------------------
# 4. env
# --------------------------------------------------------------------------
stage_env() {
    say "阶段 4/8：生成 .env 与密钥"
    need_root

    if [[ ! -f "$APP_ROOT/.env" ]]; then
        [[ -f "$APP_ROOT/.env.example" ]] || die "缺少 .env.example"
        run_sh "cp '$APP_ROOT/.env.example' '$APP_ROOT/.env'"
        ok "已从 .env.example 创建 .env"
    else
        ok ".env 已存在，仅补齐缺失项（不覆盖已有值）"
    fi

    run_sh "chmod 600 '$APP_ROOT/.env'"
    run_sh "chown '$RUN_USER':'$RUN_USER' '$APP_ROOT/.env'"

    # 框架密钥
    if [[ -z "$(env_get APP_KEY)" ]]; then
        artisan key:generate --force
        ok "已生成 APP_KEY"
    else
        ok "APP_KEY 已存在"
    fi

    # 生产基础项
    env_set APP_ENV production
    env_set APP_DEBUG false
    env_set LOG_STACK json
    env_set LOG_LEVEL info
    env_set QUEUE_CONNECTION redis
    env_set CACHE_STORE redis
    env_set SESSION_DRIVER redis
    env_set BROADCAST_CONNECTION reverb
    env_set HEALTH_CHECK_CLICKHOUSE true
    env_set ALERTS_ENABLED true
    if [[ -n "$DOMAIN" ]]; then
        env_set APP_URL "https://${DOMAIN}"
    fi

    # KEK：丢失 = 全部租户交易所凭据不可解，只存在节点 .env
    if [[ -z "$(env_get EXCHANGE_MASTER_KEY)" ]]; then
        local kek; kek=$(hex32)
        env_set EXCHANGE_MASTER_KEY "$kek"
        ok "已生成 EXCHANGE_MASTER_KEY（请立即离线备份，切勿入库/进 git/进聊天记录）"
    else
        ok "EXCHANGE_MASTER_KEY 已存在"
    fi

    # Reverb 三段
    if [[ -z "$(env_get REVERB_APP_ID)" ]]; then
        env_set REVERB_APP_ID "$(( RANDOM % 90000 + 10000 ))"
    fi
    if [[ -z "$(env_get REVERB_APP_KEY)" ]]; then
        env_set REVERB_APP_KEY "$("$PHP_BIN" -r 'echo bin2hex(random_bytes(8));')"
    fi
    if [[ -z "$(env_get REVERB_APP_SECRET)" ]]; then
        env_set REVERB_APP_SECRET "$("$PHP_BIN" -r 'echo bin2hex(random_bytes(16));')"
    fi
    if [[ -n "$DOMAIN" ]]; then
        env_set REVERB_HOST "$DOMAIN"
        env_set REVERB_PORT 443
        env_set REVERB_SCHEME https
    fi

    if [[ "$WITH_DB" -eq 1 ]]; then
        env_set DB_HOST 127.0.0.1
        if [[ -n "$REDIS_PASSWORD" ]]; then
            env_set REDIS_PASSWORD "$REDIS_PASSWORD"
        fi
        warn "本机 MySQL/Redis 已安装；请确认 DB_DATABASE / DB_USERNAME / DB_PASSWORD 正确，并手工创建数据库"
    fi

    echo
    warn "以下凭据需人工填入 .env（缺失只影响对应验收项，不影响启动）："
    echo "     交易所: EXCHANGE_TESTNET_KEY/SECRET、EXCHANGE_OKX_*、EXCHANGE_BYBIT_*、EXCHANGE_BITGET_*"
    echo "     通知:   TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID、WECHAT_WORK_WEBHOOK、NOTIFY_MAIL_TO + MAIL_*"
    echo "     支付:   PAYMENT_CHANNEL=btcpay + BTCPAY_BASE_URL / STORE_ID / API_KEY / WEBHOOK_SECRET"
    echo "     归档:   ARCHIVE_CH_HOST / ARCHIVE_CH_PORT / ARCHIVE_CH_DATABASE"

    # sed -i 会以 root 重建 .env，必须把属主改回应用用户，否则 artisan 读不到配置
    run_sh "chmod 600 '$APP_ROOT/.env'"
    run_sh "chown '$RUN_USER':'$RUN_USER' '$APP_ROOT/.env'"
    ok ".env 配置完成（APP_URL=${DOMAIN:-未设置}）"
}

# --------------------------------------------------------------------------
# 5. database
# --------------------------------------------------------------------------
stage_database() {
    say "阶段 5/8：数据库迁移与缓存"
    need_root

    [[ -f "$APP_ROOT/.env" ]] || die ".env 不存在，先跑 --stage=env"

    artisan migrate --force --no-interaction
    artisan config:cache
    artisan route:cache
    ok "迁移与配置缓存完成（修改 .env 后须重跑 config:cache）"
}

# --------------------------------------------------------------------------
# 6. supervisor
# --------------------------------------------------------------------------
stage_supervisor() {
    say "阶段 6/8：安装 supervisor 程序"
    need_root

    local tpl src name dest
    shopt -s nullglob
    for src in "$APP_ROOT"/deploy/supervisor/*.conf.example; do
        name=$(basename "$src" .conf.example)
        dest="/etc/supervisor/conf.d/${name}.conf"
        if [[ -f "$dest" && "$FORCE" -ne 1 ]]; then
            ok "已存在，跳过（--force 覆盖）: $dest"
            continue
        fi
        if [[ "$DRY_RUN" -eq 1 ]]; then
            printf '  [dry-run] 渲染 %s -> %s\n' "$src" "$dest"
            continue
        fi
        sed -e "s|/var/www/crypto-saas|$APP_ROOT|g" \
            -e "s|/usr/bin/php|$PHP_BIN|g" \
            -e "s|user=www-data|user=$RUN_USER|g" \
            -e "s|/var/log/crypto-saas|$LOG_DIR|g" \
            "$src" > "$dest"
        ok "安装 $dest"
    done
    shopt -u nullglob

    run_sh "supervisorctl reread"
    run_sh "supervisorctl update"
    if [[ "$DRY_RUN" -eq 0 ]]; then
        supervisorctl status || true
    fi
    warn "任一程序 FATAL 时先看 $LOG_DIR/<name>.log 与 storage/logs/laravel.log"
}

# --------------------------------------------------------------------------
# 7. nginx
# --------------------------------------------------------------------------
stage_nginx() {
    say "阶段 7/8：Nginx 反代"
    need_root

    if [[ -z "$DOMAIN" ]]; then
        warn "未传 --domain，跳过 Nginx 站点生成（可手工配置 proxy_pass http://127.0.0.1:8000）"
        return 0
    fi

    local site="/etc/nginx/sites-available/crypto-saas"
    if [[ -f "$site" && "$FORCE" -ne 1 ]]; then
        ok "站点已存在，跳过（--force 覆盖）: $site"
    elif [[ "$DRY_RUN" -eq 1 ]]; then
        printf '  [dry-run] 生成 %s（server_name=%s）\n' "$site" "$DOMAIN"
    else
        cat > "$site" <<NGINX
server {
    listen 80;
    server_name ${DOMAIN};

    client_max_body_size 20m;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 120s;
    }

    # Laravel Reverb（私有频道 WebSocket）
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_read_timeout 3600s;
    }

    location /apps {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
    }
}
NGINX
        ln -sf "$site" /etc/nginx/sites-enabled/crypto-saas
        rm -f /etc/nginx/sites-enabled/default
        ok "已生成站点并启用"
    fi

    run_sh "nginx -t"
    run_sh "systemctl reload nginx"

    if [[ "$WITH_TLS" -eq 1 ]]; then
        run_sh "export DEBIAN_FRONTEND=noninteractive; apt-get install -y certbot python3-certbot-nginx"
        run_sh "certbot --nginx -d '$DOMAIN' --non-interactive --agree-tos --register-unsafely-without-email --redirect"
        ok "已申请并启用 TLS"
    else
        warn "未申请 TLS；生产必须在 Nginx 终止 HTTPS（--with-tls 或手工 certbot）"
    fi
}

# --------------------------------------------------------------------------
# 8. cron
# --------------------------------------------------------------------------
stage_cron() {
    say "阶段 8/8：系统调度与日志轮转"
    need_root

    local cron_file="/etc/cron.d/crypto-saas"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '  [dry-run] 写入 %s（schedule:run 每分钟）\n' "$cron_file"
    else
        cat > "$cron_file" <<CRON
# Laravel 调度入口：billing / reconcile / renewal-reminders / backtest:reap-stale / ops:alerts:check
* * * * * ${RUN_USER} ${PHP_BIN} ${APP_ROOT}/artisan schedule:run >> ${LOG_DIR}/schedule.log 2>&1
CRON
        chmod 644 "$cron_file"
        ok "已写入 $cron_file"
    fi

    local logrotate_file="/etc/logrotate.d/crypto-saas"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '  [dry-run] 写入 %s\n' "$logrotate_file"
    else
        cat > "$logrotate_file" <<LOGROTATE
${LOG_DIR}/*.log {
    daily
    rotate 14
    missingok
    notifempty
    copytruncate
    compress
    delaycompress
}
LOGROTATE
        chmod 644 "$logrotate_file"
        ok "已写入 $logrotate_file"
    fi

    run_sh "logrotate -d '$logrotate_file' >/dev/null 2>&1 || true"
}

# --------------------------------------------------------------------------
# 9. verify
# --------------------------------------------------------------------------
stage_verify() {
    say "部署自检"
    local fails=0

    # .env 权限
    if [[ "$(stat -c '%a' "$APP_ROOT/.env" 2>/dev/null)" == "600" ]]; then
        ok ".env 权限 600"
    else
        warn ".env 权限不是 600"
        fails=$((fails + 1))
    fi

    # 调度
    local sched
    sched=$(artisan schedule:list 2>/dev/null || true)
    if [[ -n "$sched" ]]; then
        local c
        for c in billing:refresh-status reconcile:run billing:renewal-reminders billing:expire-invoices backtest:reap-stale ops:alerts:check; do
            if printf '%s' "$sched" | grep -q "$c"; then
                ok "调度在列: $c"
            else
                warn "调度缺失: $c"
                fails=$((fails + 1))
            fi
        done
    else
        warn "schedule:list 无输出（检查 .env 与 PHP 扩展）"
        fails=$((fails + 1))
    fi

    # supervisor
    if command -v supervisorctl >/dev/null 2>&1; then
        local st
        st=$(supervisorctl status 2>/dev/null || true)
        if [[ -n "$st" ]]; then
            printf '%s\n' "$st"
            local p
            for p in octane market-feed worker-ai worker-default reverb worker-backtest; do
                if printf '%s' "$st" | grep -qE "^${p}(:[^ ]+)?\s+RUNNING"; then
                    ok "进程 RUNNING: $p"
                else
                    warn "进程非 RUNNING: $p"
                    fails=$((fails + 1))
                fi
            done
        else
            warn "supervisorctl 无程序（先跑 --stage=supervisor）"
            fails=$((fails + 1))
        fi
    fi

    # 健康检查
    local health
    health=$(curl -fsS --max-time 5 http://127.0.0.1:8000/api/v1/health 2>/dev/null || true)
    if [[ -n "$health" ]]; then
        printf '  health: %s\n' "$health"
        if printf '%s' "$health" | grep -q '"status":"ok"'; then
            ok "健康检查 ok"
        else
            warn "健康检查非 ok（DB/Redis 需重点排查）"
            fails=$((fails + 1))
        fi
    else
        warn "健康检查无响应（octane 未起或端口不是 8000）"
        fails=$((fails + 1))
    fi

    echo
    if [[ "$fails" -eq 0 ]]; then
        ok "部署自检全部通过；下一步执行验收：bash deploy/acceptance.sh"
    else
        warn "自检存在 $fails 项问题，按提示修复后重跑 --stage=verify"
    fi
    return 0
}

# --------------------------------------------------------------------------
# 调度入口
# --------------------------------------------------------------------------
case "$STAGE" in
    preflight)  stage_preflight ;;
    deps)       stage_deps ;;
    code)       stage_code ;;
    env)        stage_env ;;
    database)   stage_database ;;
    supervisor) stage_supervisor ;;
    nginx)      stage_nginx ;;
    cron)       stage_cron ;;
    verify)     stage_verify ;;
    all)
        stage_preflight || warn "前置检查未全通过，继续部署（端点不可达请换机房）"
        stage_deps
        stage_code
        stage_env
        stage_database
        stage_supervisor
        stage_nginx
        stage_cron
        stage_verify
        ;;
    *) echo "未知阶段: $STAGE（--help 查看）" >&2; exit 2 ;;
esac

say "完成：$STAGE"
