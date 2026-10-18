# Quickstart: Bitget 现货适配器 POC 验证

**Date**: 2026-10-18 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/spec.md)

验证 Bitget 现货适配器端到端可用性的运行指南。POC 阶段先用公共行情验证（无需凭据）；Bitget 现货无公开模拟盘（澄清决定 A），真实下单降级为主网小额单待 key 到位补测。参考 [contracts/bitget-api-contract.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/contracts/bitget-api-contract.md) 与 [data-model.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/data-model.md)。

## 前置条件

1. WSL Ubuntu-22.04 开发机，PHP 8.4 + Swoole 6.2.3 扩展
2. 项目依赖已安装（`composer install`）
3. `.env` 配置 `REDIS_CLIENT=phpredis`，Redis 可达
4. Bitget 端点对开发机出口网络可达性待实测（`nc -vz api.bitget.com 443`、`nc -vz ws.bitget.com 443`）；若主网不可达，离线断言先行覆盖，真实流后补（与前三家一致，坑 #23 范式）

## 场景 1+2: REST 行情 + WS 单 bar（公共，无需凭据）

验证 spec User Story 1/2（SC-001/SC-002）。POC 命令（FR-012，独立 artisan 命令，镜像 `poc:verify-okx-feed`/`poc:verify-bybit-feed` 范式）：

```bash
# 在 WSL 项目根
php artisan poc:verify-bitget-feed --symbol=BTCUSDT --interval=1m --wait=120
```

命令依次执行：REST getTicker 打印 lastPrice/bid/ask → REST getKline(500) 打印根数与首根 OHLC（含首末 ts，记录原始排序方向证据，R-011）→ WS subscribeKlineStream 等待单根 bar 关闭回调并打印。

**预期结果**：ticker 字段非空合理、bid < ask；kline ≤500 根且按 openTime 升序；≤120 秒内 onBar 回调触发，回调参数为完整 ExchangeKline DTO（symbol 为平台格式 BTCUSDT）；退出码 0=PASS、非 0=FAIL。

或手动 tinker 验证：

```bash
php artisan tinker
```

```php
$adapter = app(\App\Domain\Exchange\ExchangeManager::class)->adapter('bitget', 'spot');

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

验证响应映射、签名构造、路由与策略兼容性，无需真实网络（constitution VI 测试隔离）。

```bash
# WSL 项目根
php artisan test --filter=BitgetAdapterTest
php artisan test --filter=BitgetRequestSignerTest
php artisan test --filter=ErrorCodeTranslationTest
php artisan test --filter=ExchangeManagerRouteTest
php artisan test --filter=StrategyExchangeCompatibilityTest
```

**预期结果**：全部通过，覆盖：

- V2 签名构造（prehash=timestamp+METHOD+requestPath(+queryString)+body、base64 输出、ACCESS-* 四件套、毫秒 timestamp 非 ISO8601）——BitgetRequestSignerTest（黄金样本逐字节断言，R-002）
- 前三家签名回归：Binance/OKX/Bybit 既有签名用例 100% 通过零变化（SC-004）
- ticker/kline/order/balance 响应映射 DTO、kline 排序后升序、订单状态机（new/init→submitted、partially_filled→partial、filled→filled、cancelled→canceled、failed→failed）
- granularity 双形态映射（REST 1min 系 / WS candle1m 系）、越界 interval 抛 clientError、凭据三段守卫（缺 key/secret/passphrase 任一段抛 ExchangeApiException）、候选 40013 时钟硬失败归类、候选 43001 cancel 特判返回 false
- US4 边界：config exchange.exchanges.bitget 段不含 testnet 键的结构性断言（FR-013）
- WS 帧解析：snapshot 首帧不回调、bar 切换触发 onBar、同 bar 重复 update 不触发（R-006 判定语义的离线仿真）
- C1：ExchangeManager 对 ('binance','spot')/('okx','spot')/('bybit','spot')/('bitget','spot') 四家路由 + binance futures 分支 + 非法 code 显式断言
- C2：spot_grid/spot_dca 经 ExchangeManager('bitget','spot') 的下单/撤单/查单调用序列与其他三家端一致（RecordingAdapter 驱动，stub/mock 注入，无网络）

## 场景 4: 无模拟盘边界核查（FR-013，替代前三家的环境开关场景）

Bitget 现货无公开 testnet/模拟盘（澄清决定 A），本场景验证"无开关"边界的确定性：

```bash
# WSL 项目根
php artisan tinker
```

```php
$cfg = config('exchange.exchanges.bitget');
var_dump(array_key_exists('testnet', $cfg));          // 预期: false（无 testnet 键）
var_dump(array_key_exists('testnet_rest_url', $cfg)); // 预期: false
echo $cfg['rest_url'];  // 预期: https://api.bitget.com
echo $cfg['ws_url'];    // 预期: wss://ws.bitget.com/v2/ws/public
print_r($cfg['rate_limits']);  // 预期: public/trade/account 三类 ['limit','window'] 结构
```

**预期结果**：config 仅含生产端点与限流配置，无 testnet 相关键；该场景同时由场景 3 的 US4 结构性断言覆盖。

## 场景 5: 真实下单（主网小额，待用户 key，可选）

验证 spec User Story 3（SC-003 挂起部分）。Bitget 现货无模拟盘，真实下单为**主网小额市价单**，需用户提供主网账户凭据（key/secret/passphrase 三段）。

```bash
# .env
EXCHANGE_BITGET_KEY=...
EXCHANGE_BITGET_SECRET=...
EXCHANGE_BITGET_PASSPHRASE=...
```

```php
$adapter = app(\App\Domain\Exchange\ExchangeManager::class)->adapter('bitget', 'spot', [
    'api_key' => env('EXCHANGE_BITGET_KEY'),
    'api_secret' => env('EXCHANGE_BITGET_SECRET'),
    'passphrase' => env('EXCHANGE_BITGET_PASSPHRASE'),
]);

