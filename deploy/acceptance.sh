#!/usr/bin/env bash
# =============================================================================
# 海外节点真实链路验收脚本（runbook 第 8 节 8.1 矩阵 11 项半自动化）
#
# 用法：
#   bash deploy/acceptance.sh                     # 交互式全量跑（下单项逐项确认）
#   bash deploy/acceptance.sh --only=2,4,6,8      # 只跑指定项
#   bash deploy/acceptance.sh --skip=5,7,9        # 跳过指定项（如暂未配 key）
#   bash deploy/acceptance.sh --with-destructive  # 含第 1 项 kill -9 自愈验收
#   bash deploy/acceptance.sh --yes               # 下单项不再逐项确认（仍需 .env 已配 key）
#
# 产出：
#   storage/acceptance/<UTC 时间戳>/item-*.log    逐项证据日志
#   storage/acceptance/<UTC 时间戳>/summary.txt   汇总表（贴回 runbook 8.1 销账）
#
# 约定：PASS=自动判定通过；FAIL=自动判定失败；SKIP=前置缺失跳过；MANUAL=需人工确认。
# 退出码：有 FAIL 时非零。
# =============================================================================
set -u

APP_ROOT="${APP_ROOT:-/var/www/crypto-saas}"
WITH_DESTRUCTIVE=0
ASSUME_YES=0
ONLY=""
SKIP=""
LIQ_DURATION=120

for arg in "$@"; do
    case "$arg" in
        --with-destructive) WITH_DESTRUCTIVE=1 ;;
        --yes)              ASSUME_YES=1 ;;
        --only=*)           ONLY="${arg#*=}" ;;
        --skip=*)           SKIP="${arg#*=}" ;;
        --liq-duration=*)   LIQ_DURATION="${arg#*=}" ;;
        --app-root=*)       APP_ROOT="${arg#*=}" ;;
        -h|--help)
            grep '^#' "$0" | head -20
            exit 0 ;;
        *) echo "未知参数: $arg"; exit 2 ;;
    esac
done

cd "$APP_ROOT" || { echo "APP_ROOT 不存在: $APP_ROOT"; exit 2; }

TS=$(date -u +%Y%m%d-%H%M%S)
EVIDENCE_DIR="$APP_ROOT/storage/acceptance/$TS"
mkdir -p "$EVIDENCE_DIR"
SUMMARY="$EVIDENCE_DIR/summary.txt"

declare -A RESULT

# --------------------------------------------------------------------------
# 工具函数
# --------------------------------------------------------------------------

in_list() {  # in_list "1,2,3" 2
    local list="$1" item="$2"
    [[ -z "$list" ]] && return 1
    [[ ",$list," == *",$item,"* ]]
}

should_run() {  # should_run <n>
    local n="$1"
    [[ -n "$ONLY" ]] && { in_list "$ONLY" "$n" || return 1; }
    in_list "$SKIP" "$n" && return 1
    return 0
}

