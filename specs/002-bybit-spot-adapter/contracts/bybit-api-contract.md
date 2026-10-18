# Bybit V5 API Contract

**Date**: 2026-10-18 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/spec.md)

Bybit 适配器对外契约（ExchangeAdapter 接口）不变，对内对接 Bybit V5 REST/WS。本文件记录 Bybit V5 端点契约（决策来源：[research.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/research.md) R-001~R-008、R-011、R-012）。

## REST 端点

### 公共（无需签名）

| 方法 | 路径 | 参数 | 限流类别 | → 平台方法 |
|------|------|------|---------|-----------|
| GET | /v5/market/tickers | category=spot&symbol=BTCUSDT | public（600/5s per IP） | getTicker |
| GET | /v5/market/kline | category=spot&symbol=BTCUSDT&interval=1&limit=500 | public | getKline |
| GET | /v5/market/instruments-info | category=spot&symbol=BTCUSDT | public | symbolFilters |

### 签名（需 X-BAPI-* header）

| 方法 | 路径 | 参数 | 限流类别 | → 平台方法 |
|------|------|------|---------|-----------|
| GET | /v5/account/wallet-balance | accountType=UNIFIED | account（10/s per UID） | getBalance |
| POST | /v5/order/create | body: category/symbol/side/orderType/qty/price?/marketUnit?/orderLinkId?/timeInForce? | trade（10/s per UID） | placeOrder |
| POST | /v5/order/cancel | body: category/symbol/orderId?/orderLinkId? | trade | cancelOrder |
| GET | /v5/order/realtime | category=spot&symbol&orderId?/orderLinkId? | account | getOrder / getOrderByClientOrderId（先查，R-005） |
| GET | /v5/order/history | category=spot&symbol&orderId?/orderLinkId? | account | getOrder / getOrderByClientOrderId（realtime 空则回查，R-005） |

### 响应样例

ticker（result.list[0] 关键字段）：

```json
{"retCode":0,"retMsg":"OK","result":{"category":"spot","list":[{"symbol":"BTCUSDT","lastPrice":"65000.1","bid1Price":"65000.0","ask1Price":"65000.2","volume24h":"1234.567","turnover24h":"80000000.5","price24hPcnt":"0.0123"}]},"time":1697443200000}
```

kline（result.list **倒序**，元素为数组，R-012）：

```json
{"retCode":0,"result":{"category":"spot","symbol":"BTCUSDT","list":[["1697443200000","65000","65100","64900","65050","12.5","812500"]]},"time":1697443200001}
```

order/create 请求 body（市价买单，报价币计价，R-011）：

```json
{"category":"spot","symbol":"BTCUSDT","side":"Buy","orderType":"Market","qty":"100","marketUnit":"quoteCoin","orderLinkId":"1_1_1697443200_ab12"}
```

order/create 响应：

```json
{"retCode":0,"retMsg":"OK","result":{"orderId":"1635000000000123","orderLinkId":"1_1_1697443200_ab12"},"time":1697443200002}
```

order/realtime|history 响应（result.list[0] 关键字段）：

```json
{"retCode":0,"result":{"category":"spot","list":[{"orderId":"1635000000000123","orderLinkId":"1_1_1697443200_ab12","symbol":"BTCUSDT","side":"Buy","orderType":"Market","qty":"100","price":"","orderStatus":"Filled","cumExecQty":"0.001538","avgPrice":"65010.5","cumExecFee":"0.0000015","createdTime":"1697443200000","updatedTime":"1697443201000"}]},"time":1697443201001}
```

## 认证

### Header 五件套（签名请求，R-002）

```
X-BAPI-API-KEY: {api_key}
X-BAPI-SIGN: {hex(HMAC-SHA256(secret, timestamp+api_key+recv_window+payload))}
X-BAPI-TIMESTAMP: {UTC 毫秒字符串, e.g. 1697443200000}
X-BAPI-RECV-WINDOW: 5000   # 默认，平台 Assumption
X-BAPI-SIGN-TYPE: 2        # HMAC-SHA256 标识
```

### 模拟盘切换（R-001）

testnet 为**完全独立域名**，config `testnet_rest_url/testnet_ws_url` 键整体替换 base URL，**不注入额外 header**（与 OKX `x-simulated-trading` 机制根本不同）。