$balance = $adapter->getBalance();
echo $balance->available;    // 预期: 可用 USDT 余额（R-010 镜像 Binance 现货语义）

$order = $adapter->placeOrder('BTCUSDT', 'buy', 'market', '10', opts: [  // 小额：10 USDT
    'clientOrderId' => '1_1_'.time().'_'.bin2hex(random_bytes(4)),
]);
echo $order->exchangeOrderId;  // 预期: Bitget 订单 ID
echo $order->status;          // 预期: filled 或 submitted
echo $order->clientOrderId;   // 预期: 与传入一致

// 按 clientOrderId 查回（崩溃恢复核实，orderInfo→history-orders 双查，R-005）
$found = $adapter->getOrderByClientOrderId('BTCUSDT', $order->clientOrderId);
echo $found ? 'FOUND' : 'NOT FOUND';  // 预期: FOUND

// 撤已成交单（候选 43001 特判）
$ok = $adapter->cancelOrder('BTCUSDT', $order->exchangeOrderId, $order->clientOrderId);
var_dump($ok);  // 预期: false（已成交不可撤），不抛异常
```

**预期结果**：订单按 10 USDT 报价币计价成交，按 clientOrderId 可查回，余额变动反映该笔成交；撤已成交单返回 false。同时实测记录：orderInfo 对终态单的即时可见性（验证 R-005 双查是否可退化）、cancel not-exist 实际错误码（核对候选 43001）。

## 验收矩阵

| 场景 | spec 验收 | 依赖 | POC 阶段 |
|------|----------|------|---------|
| 1+2 REST 行情 + WS bar | SC-001 / SC-002 | 网络 | 即时可验 |
| 3 离线断言 | FR-001~013 / SC-003（映射部分）/ SC-004 / SC-005 / SC-006 | 无 | 即时可验 |
| 4 无模拟盘边界 | FR-013（US4） | 无 | 即时可验 |
| 5 真实下单（主网小额） | SC-003（挂起部分）/ US4 场景3 | 主网 key + 网络 | **待 key，可选** |

## 故障排查

- **Bitget 端点不可达**：`nc -vz` 两端点分别实测；若主网拒绝，离线断言先行（场景 3），真实流待网络恢复后补（与前三家坑 #23 一致处理）。
- **签名失败（候选 40009）**：检查 prehash=timestamp+METHOD+requestPath(+queryString)+body、base64 输出（非 hex）、timestamp 为毫秒整数串（非 OKX 的 ISO8601）、GET queryString 与发送串一致、POST body 为 raw JSON 且与签名串逐字节一致。
- **timestamp 超窗（候选 40013）**：本地时钟漂移所致，校准系统时钟（WSL 时钟漂移可先 `wsl --shutdown` 后重启触发同步）；该错误为硬失败不重试，勿靠重试掩盖。
- **认证失败（候选 40011/40012/40014/40018）**：检查 key/passphrase 有效性（三段缺一不可）、API 权限（现货读写）、IP 白名单配置与出口 IP 一致。
- **查单漏单**：orderInfo 对终态单可见窗口候选有限（R-005）；必须按 orderInfo→history-orders 双查，勿单查一端误判 null 导致崩溃恢复重复下单。
- **WS 不推送 / bar 不触发**：检查订阅帧 args 结构（`instType:SPOT` + `channel:candle1m` + `instId:BTCUSDT` 三字段对象数组，非字符串数组）；文本帧 `ping`/`pong` 心跳（非 JSON op 帧）25s 周期（analyze F5 定值）与断线退避重连。
- **bar 关闭判定证据（R-006 关键）**：POC 首次运行时记录连续推送帧日志（action 值、data[0][0] ts 序列），确认「snapshot 首帧→update 增量→新 ts 出现即上一 bar 关闭」行为序列；若实测帧含 confirm 类标志，退化实现为 confirm 过滤并更新 contract/data-model 对应节。
- **kline 排序方向（R-011）**：场景 1+2 命令已打印首末 ts；若原始方向为升序，排序逻辑无操作（幂等），若为倒序则归一化为升序——两种方向均应输出升序数组。
