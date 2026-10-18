# Quickstart: Bybit 现货适配器 POC 验证

**Date**: 2026-10-18 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/spec.md)

验证 Bybit 现货适配器端到端可用性的运行指南。POC 阶段先用公共行情验证（无需凭据），真实下单待 testnet key 后补。参考 [contracts/bybit-api-contract.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/contracts/bybit-api-contract.md) 与 [data-model.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/data-model.md)。

## 前置条件

1. WSL Ubuntu-22.04 开发机，PHP 8.4 + Swoole 6.2.3 扩展
2. 项目依赖已安装（`composer install`）
3. `.env` 配置 `REDIS_CLIENT=phpredis`，Redis 可达
4. Bybit 端点对开发机出口网络可达性待实测（`nc -vz api.bybit.com 443`、`nc -vz stream.bybit.com 443`、`nc -vz api-testnet.bybit.com 443`、`nc -vz stream-testnet.bybit.com 443`）；若主网不可达，离线断言先行覆盖，真实流后补（与 001 一致）

## 场景 1+2: REST 行情 + WS 单 bar（公共，无需凭据）

验证 spec User Story 1/2（SC-001/SC-002）。POC 命令（clarify Q3，独立 artisan 命令，对齐 001 `poc:verify-okx-feed` 范式）：

```bash
# 在 WSL 项目根
php artisan poc:verify-bybit-feed --symbol=BTCUSDT --interval=1m --wait=120
```

命令依次执行：REST getTicker 打印 lastPrice/bid/ask → REST getKline(500) 打印根数与首根 OHLC → WS subscribeKlineStream 等待单根 bar 关闭回调并打印。

**预期结果**：ticker 字段非空合理、bid < ask；kline ≤500 根且按 openTime 升序；≤120 秒内 onBar 回调触发，回调参数为完整 ExchangeKline DTO（symbol 为平台格式 BTCUSDT）；退出码 0=PASS、非 0=FAIL。

或手动 tinker 验证：

```bash
php artisan tinker
```

```php
$adapter = app(\App\Domain\Exchange\ExchangeManager::class)->adapter('bybit', 'spot');

// REST（symbol 即平台格式，无需归一化）
$ticker = $adapter->getTicker('BTCUSDT');
echo $ticker->lastPrice;   // 预期: 非空数值字符串
$klines = $adapter->getKline('BTCUSDT', '1m', 500);
echo count($klines);        // 预期: ≤ 500，按 openTime 升序

// WS
$received = false;
$subId = $adapter->subscribeKlineStream('BTCUSDT', '1m', function ($kline) use (&$received) {
    $received = true;
    echo "bar closed: {$kline->symbol} O={$kline->open} C={$kline->close} T={$kline->openTime}\n";
});
\Swoole\Coroutine::sleep(120);
$adapter->unsubscribeStream($subId);
echo $received ? 'PASS' : 'FAIL';
```

## 场景 3: 离线断言（无需网络）

验证响应映射、签名构造、路由与策略兼容性，无需真实网络（constitution VI 测试隔离；clarify Q4）。

```bash
# WSL 项目根
php artisan test --filter=BybitAdapterTest
php artisan test --filter=SignerTest
php artisan test --filter=ExchangeManagerRouteTest
php artisan test --filter=StrategyExchangeCompatibilityTest
```

**预期结果**：全部通过，覆盖：

- v5 签名构造（prehash=timestamp+api_key+recv_window+payload、hex 输出、X-BAPI-* 五件套）——BybitRequestSignerTest
- Binance/OKX 签名迁移回归：迁移后签名输出与迁移前逐字节一致——BinanceRequestSignerTest / OkxRequestSignerTest（SC-006）
- ticker/kline/order/balance 响应映射 DTO、kline 倒序反升序、订单状态机（New→submitted、PartiallyFilled→partial、Filled→filled、Cancelled→canceled、Rejected→failed）
- interval 分钟数映射、凭据两段守卫（缺 key/secret 抛 ExchangeApiException）、retCode 10002 时钟硬失败归类、170213 cancel 特判返回 false
- testnet 独立域名切换（api.bybit.com ↔ api-testnet.bybit.com / stream.bybit.com ↔ stream-testnet.bybit.com）
- C1：ExchangeManager 对 ('binance','spot')/('okx','spot')/('bybit','spot') 三家路由显式断言
- C2：spot_grid/spot_dca 经 ExchangeManager('bybit','spot') 的下单/撤单/查单调用序列与 Binance/OKX 端一致（stub/mock 注入，无网络）