### 签名 prehash 构造

```
prehash = timestamp + api_key + recv_window + payload
# timestamp: UTC 毫秒字符串（与 X-BAPI-TIMESTAMP 同值）
# payload: GET = queryString（与实际发送串一致）；POST = raw JSON body（与实际发送串一致）
# recv_window: 与 X-BAPI-RECV-WINDOW 同值
sign = bin2hex(hash_hmac('sha256', prehash, secret, raw_binary))
```

注意：与 Binance 的 `queryString&signature`（hex）、OKX 的 `timestamp+method+requestPath+body`（base64）均不同——Bybit prehash 含 api_key 与 recv_window，摘要输出 **hex 非 base64**。POST Content-Type `application/json`。序列化必须确定性且与实际发送串逐字节一致（同 Binance 坑"签名串=发送串"）。

## 错误响应结构（FR-009 extractErrorCode 数据源）

v5 响应恒 HTTP 200 + 业务错误体（retCode=0 成功）：

```json
{"retCode":10004,"retMsg":"error sign!","result":{},"time":1697443200003}
```

retCode 分类表（R-008，translateErrorCode Bybit 实现）：

| retCode | 语义 | 分类 |
|---|---|---|
| 10002 | timestamp/recv_window 超窗 | clientError 硬失败 + 时钟校准提示（clarify Q2） |
| 10003 | API key 无效 | clientError 硬失败 |
| 10004 | 签名不匹配 | clientError 硬失败 |
| 10005 | 权限不足 | clientError 硬失败 |
| 10010 | IP 白名单不匹配 | clientError 硬失败 |
| 10006 | 触发限流 | serverError 可重试 |
| 10016 | 服务器繁忙/内部错误 | serverError 可重试 |
| 110001 | 参数非法 | clientError 硬失败 |
| 110004 | 余额不足 | clientError 硬失败 |
| 170213 | 订单不存在（cancel 已成交/已撤） | clientError（cancelOrder 特判 → 返回 false，US3 场景3） |
| 其他未知 | — | 保守 serverError 可重试（与 001 未知码策略一致） |

实现期对照官方 v5 error-code 页逐条核对；cancel 场景 not-exist 码以 demo 实测为准（候选 170213）。

## WebSocket

### 连接

| 环境 | URL |
|------|-----|
| 生产公共 | wss://stream.bybit.com/v5/public/spot |
| 模拟盘公共 | wss://stream-testnet.bybit.com/v5/public/spot |

公共流无需 login，直接 subscribe。

### 订阅 K 线（R-006）

```json
{"op":"subscribe","args":["kline.1.BTCUSDT"]}
```

topic 命名：`kline.{interval}.{symbol}`，interval 为 Bybit 分钟数/D/W（与 REST 同映射，R-004）；symbol 即平台格式 BTCUSDT。

### 推送格式

```json
{
  "topic": "kline.1.BTCUSDT",
  "type": "snapshot",
  "ts": 1697443260000,
  "data": [{
    "start": 1697443200000, "end": 1697443259999,
    "open": "65000", "high": "65100", "low": "64900", "close": "65050",
    "volume": "12.5", "turnover": "812500",
    "confirm": true, "timestamp": 1697443260000
  }]
}
```

- **confirm:true = bar 关闭**（触发 onBar）；confirm:false = bar 内快照更新（约 250ms 周期，不回调）
- 字段映射：openTime=start，closeTime=end，quoteVolume=turnover，tradesCount=0

### 心跳（R-006）

应用层心跳：每 20s 发 `{"op":"ping"}`，服务端回 `{"op":"pong"}`；超时未回判断线（可探测半开连接，优于纯协议层 ping）。断线指数退避 1s→2s→4s…上限 60s 自动重连，各重连分支显式 `Coroutine::isCanceled()` 检查（坑2）。

## 限流（R-007）

| 类别 | 限制 | 维度 |
|------|------|------|
| 公共行情 | 600 req/5s | per IP |
| 下单/撤单 | 10 req/s | per UID |
| 账户/订单查询 | 10 req/s | per UID |

适配器复用基类 RateLimiter，按 `bybit:public` / `bybit:account` / `bybit:trade` 分桶，窗口参数化（5s/1s/1s）；v5 无 weight 概念，每请求计 1。