env_get() {  # env_get KEY → 从 .env 读值（去引号）
    local v
    v=$(grep -E "^$1=" .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//")
    printf '%s' "$v"
}

confirm() {  # confirm <提示>；--yes 时直接通过
    [[ "$ASSUME_YES" -eq 1 ]] && return 0
    local ans
    read -r -p "$1 [y/N] " ans
    [[ "$ans" == "y" || "$ans" == "Y" ]]
}

mark() {  # mark <n> <PASS|FAIL|SKIP|MANUAL> <备注>
    RESULT[$1]="$2"
    printf '[%-6s] item-%02d  %s\n' "$2" "$1" "$3" | tee -a "$SUMMARY"
}

artisan() {
    php artisan "$@"
}

# tinker_run <php 文件>：以 tinker --execute 执行，输出原样返回
tinker_run() {
    php artisan tinker --execute="$(cat "$1")"
}

probe() {  # probe <host> <port> <说明>；失败返回 1
    if nc -vz -w 5 "$1" "$2" >/dev/null 2>&1; then
        echo "  probe OK   $1:$2 ($3)"
        return 0
    fi
    echo "  probe FAIL $1:$2 ($3)"
    return 1
}

log_file() { printf '%s/item-%02d-%s.log' "$EVIDENCE_DIR" "$1" "$2"; }

# --------------------------------------------------------------------------
# item 1：supervisor kill -9 自愈（破坏性，需 --with-destructive）
# --------------------------------------------------------------------------
item_1() {
    local log; log=$(log_file 1 supervisor-self-heal)
    {
        if [[ "$WITH_DESTRUCTIVE" -ne 1 ]]; then
            echo "未加 --with-destructive，跳过 kill -9 破坏性验收"
            mark 1 SKIP "需 --with-destructive 显式开启"
            return
        fi
        local pid
        pid=$(pgrep -f "market:feed" | head -1 || true)
        if [[ -z "$pid" ]]; then
            mark 1 FAIL "未找到 market:feed 进程"
            return
        fi
        echo "kill -9 market:feed pid=$pid"
        sudo kill -9 "$pid"
        sleep 12
        sudo supervisorctl status market-feed
        if sudo supervisorctl status market-feed | grep -q RUNNING; then
            echo "--- 崩溃恢复日志（末 30 行）---"
            tail -n 30 /var/log/crypto-saas/market-feed.log 2>/dev/null || true
            mark 1 PASS "kill -9 后 12s 内重新 RUNNING"
        else
            mark 1 FAIL "kill -9 后未在 12s 内恢复 RUNNING"
        fi
    } > >(tee "$log") 2>&1
}

# --------------------------------------------------------------------------
# item 2：调度双命令
# --------------------------------------------------------------------------
item_2() {
    local log; log=$(log_file 2 schedule)
    {
        artisan schedule:list
        local ok=1
        artisan schedule:list | grep -q 'billing:refresh-status' || { echo "缺 billing:refresh-status"; ok=0; }
        artisan schedule:list | grep -q 'reconcile:run'         || { echo "缺 reconcile:run"; ok=0; }
        if [[ "$ok" -eq 1 ]]; then
            mark 2 PASS "schedule:list 含 billing+reconcile"
        else
            mark 2 FAIL "调度命令缺失"
        fi
    } > >(tee "$log") 2>&1
}

# --------------------------------------------------------------------------
# item 3：B8 真实强平流（Binance 主网）
# --------------------------------------------------------------------------
item_3() {
    local log; log=$(log_file 3 liquidation)
    {
        probe fstream.binance.com 443 "强平 WS" || { mark 3 FAIL "fstream.binance.com 不可达"; return; }

        echo "运行 poc:liquidation-demo --duration=$LIQ_DURATION ..."
        artisan poc:liquidation-demo --duration="$LIQ_DURATION"
        local rc=$?
        [[ $rc -ne 0 ]] && { mark 3 FAIL "liquidation-demo 退出码 $rc"; return; }

        echo "--- 最近 10 分钟 liquidation_events 入库数 ---"
        cat > /tmp/acc_liq_db.php <<'PHP'
$cnt = DB::table('liquidation_events')
    ->where('event_ts', '>', (time() - 600) * 1000)
    ->count();
echo "db_count=$cnt\n";
echo $cnt > 0 ? "ACCEPTANCE:PASS\n" : "ACCEPTANCE:FAIL\n";
PHP
        local db_out
        db_out=$(tinker_run /tmp/acc_liq_db.php)
        echo "$db_out"

        echo "--- 三档窗口 Redis key（liquidation:*）---"
        local redis_args=(-h "$(env_get REDIS_HOST)" -p "$(env_get REDIS_PORT)")
        local pw; pw=$(env_get REDIS_PASSWORD)
        [[ -n "$pw" ]] && redis_args+=(-a "$pw" --no-auth-warning)
        [[ -z "$(env_get REDIS_HOST)" ]] && redis_args=()  # 缺省走本机
        local keys
        keys=$(redis-cli "${redis_args[@]}" --scan --pattern '*liquidation:*' 2>/dev/null | head -20)
        echo "$keys"
        local key_cnt
        key_cnt=$(printf '%s' "$keys" | grep -c 'liquidation:' || true)

        if [[ "$db_out" == *"ACCEPTANCE:PASS"* && "$key_cnt" -gt 0 ]]; then
            mark 3 PASS "事件入库 + Redis 三档窗口有数（keys=$key_cnt）"
        else
            mark 3 FAIL "入库或 Redis 证据不足（keys=$key_cnt）"
        fi
    } > >(tee "$log") 2>&1
}

# --------------------------------------------------------------------------
# 公共：行情验证（item 4/6/8 复用）
# feed_check <n> <命令> <probe host:port...>
# --------------------------------------------------------------------------
feed_check() {
    local n="$1" cmd="$2"; shift 2
    local log; log=$(log_file "$n" "$cmd")
    {
        local probe_ok=1
        for hp in "$@"; do
            probe "${hp%%:*}" "${hp##*:}" "feed" || probe_ok=0
        done
        [[ $probe_ok -eq 0 ]] && { mark "$n" FAIL "交易所端点不可达"; return; }

        local out
        out=$(artisan "$cmd" --symbol=BTCUSDT --interval=1m --wait=120 2>&1)
        local rc=$?
        echo "$out"
        if [[ $rc -eq 0 && "$out" == *"PASS"* ]]; then
            mark "$n" PASS "$cmd REST+WS 通过"
        else
            mark "$n" FAIL "$cmd 未通过（rc=$rc）"
        fi
    } > >(tee "$log") 2>&1
}

item_4() { feed_check 4 poc:verify-okx-feed   "www.okx.com:443" "ws.okx.com:8443"; }
item_6() { feed_check 6 poc:verify-bybit-feed "api-testnet.bybit.com:443" "stream-testnet.bybit.com:443"; }
item_8() { feed_check 8 poc:verify-bitget-feed "api.bitget.com:443" "ws.bitget.com:443"; }

# --------------------------------------------------------------------------
# 公共：真实下单验收（item 5/7/9 复用）
# order_check <n> <名称> <数量> <php 凭据数组片段> <adapter> <需要确认的说明>
# --------------------------------------------------------------------------
order_check() {
    local n="$1" name="$2" qty="$3" creds="$4" adapter="$5" note="$6"
    local log; log=$(log_file "$n" "$name")
    {
        echo "$note"
        confirm "确认执行 $name 真实下单（数量 $qty）？" || { mark "$n" SKIP "人工取消"; return; }

        cat > /tmp/acc_order.php <<PHP
try {
    \$adapter = app(\App\Domain\Exchange\ExchangeManager::class)->adapter('$adapter', 'spot', $creds);
    \$clientOrderId = '1_1_' . time() . '_' . bin2hex(random_bytes(4));
    \$order = \$adapter->placeOrder('BTCUSDT', 'buy', 'market', '$qty', null, null, [
        'clientOrderId' => \$clientOrderId,
    ]);
    echo "exchangeOrderId={\$order->exchangeOrderId} status={\$order->status}\n";
    \$found = \$adapter->getOrderByClientOrderId('BTCUSDT', \$clientOrderId);
    echo \$found ? "clientOrderId 查回: FOUND\n" : "clientOrderId 查回: NOT FOUND\n";
    echo (\$order->exchangeOrderId !== '' && \$found) ? "ACCEPTANCE:PASS\n" : "ACCEPTANCE:FAIL\n";
} catch (\Throwable \$e) {
    echo 'ERROR: ' . \$e->getMessage() . "\n";
    echo "ACCEPTANCE:FAIL\n";
}
PHP
        local out
        out=$(tinker_run /tmp/acc_order.php)
        echo "$out"
        if [[ "$out" == *"ACCEPTANCE:PASS"* ]]; then
            mark "$n" PASS "$name 下单成交且 clientOrderId 可查回"
        else
            mark "$n" FAIL "$name 下单/查回失败"
        fi
    } > >(tee "$log") 2>&1
}

item_5() {
    if [[ "$(env_get EXCHANGE_OKX_TESTNET)" != "true" || -z "$(env_get EXCHANGE_OKX_KEY)" ]]; then
        mark 5 SKIP "EXCHANGE_OKX_TESTNET/KEY 未配置"
        return
    fi
    order_check 5 okx-demo-order 100 \
        "[ 'api_key' => env('EXCHANGE_OKX_KEY'), 'api_secret' => env('EXCHANGE_OKX_SECRET'), 'passphrase' => env('EXCHANGE_OKX_PASSPHRASE') ]" \
        okx "OKX 模拟盘（x-simulated-trading:1）市价买 100 USDT。"
}

item_7() {
    if [[ "$(env_get EXCHANGE_BYBIT_TESTNET)" != "true" || -z "$(env_get EXCHANGE_BYBIT_KEY)" ]]; then
        mark 7 SKIP "EXCHANGE_BYBIT_TESTNET/KEY 未配置"
        return
    fi
    order_check 7 bybit-testnet-order 100 \
        "[ 'api_key' => env('EXCHANGE_BYBIT_KEY'), 'api_secret' => env('EXCHANGE_BYBIT_SECRET') ]" \
        bybit "Bybit testnet 市价买 100 USDT。"
}

item_9() {
    if [[ -z "$(env_get EXCHANGE_BITGET_KEY)" ]]; then
        mark 9 SKIP "EXCHANGE_BITGET_KEY 未配置"
        return
    fi
    order_check 9 bitget-mainnet-order 10 \
        "[ 'api_key' => env('EXCHANGE_BITGET_KEY'), 'api_secret' => env('EXCHANGE_BITGET_SECRET'), 'passphrase' => env('EXCHANGE_BITGET_PASSPHRASE') ]" \
        bitget "⚠️  Bitget 主网真实资金小额单（10 USDT）。"
}

# --------------------------------------------------------------------------
# item 10：通知渠道实测（Telegram / 企微 / 邮件）
# --------------------------------------------------------------------------
item_10() {
    local log; log=$(log_file 10 notify-channels)
    {
        cat > /tmp/acc_notify.php <<'PHP'
use App\Domain\Notification\Contracts\NotificationPayload;

$payload = new NotificationPayload(
    title: '✅ 验收测试通知',
    fields: ['来源' => 'deploy/acceptance.sh item-10', '节点' => gethostname()],
    footer: gmdate('Y-m-d H:i:s') . ' UTC',
    context: ['acceptance' => 'item-10'],
);
$channels = [
    'telegram'    => \App\Domain\Notification\Channels\TelegramChannel::class,
    'wechat_work' => \App\Domain\Notification\Channels\WeComChannel::class,
    'mail'        => \App\Domain\Notification\Channels\MailChannel::class,
];
foreach ($channels as $name => $class) {
    try {
        $id = (new $class)->send($payload);
        echo "CHANNEL:$name:PASS" . ($id ? " (id=$id)" : '') . "\n";
    } catch (\Throwable $e) {
        echo "CHANNEL:$name:FAIL " . mb_substr($e->getMessage(), 0, 200) . "\n";
    }
}
PHP
        local out
        out=$(tinker_run /tmp/acc_notify.php)
        echo "$out"

        local pass_cnt=0 cfgd=0 ch
        for ch in telegram wechat_work mail; do
            local configured=0
            case "$ch" in
                telegram)    [[ -n "$(env_get TELEGRAM_BOT_TOKEN)" && -n "$(env_get TELEGRAM_CHAT_ID)" ]] && configured=1 ;;
                wechat_work) [[ -n "$(env_get WECHAT_WORK_WEBHOOK)" ]] && configured=1 ;;
                mail)        [[ -n "$(env_get NOTIFY_MAIL_TO)" ]] && configured=1 ;;
            esac
            if [[ $configured -eq 0 ]]; then
                echo "  $ch: 未配置，SKIP"
                continue
            fi
            cfgd=$((cfgd + 1))
            [[ "$out" == *"CHANNEL:$ch:PASS"* ]] && pass_cnt=$((pass_cnt + 1))
        done

        if [[ $cfgd -eq 0 ]]; then
            mark 10 SKIP "三渠道均未配置"
        elif [[ $pass_cnt -eq $cfgd ]]; then
            mark 10 PASS "已配置渠道全部送达（$pass_cnt/$cfgd），请人工确认实际收到"
        else
            mark 10 FAIL "送达 $pass_cnt/$cfgd"
        fi
    } > >(tee "$log") 2>&1
}