## 场景 4: 模拟盘开关（SC-004）

```bash
# .env
EXCHANGE_BYBIT_TESTNET=true
```

```php
$adapter = app(\App\Domain\Exchange\ExchangeManager::class)->adapter('bybit', 'spot');
// 检查同一公共请求的目标域名：
//   testnet=false → https://api.bybit.com / wss://stream.bybit.com/v5/public/spot
//   testnet=true  → https://api-testnet.bybit.com / wss://stream-testnet.bybit.com/v5/public/spot
```

**预期结果**：开关切换后 REST/WS base URL 在生产域名与 testnet 独立域名间整体切换，不注入额外 header（与 OKX 机制不同）。该场景同时由场景 3 的 US4 离线断言覆盖。

## 场景 5: 真实下单（待 testnet key，可选）

验证 spec User Story 3（SC-003），需 Bybit testnet 账户凭据（key/secret 两段，无 passphrase）。

```bash
# .env
EXCHANGE_BYBIT_TESTNET=true
EXCHANGE_BYBIT_KEY=...        # testnet 账户 key
EXCHANGE_BYBIT_SECRET=...
```

```php
$adapter = app(\App\Domain\Exchange\ExchangeManager::class)->adapter('bybit', 'spot', [
    'api_key' => env('EXCHANGE_BYBIT_KEY'),
    'api_secret' => env('EXCHANGE_BYBIT_SECRET'),
]);

$balance = $adapter->getBalance();
echo $balance->available;    // 预期: 模拟盘可用 USDT 余额（walletBalance - locked）

$order = $adapter->placeOrder('BTCUSDT', 'buy', 'market', '100', opts: [
    'clientOrderId' => '1_1_'.time().'_'.bin2hex(random_bytes(4)),  // ≤36 字符
]);
echo $order->exchangeOrderId;  // 预期: Bybit 订单 ID
echo $order->status;          // 预期: filled 或 submitted
echo $order->clientOrderId;   // 预期: 与传入一致

// 按 clientOrderId 查回（崩溃恢复核实，realtime→history 双端点）
$found = $adapter->getOrderByClientOrderId('BTCUSDT', $order->clientOrderId);
echo $found ? 'FOUND' : 'NOT FOUND';  // 预期: FOUND

// 撤已成交单（170213 特判）
$ok = $adapter->cancelOrder('BTCUSDT', $order->exchangeOrderId, $order->clientOrderId);
var_dump($ok);  // 预期: false（已成交不可撤），不抛异常
```

**预期结果**：订单成交（marketUnit=quoteCoin 按 100 USDT 计价），按 clientOrderId 可查回，余额变动反映该笔成交；撤已成交单返回 false。

## 验收矩阵

| 场景 | spec 验收 | 依赖 | POC 阶段 |
|------|----------|------|---------|
| 1+2 REST 行情 + WS bar | SC-001 / SC-002 | 网络 | 即时可验 |
| 3 离线断言 | FR-001~012 / SC-005 / SC-006 | 无 | 即时可验 |
| 4 模拟盘开关 | SC-004 | 网络（离线断言可覆盖） | 即时可验 |
| 5 真实下单 | SC-003 | testnet key + 网络 | 待 key，可选 |

## 故障排查

- **Bybit 端点不可达**：`nc -vz` 四端点分别实测；若主网拒绝，离线断言先行（场景 3），真实流待网络恢复后补（与 001 坑23 一致处理）。
- **签名失败（retCode 10004）**：检查 prehash=timestamp+api_key+recv_window+payload、hex 输出（非 base64）、X-BAPI-SIGN-TYPE:2、POST body 为 raw JSON 且与签名串逐字节一致、GET queryString 与实际发送串一致。
- **recv_window 超窗（retCode 10002）**：本地时钟漂移所致，校准系统时钟（WSL 时钟漂移可先 `wsl --shutdown` 后重启触发同步）；该错误为硬失败不重试（clarify Q2），勿靠重试掩盖。
- **认证失败（10003/10005/10010）**：检查 key 有效性、API 权限（现货读写）、IP 白名单配置与出口 IP 一致。
- **查单漏单**：`/v5/order/realtime` 只返活跃单，已成交单立即移入 history；必须按 R-005 双端点查询，勿单查一端误判 null 导致崩溃恢复重复下单。
- **WS 不推送**：检查 topic 命名 `kline.1.BTCUSDT`（interval 为分钟数 `1` 而非 `1m`）、confirm:true 才触发回调、`{"op":"ping"}` 20s 心跳与断线退避重连。
