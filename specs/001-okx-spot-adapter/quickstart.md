# Quickstart: OKX 现货适配器 POC 验证

**Date**: 2026-10-16 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/spec.md)

验证 OKX 现货适配器端到端可用性的运行指南。POC 阶段先用公共行情验证（无需凭据），真实下单待 demo key 后补。参考 [contracts/okx-api-contract.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/contracts/okx-api-contract.md) 与 [data-model.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/data-model.md)。

## 前置条件

1. WSL Ubuntu-22.04 开发机，PHP 8.4 + Swoole 6.2.3 + sodium 扩展
2. 项目依赖已安装（`composer install`）
3. `.env` 配置 `REDIS_CLIENT=phpredis`，Redis 可达
4. OKX 端点对开发机出口网络可达性待实测（`nc -vz ws.okx.com 8443` 与 `nc -vz www.okx.com 443`）；若主网不可达，离线断言先行覆盖，真实流后补

## 场景 1: REST 行情（公共，无需凭据）

验证 spec User Story 1（SC-001）。

```bash
# 在 WSL 项目根
php artisan tinker
```

```php
// 实例化 OKX 适配器（不注入凭据）
$adapter = app(\App\Domain\Exchange\ExchangeManager::class)->adapter('okx', 'spot');

// 拉取 BTC ticker（平台格式 BTCUSDT，适配器内部归一化为 BTC-USDT）
$ticker = $adapter->getTicker('BTCUSDT');
echo $ticker->symbol;      // 预期: BTCUSDT（归一化后平台格式）
echo $ticker->lastPrice;   // 预期: 非空数值字符串
echo $ticker->bidPrice;    // 预期: 非空，bid < ask
echo $ticker->askPrice;

// 拉取 500 根 1m K 线
$klines = $adapter->getKline('BTCUSDT', '1m', 500);
echo count($klines);        // 预期: ≤ 500，按 openTime 升序
echo $klines[0]->open;      // 预期: 非空 OHLCV 字段
```

**预期结果**：ticker 字段非空合理，bid < ask；kline 数组升序，OHLCV 字段非空。

## 场景 2: WS K 线 stream（公共，无需凭据）

验证 spec User Story 2（SC-002）。参考 Binance 的 `poc:verify-feed` 范式。

```bash
# 可选：运行 POC 验证命令（若实现）
php artisan poc:verify-okx-feed --symbol=BTCUSDT --interval=1m --wait=120

# 或手动 tinker 验证
php artisan tinker
```

```php
$adapter = app(\App\Domain\Exchange\ExchangeManager::class)->adapter('okx', 'spot');
$received = false;
$subId = $adapter->subscribeKlineStream('BTCUSDT', '1m', function ($kline) use (&$received) {
    $received = true;
    echo "bar closed: {$kline->symbol} O={$kline->open} C={$kline->close} T={$kline->openTime}\n";
});
// 等待 ≤120 秒（1 根 1m bar 关闭）
\Swoole\Coroutine::sleep(120);
$adapter->unsubscribeStream($subId);
echo $received ? 'PASS' : 'FAIL';
```

**预期结果**：≤120 秒内 onBar 回调触发，回调参数为 ExchangeKline DTO，symbol 为平台格式 BTCUSDT。

## 场景 3: 离线断言（无需网络）

验证 OKX 响应映射逻辑，无需真实网络（与 P5/P5.5 一致）。

```bash
# WSL 项目根
php artisan test --filter=OkxAdapterTest
```

**预期结果**：测试通过，覆盖：
- instId 归一化（BTCUSDT ↔ BTC-USDT 双向）
- ticker/kline 响应映射 DTO
- 订单状态映射（live→submitted、filled→filled 等）
- passphrase 缺失守卫
- 模拟盘 header 注入（testnet=true 时 x-simulated-trading:1）

## 场景 4: 模拟盘开关（待 demo key）

验证 spec User Story 4（SC-004），需 OKX demo 账户凭据。

```bash
# .env 配置
EXCHANGE_OKX_TESTNET=true
EXCHANGE_OKX_KEY=...        # demo 账户 key
EXCHANGE_OKX_SECRET=...
EXCHANGE_OKX_PASSPHRASE=...
```

```bash
php artisan tinker
```

```php
$adapter = app(\App\Domain\Exchange\ExchangeManager::class)->adapter('okx', 'spot', [
    'api_key' => env('EXCHANGE_OKX_KEY'),
    'api_secret' => env('EXCHANGE_OKX_SECRET'),
    'passphrase' => env('EXCHANGE_OKX_PASSPHRASE'),
]);
$balance = $adapter->getBalance();
echo $balance->available;   // 预期: 模拟盘账户可用 USDT 余额
```

**预期结果**：请求携带 `x-simulated-trading:1`，响应来自模拟盘账户。

## 场景 5: 真实下单（待 demo key，可选）

验证 spec User Story 3（SC-003），需 demo 凭据且端点可达。

```php
$order = $adapter->placeOrder('BTCUSDT', 'buy', 'market', '100', null, null, [
    'clientOrderId' => '1_1_'.time().'_'.bin2hex(random_bytes(4)),
]);
echo $order->exchangeOrderId;  // 预期: OKX 订单 ID
echo $order->status;          // 预期: filled 或 submitted
echo $order->clientOrderId;   // 预期: 与传入一致

// 按 clientOrderId 查回（崩溃恢复核实）
$found = $adapter->getOrderByClientOrderId('BTCUSDT', $order->clientOrderId);
echo $found ? 'FOUND' : 'NOT FOUND';  // 预期: FOUND
```

**预期结果**：订单成交，按 clientOrderId 可查回，余额变动反映该笔成交。

## 验收矩阵

| 场景 | spec 验收 | 依赖 | POC 阶段 |
|------|----------|------|---------|
| 1 REST 行情 | SC-001 | 网络 | 即时可验 |
| 2 WS bar | SC-002 | 网络 | 即时可验 |
| 3 离线断言 | FR-001~008 | 无 | 即时可验 |
| 4 模拟盘开关 | SC-004 | demo key + 网络 | 待 key |
| 5 真实下单 | SC-003 | demo key + 网络 | 待 key，可选 |

## 故障排查

- **OKX 端点不可达**：`nc -vz ws.okx.com 8443`；若主网拒绝，离线断言先行（场景 3），真实流待网络恢复后补（与 P5 Binance 主网不可达坑23 一致处理）。
- **签名失败（401）**：检查 timestamp ISO 8601 格式、prehash 含 method+requestPath+body、base64 输出、passphrase 非空。
- **instId 格式错误**：确保入参用平台 BTCUSDT 格式，适配器内部转 BTC-USDT；若 OKX 响应 symbol 含 `-` 未归一化，检查 mapXxx 方法。
- **WS 不推送**：检查 subscribe channel 命名（candle1m）、instId 格式（BTC-USDT）、30s 心跳超时重连。