# --------------------------------------------------------------------------
# item 11：账实对账 testnet 实跑（半自动：基线 → 人工制造 drift → 复跑）
# --------------------------------------------------------------------------
item_11() {
    local log; log=$(log_file 11 reconcile)
    {
        echo "=== 阶段 1：基线 reconcile:run ==="
        artisan reconcile:run --period=5m
        artisan reconcile:status "$(env_get RECONCILE_INSTANCE_ID)" 2>/dev/null || true

        echo
        echo "请手工在 Binance testnet 转入/转出一笔资金制造 drift（或确认上一笔已制造）。"
        confirm "drift 已制造，继续复跑？" || { mark 11 MANUAL "基线已跑，drift 复跑待人工"; return; }

        echo "=== 阶段 2：drift 复跑 ==="
        artisan reconcile:run --period=5m

        cat > /tmp/acc_reconcile.php <<'PHP'
$rows = DB::table('reconciliation_reports')->orderByDesc('id')->limit(5)->get();
foreach ($rows as $r) {
    echo sprintf("id=%d instance=%s status=%s diff=%s at=%s\n",
        $r->id, $r->strategy_instance_id ?? '-', $r->status, $r->diff_json ?? '-', $r->created_at);
}
$drift = $rows->firstWhere('status', 'drift') ?? $rows->firstWhere('status', 'critical');
echo $drift ? "ACCEPTANCE:PASS\n" : "ACCEPTANCE:FAIL\n";
PHP
        local out
        out=$(tinker_run /tmp/acc_reconcile.php)
        echo "$out"

        if [[ "$out" == *"ACCEPTANCE:PASS"* ]]; then
            echo "请确认实例已按配置暂停（Filament 实例页或 strategy_instances 状态）。"
            mark 11 PASS "drift/critical 报告已生成（实例暂停请人工确认）"
        else
            mark 11 FAIL "未见 drift/critical 报告"
        fi
    } > >(tee "$log") 2>&1
}

