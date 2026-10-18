# Bitget V2 API Contract

**Date**: 2026-10-18 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/spec.md)

Bitget 适配器对外契约（ExchangeAdapter 接口）不变，对内对接 Bitget V2 REST/WS。本文件记录 Bitget V2 端点契约（决策来源：[research.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/research.md) R-001~R-011）。证据分级：「已核实」= 双来源一致；「候选待核」= 实现期对照官方文档逐条核对 + POC 实测佐证。

## REST 端点

### 公共（无需签名）

| 方法 | 路径 | 参数 | 限流类别 | → 平台方法 |
|------|------|------|---------|-----------|
| GET | /api/v2/spot/market/tickers | symbol=BTCUSDT | public（20/s per IP，已核实） | getTicker |
| GET | /api/v2/spot/market/candles | symbol=BTCUSDT&granularity=1min&limit=500 | public | getKline |
| GET | /api/v2/spot/public/symbols | symbol=BTCUSDT | public | symbolFilters |

### 签名（需 ACCESS-* header）

| 方法 | 路径 | 参数 | 限流类别 | → 平台方法 |
|------|------|------|---------|-----------|
| GET | /api/v2/spot/account/assets | （可选 coin 过滤） | account（10/s per UID，候选待核） | getBalance |
| POST | /api/v2/spot/trade/place-order | body: symbol/side/orderType/size/price?/force?/clientOid? | trade（10/s per UID，候选待核） | placeOrder |
| POST | /api/v2/spot/trade/cancel-order | body: symbol/orderId?/clientOid? | trade | cancelOrder |
| GET | /api/v2/spot/trade/orderInfo | symbol&orderId?/clientOid? | account | getOrder / getOrderByClientOrderId（主查，R-005） |
| GET | /api/v2/spot/trade/history-orders | symbol&orderId?/clientOid? | account | getOrder / getOrderByClientOrderId（orderInfo 空则回查，R-005） |

### 响应样例

ticker（data[0] 关键字段，字段名候选待核）：

```json
{"code":"00000","msg":"success","data":[{"symbol":"BTCUSDT","lastPr":"65000.1","bidPr":"65000.0","askPr":"65000.2","baseVolume":"1234.567","quoteVolume":"80000000.5","change24h":"0.0123","ts":"1697443200000"}]}
```

kline（data 元素为数组，**排序方向候选待核→适配器排序后升序**，R-011）：

```json
{"code":"00000","msg":"success","data":[["1697443200000","65000","65100","64900","65050","12.5","812500"]]}
```

place-order 请求 body（市价买单，报价币计价，R-009）：

```json
{"symbol":"BTCUSDT","side":"buy","orderType":"market","size":"100","clientOid":"1_1_1697443200_ab12"}
```

place-order 响应：

```json
{"code":"00000","msg":"success","data":{"orderId":"1635000000000123","clientOid":"1_1_1697443200_ab12"}}
```

orderInfo / history-orders 响应（data 关键字段，字段名候选待核）：

```json
{"code":"00000","msg":"success","data":[{"orderId":"1635000000000123","clientOid":"1_1_1697443200_ab12","symbol":"BTCUSDT","side":"buy","orderType":"market","size":"100","price":"","status":"filled","baseVolume":"0.001538","priceAvg":"65010.5","fee":"0.0000015","cTime":"1697443200000","uTime":"1697443201000"}]}
```

## 认证

### Header 四件套（签名请求，R-002，头名已核实）

```
ACCESS-KEY: {api_key}
ACCESS-SIGN: {base64(HMAC-SHA256(secret, timestamp+METHOD+requestPath(+?queryString)+body))}
ACCESS-TIMESTAMP: {UTC 毫秒字符串, e.g. 1697443200000}
ACCESS-PASSPHRASE: {passphrase}
```

**无模拟盘切换**：Bitget 现货无公开 testnet/模拟盘（澄清决定 A），config 不含 testnet 键，签名头不附加任何环境标识（与 OKX `x-simulated-trading`、Bybit testnet 独立域名机制均不同）。

### 签名 prehash 构造

```
prehash = timestamp + METHOD + requestPath(+?queryString) + body
# timestamp: UTC 毫秒字符串（与 ACCESS-TIMESTAMP 同值）
# METHOD: 大写（GET/POST）
# requestPath: 如 /api/v2/spot/trade/place-order；GET/DELETE 带参时拼 ?queryString（与实际发送串一致）
# body: GET 恒空串；POST = raw JSON（与实际发送串逐字节一致）
sign = base64_encode(hash_hmac('sha256', prehash, secret, raw_binary))
```

