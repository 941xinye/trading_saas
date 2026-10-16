# OKX V5 API Contract

**Date**: 2026-10-16 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/spec.md)

OKX 适配器对外契约（ExchangeAdapter 接口）不变，对内对接 OKX V5 REST/WS。本文件记录 OKX V5 端点契约。

## REST 端点

### 公共（无需签名）

| 方法 | 路径 | 参数 | 权重 | → 平台方法 |
|------|------|------|------|-----------|
| GET | /api/v5/market/ticker | instId=BTC-USDT | 1（public 20/2s） | getTicker |
| GET | /api/v5/market/candles | instId=BTC-USDT&bar=1m&limit=500 | 1（public 20/2s） | getKline |
| GET | /api/v5/public/instruments | instType=SPOT&instId=BTC-USDT | 1（public 20/2s） | symbolFilters |

### 签名（需 OK-ACCESS-* header）

| 方法 | 路径 | 参数 | 权重 | → 平台方法 |
|------|------|------|------|-----------|
| GET | /api/v5/account/balance | （无） | 1（account 10/2s） | getBalance |
| POST | /api/v5/trade/order | body: instId/side/ordType/sz/px?/clOrdId? | 1（trade 60/2s） | placeOrder |
| DELETE | /api/v5/trade/order | body: instId/ordId?/clOrdId? | 1（trade 60/2s） | cancelOrder |
| GET | /api/v5/trade/order | instId&ordId | 1（trade 60/2s） | getOrder |
| GET | /api/v5/trade/order | instId&clOrdId | 1（trade 60/2s） | getOrderByClientOrderId |

## 认证

### Header 四件套（签名请求）

```
OK-ACCESS-KEY: {api_key}
OK-ACCESS-SIGN: {base64(HMAC-SHA256(secret, timestamp+method+requestPath+body))}
OK-ACCESS-TIMESTAMP: {ISO 8601 UTC, e.g. 2026-10-16T12:00:00.000Z}
OK-ACCESS-PASSPHRASE: {passphrase}
```

### 模拟盘 header

```
x-simulated-trading: 1   # 仅 testnet=true 时注入，REST 模拟盘切换
```

### 签名 prehash 构造

```
prehash = timestamp + method + requestPath + body
# timestamp: ISO 8601 字符串
# method: 大写 HTTP 方法（GET/POST/DELETE）
# requestPath: 含 query string 的路径（GET: /api/v5/trade/order?instId=BTC-USDT&clOrdId=xxx）
# body: POST/DELETE 的 raw body（JSON 字符串），GET 为空字符串
sign = base64_encode(hash_hmac('sha256', prehash, secret, raw_binary))
```

注意：与 Binance 的 `queryString&signature` 根本不同——OKX prehash 含 method 与 requestPath（含 query），body 是 raw JSON 非 form-encoded。

## WebSocket

### 连接

| 环境 | URL |
|------|-----|
| 生产公共 | wss://ws.okx.com:8443/ws/v5/public |
| 模拟盘公共 | wss://wspap.okx.com:8443/ws/v5/public |

公共流无需 login，直接 subscribe。

### 订阅 K 线

```json
{"op":"subscribe","args":[{"channel":"candle1m","instId":"BTC-USDT"}]}
```

channel 命名：`candle{interval}`（candle1m/candle5m/candle15m...），与平台 interval 命名基本一致（去 `m` 前缀差异由适配器处理）。

### 推送格式

```json
{
  "arg": {"channel":"candle1m","instId":"BTC-USDT"},
  "data": [["1697443200000","0.001","0.002","0.0005","0.0015","100","0.1","1","1","0","0","0"]]
}
```

data[0] 数组字段：[ts, open, high, low, close, vol, volCcy, volCcyQuote, confirm, ...]
- [0] ts（毫秒，openTime）
- [1-4] OHLC
- [5] base 成交量
- [6] quote 成交量
- [8] confirm：0=未关闭，1=已关闭（bar close 信号，对应 Binance k.x）

### 心跳

OKX 每 30s 发 ping，需回 pong（Swoole WsClient 自动处理）。30s 无消息视为断线，重连（与 Binance heartbeat_timeout=30 一致）。

## 限流（分类别 2s 窗口）

| 类别 | 限制 | 维度 |
|------|------|------|
| 公共行情 | 20 req/2s | per IP |
| 账户 | 10 req/2s | per UID |
| 下单 | 60 req/2s | per UID |
| 撤单 | 60 req/2s | per UID |

适配器复用 RateLimiter，按 `okx:public` / `okx:account` / `okx:trade` 分桶，窗口 2s，权重 1。