# --------------------------------------------------------------------------
# 主流程
# --------------------------------------------------------------------------
echo "============================================================"
echo " 真实链路验收  $(date -u '+%Y-%m-%d %H:%M:%S') UTC"
echo " APP_ROOT=$APP_ROOT"
echo " 证据目录=$EVIDENCE_DIR"
echo "============================================================"

for n in 1 2 3 4 5 6 7 8 9 10 11; do
    if should_run "$n"; then
        echo
        echo "----- item $n -----"
        "item_$n"
    else
        mark "$n" SKIP "--only/--skip 过滤"
    fi
done

echo
echo "================= 汇总 ================="
pass=0; fail=0; skip=0; manual=0
for n in 1 2 3 4 5 6 7 8 9 10 11; do
    r="${RESULT[$n]:-SKIP}"
    printf '  item-%02d  %s\n' "$n" "$r" | tee -a "$SUMMARY"
    case "$r" in
        PASS)   pass=$((pass + 1)) ;;
        FAIL)   fail=$((fail + 1)) ;;
        MANUAL) manual=$((manual + 1)) ;;
        *)      skip=$((skip + 1)) ;;
    esac
done
{
    echo "  ------------------------------------"
    echo "  PASS=$pass FAIL=$fail MANUAL=$manual SKIP=$skip"
    echo "  证据目录: $EVIDENCE_DIR"
    echo "  提示: PASS 项截图/日志归档后，回 runbook 8.1 表标 ✅ 并注明日期"
} | tee -a "$SUMMARY"

[[ $fail -gt 0 ]] && exit 1
exit 0