注意：与 Binance 的 `queryString&signature`（hex）、OKX 的 `ISO8601 timestamp+method+requestPath+body`（base64）、Bybit 的 `timestamp+api_key+recv_window+payload`（hex）均不同——Bitget prehash 含 queryString 段、timestamp 为毫秒整数串、摘要输出 **base64**。POST Content-Type `application/json`。序列化必须确定性且与实际发送串逐字节一致（「签名串=发送串」原则）。三段凭据（api_key/api_secret/passphrase）任一段缺失前置抛 ExchangeApiException（FR-006，不占限流额度）。

## 错误响应结构（FR-008 extractErrorCode 数据源）

V2 响应恒 HTTP 200 + 业务错误体（**code "00000" 成功**，已核实）：

```json
{"code":"40009","msg":"Signature verification failed","data":null}
```

code 分类表（R-008，translateErrorCode Bitget 实现；码段全部候选待核，实现期对照官方错误码页逐条核对）：

| code | 语义 | 分类 |
|---|---|---|
| 40001 | 参数非法 | clientError 硬失败 |
| 40009 | 签名不匹配 | clientError 硬失败 |
| 40011 | passphrase 错误 | clientError 硬失败 |
| 40012 | API key 无效 | clientError 硬失败 |
| 40013 | timestamp 超窗 | clientError 硬失败 + 时钟校准提示（与 Bybit 10002 语义对齐） |
| 40014 | 权限不足 | clientError 硬失败 |
| 40018 | IP 白名单不匹配 | clientError 硬失败 |
| 40725 | 触发限流 | serverError 可重试 |
| 43001 | 订单不存在（cancel 已成交/已撤） | clientError（cancelOrder 特判 → 返回 false，US3 场景3） |
| 其他未知 | — | 保守 serverError 可重试（与前三家未知码策略一致） |

## WebSocket

### 连接

| 环境 | URL |
|------|-----|
| 生产公共 | wss://ws.bitget.com/v2/ws/public |

无 testnet 环境（澄清决定 A）。公共流无需 login，直接 subscribe（已核实）。

### 订阅 K 线（R-006）

```json
{"op":"subscribe","args":[{"instType":"SPOT","channel":"candle1m","instId":"BTCUSDT"}]}
```

频道命名：`candle{i}`（candle1m/candle5m/...，已核实命名系，R-004 映射表）；instId 即平台格式 BTCUSDT。

### 推送格式

```json
{
  "action": "update",
  "arg": {"instType":"SPOT","channel":"candle1m","instId":"BTCUSDT"},
  "data": [["1697443200000","65000","65100","64900","65050","12.5","812500"]],
  "ts": 1697443260000
}
```

- **无官方 bar-close 标志**（与前三家 confirm/x 不同，本 spec 最大技术风险点）：bar 关闭判定 = **bar 切换检测**（R-006）——首帧 action=snapshot 只播种 lastBarOpenTime 不回调；update 帧 bar ts（data[0][0]）大于 lastBarOpenTime 时判定上一 bar 关闭，用缓存的上一 bar 完整数据触发 onBar；同 bar 重复 update 不触发
- 字段映射：openTime=[0]，OHLC=[1..4]，volume=[5]，quoteVolume=[6]，closeTime=openTime+intervalMs，tradesCount=0
- **验证指针**：实现期对照官方 WS 文档核对帧结构 + POC 实测捕获连续帧佐证判定语义（证据记录于 quickstart）；若实测发现 confirm 类标志则退化为 confirm 过滤（与前三家同构）

### 心跳（R-006，已核实机制）

应用层心跳为**文本帧**：客户端发字符串 `ping`，服务端回字符串 `pong`（与前三家 JSON op 帧不同）；周期 25s（analyze F5 定值），超时未回判断线。断线指数退避 1s→2s→4s…上限 60s 自动重连，各重连分支显式 `Coroutine::isCanceled()` 检查（坑2：cancel 非 sticky，异常被吞后协程不退出）。

## 限流（R-007）

| 类别 | 限制 | 维度 | 证据 |
|------|------|------|------|
| 公共行情 | 20 req/s | per IP | 已核实 |
| 下单/撤单 | 10 req/s | per UID | 候选待核 |
| 账户/订单查询 | 10 req/s | per UID | 候选待核 |

适配器复用基类 RateLimiter，按 `bitget:public` / `bitget:account` / `bitget:trade` 分桶，窗口参数化（1s/1s/1s，config `rate_limits` 结构镜像 002 bybit 段 `['limit'=>N,'window'=>S]`）；V2 无 weight 概念，每请求计 1。超限响应码候选 40725 → serverError 可重试。
