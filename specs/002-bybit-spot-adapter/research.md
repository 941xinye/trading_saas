# Research: Bybit v5 API 技术决策

**Feature**: 002-bybit-spot-adapter | **Date**: 2026-10-18
**Scope**: 解决 plan Technical Context 全部未知点；镜像 001 research.md 决策格式（Decision / Rationale / Alternatives）。
**来源**: Bybit v5 官方文档（bybit-exchange.github.io/docs/v5/intro）+ 001 OkxAdapter 实装范式 + clarify 4 条决策。

---

## R-001: 环境端点与 WS 路径

**Decision**: 生产 REST `https://api.bybit.com`、WS spot 公共流 `wss://stream.bybit.com/v5/public/spot`；testnet REST `https://api-testnet.bybit.com`、WS `wss://stream-testnet.bybit.com/v5/public/spot`。切换方式为基类既有 `restBaseUrl()/wsBaseUrl()` 的 `testnet_rest_url/testnet_ws_url` config 键整体替换，**不注入额外 header**。

**Rationale**: Bybit testnet 是完全独立域名（与 OKX 同域+`x-simulated-trading` header 机制根本不同）；基类 URL 解析逻辑（AbstractExchangeAdapter L219-252）天然支持，零改动。config 现有 `bybit.ws_url` 错指 `/v5/public/linear`（合约），本 feature 修正为 `/v5/public/spot`。

**Alternatives considered**: 复用 OKX 的 header 标识模式（拒绝——Bybit 无此机制）；适配器内自行拼 URL（拒绝——绕过基类模板违反 FR-005）。

---

## R-002: v5 签名构造（BybitRequestSigner）

**Decision**: HMAC-SHA256(secret, prehash) → **hex**。prehash = `timestamp + api_key + recv_window + payload`，其中 GET 请求 payload=queryString、POST 请求 payload=raw JSON body。请求头四件套 + sign-type：
- `X-BAPI-API-KEY`、`X-BAPI-SIGN`（hex）、`X-BAPI-TIMESTAMP`（毫秒字符串）、`X-BAPI-RECV-WINDOW`（默认 "5000"）
- `X-BAPI-SIGN-TYPE: 2`（HMAC-SHA256 标识）
- POST Content-Type `application/json`

**Rationale**: v5 官方认证规范；与 Binance（queryString&signature=hex，X-MBX-APIKEY）/OKX（timestamp+method+requestPath+body→base64，OK-ACCESS-*）形态差异完全内聚在 BybitRequestSigner 内（clarify Q1 完整请求签名策略）。

**Alternatives considered**: RSA 签名（拒绝——平台现有凭据模型为 HMAC key/secret 两段）；queryString 排序后签名（拒绝——v5 要求按实际发送串签名，与 Binance 坑"签名串=发送串"同理，序列化必须确定性且与发送一致）。

---

## R-003: REST 端点映射

**Decision**（全部 v5，category=spot）：

| 平台方法 | Bybit 端点 | 说明 |
|---|---|---|
| getTicker | GET `/v5/market/tickers?category=spot&symbol={s}` | result.list[0] |
| getKline | GET `/v5/market/kline?category=spot&symbol={s}&interval={i}&limit={n}` | limit ≤ 1000 |
| symbolFilters | GET `/v5/market/instruments-info?category=spot&symbol={s}` | tickSize/stepSize/minOrderQty；Redis 缓存 24h 镜像 OKX 范式 |
| getBalance | GET `/v5/account/wallet-balance?accountType=UNIFIED` | 签名 |
| placeOrder | POST `/v5/order/create` | 签名，JSON body，orderLinkId=clientOrderId |
| cancelOrder | POST `/v5/order/cancel` | 签名，orderId 或 orderLinkId |
| getOrder / getOrderByClientOrderId | GET `/v5/order/realtime` → 空则 GET `/v5/order/history` | 签名，见 R-005 |

**Rationale**: v5 统一账户（UNIFIED）下单是官方现货推荐路径；realtime+history 双端点是 v5 订单状态查询的既定形态。

**Alternatives considered**: accountType=SPOT 经典账户（拒绝——新注册 key 默认 UNIFIED，且 v5 文档以 UNIFIED 为主线）。

---

## R-004: interval 映射

**Decision**: 平台 `1m/3m/5m/15m/30m/1h/2h/4h/6h/12h/1d/1w` → Bybit kline interval `1/3/5/15/30/60/120/240/360/720/D/W`（REST 与 WS topic 同值）。映射表置于 BybitAdapter 私有方法，越界抛 ExchangeApiException（clientError）。

**Rationale**: Bybit v5 kline interval 为分钟数字符串或 D/W/M 字母，与平台 `1m`/`1h` 文本格式不同，边界内收敛转换（FR-012 只管 symbol 不管 interval，interval 转换是各家既有职责——OKX bar 参数同理）。

**Alternatives considered**: 直接透传平台格式（拒绝——Bybit 拒收 `1m`）。

---

## R-005: 订单状态双端点查询（崩溃恢复幂等）

**Decision**: getOrder / getOrderByClientOrderId 先查 `/v5/order/realtime`（活跃单：New/PartiallyFilled），空结果再查 `/v5/order/history`（终态单：Filled/Cancelled/Rejected，保留期近端可查）；两端皆空返回 null（FR-006 崩溃恢复核实语义）。

**Rationale**: v5 `/v5/order/realtime` 只返未完全成交单，已成交单立即移入 history；单查一端会漏单（001 OKX 的 pending 核实教训——查无此单必须是真"不存在"而非"查错端点"）。

**Alternatives considered**: 只查 history（拒绝——history 对刚提交单的可见性有延迟，崩溃恢复窗口内误判 null 会导致重复下单，违反 constitution IV 幂等）。

---

## R-006: WS kline 协议

**Decision**: 连接 `{wsBase}/v5/public/spot`；订阅帧 `{"op":"subscribe","args":["kline.{interval}.{symbol}"]}`（如 `kline.1.BTCUSDT`）；应用层心跳：每 20s 发 `{"op":"ping"}`，服务端回 `{"op":"pong"}`，超时未回判断线。kline 推送 `topic=kline.{i}.{s}`，`data[]` 元素含 `start/end/open/high/low/close/volume/turnover/confirm`；**bar 关闭判定 = `confirm:true`**（confirm:false 为 bar 内快照更新，不触发 onBar）。onBar 回调 ExchangeKline(openTime=start, closeTime=end, quoteVolume=turnover, tradesCount=0)。断线指数退避 1s→2s→4s…上限 60s，各分支 isCanceled 检查（坑2）。

**Rationale**: v5 kline 是增量快照流（约 250ms 推送周期），confirm 标志是官方 bar-close 语义；onBar 只在关闭时回调与 Binance `x:true`/OKX `confirm:1` 范式一致，BarAggregator 零改动。

**Alternatives considered**: 对 confirm:false 也回调（拒绝——破坏上层"每 bar 一次"语义）；依赖 Swoole 协议层 ping（拒绝——应用层 op:ping 是 v5 推荐且可探测半开连接）。

---

## R-007: 限流配置

**Decision**: config `exchange.exchanges.bybit.rate_limits` 分类：`public => 600/5s`（行情类，per IP）、`trade => 10/1s`（下单/撤单，per UID）、`account => 10/1s`（账户/订单查询，per UID）。复用基类 RateLimiter，窗口参数化（对齐 001 OKX 的 rate_limits 分类范式，非 Binance 1 分钟 weight 制）。

**Rationale**: v5 限流维度 = UID+endpoint 类别（官方 rate-limit 文档）；10/s 是 v5 现货下单默认上限，600/5s 是行情类 IP 上限。

**Alternatives considered**: 复用 Binance weight 制（拒绝——Bybit 无 weight 概念）。

---

## R-008: 错误码分类表（translateErrorCode Bybit 实现）

**Decision**: v5 响应 HTTP 200 + `retCode`/`retMsg` 业务错误结构（retCode=0 成功）。分类（rawCode 为 retCode 字符串）：

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
| 其他未知 | — | 保守 serverError 可重试（与 001 OKX 未知码策略一致） |

**Rationale**: 号段语义来自 v5 官方错误码文档；认证类硬失败与 Binance（-2014/-2015）/OKX（50111/50113）语义对齐，满足 FR-009"重试语义跨所一致"。

**Alternatives considered**: 未知码归 clientError（拒绝——未知码可能是新服务器错误，保守可重试更安全，与既有范式一致）。

**验证指针**: 实现期对照官方 v5 error-code 页核对上表逐条；cancel 场景 not-exist 码以 demo 实测为准（候选 170213）。

---

## R-009: RequestSigner 契约形态（clarify Q1 落地）

**Decision**:

```text
RequestSigner（接口）: signRequest(method, path, params, body, credentials, timestampMs): SignedRequest
SignedRequest（value object）: {headers: array<string,string>, query: string|null, body: string|null, contentType: string|null}
```

- GET 签名请求：策略产出 `query`（已含签名参数的完整 queryString），基类拼 `base+path+'?'+query` 发送。
- POST/DELETE 签名请求：策略产出 `body`（raw 串）+ `contentType`，基类 `withBody(body, contentType)` 发送。
- 公共请求不经过策略（doRequest 的 signed=false 分支不变）。
- 基类 `doRequest` 收敛为三家完全共用：限流 → （签名时委托策略） → HTTP → 健康埋点 → `extractErrorCode`/`translateErrorCode` 异常分类。Binance/OKX 的现有 doRequest/signQuery/httpHeaders override 全部删除迁入策略。
- `extractErrorCode(array $responseBody): ?string` 与 `translateErrorCode(string $rawCode, int $httpStatus): 'client'|'server'` 为基类抽象点（FR-009）；Binance 实现对 HTTP 4xx/5xx 现状行为逐一对齐（迁移回归门槛=既有测试绿，不改变 429 等现状分类）。

**Rationale**: SignedRequest 同时携带 query 与 body 通道，覆盖 Binance（URL query 签名）/OKX（raw JSON body）/Bybit（GET query / POST JSON body 双形态）三家差异；策略纯函数无状态，可独立单测（签名回归逐字节断言）。

**Alternatives considered**: 策略只返回 headers（拒绝——Binance 签名在 URL/Body 而非 header，表达力不足）；策略直接发请求（拒绝——绕过基类限流/健康埋点，违反 FR-005）。

---

## R-010: 余额映射（wallet-balance UNIFIED）

**Decision**: `GET /v5/account/wallet-balance?accountType=UNIFIED` → `result.list[0].coin[]`；ExchangeBalance.available = `walletBalance - locked`（bcmath bcsub），total = `walletBalance`；仅返 platform 计价所需币种（非空条目全返，上层自选）。无凭据/凭据缺段 → 签名前置抛 ExchangeApiException（FR-007）。

**Rationale**: UNIFIED 账户 coin 条目含 walletBalance/locked 字段，available=walletBalance-locked 是 v5 现货可用余额官方语义。

**Alternatives considered**: 用 availableToWithdraw（拒绝——该字段含借贷抵扣语义，现货场景不等于可用余额）。

---

## R-011: 市价买单报价币数量

**Decision**: placeOrder 平台语义 `placeOrder(symbol, 'buy', 'market', '100')` = 花 100 USDT 买 BTC（与 001 OKX `tgtCcy=quote_ccy` 对齐）→ Bybit body 增加 `marketUnit: 'quoteCoin'`，qty 传报价币金额；市价卖单传 base 数量（不加 marketUnit）。限价单 qty=base 数量 + price 必传 + `timeInForce: 'GTC'`（默认）。`orderLinkId` = clientOrderId（≤36 字符，v5 限制；clientOrderId 模板 `{tenant}_{instance}_{ts}_{rand}` 实现期按 36 字符截断/压缩——plan 期记录此约束）。

**Rationale**: marketUnit=quoteCoin 是 v5 市价单按报价币计价的官方参数；orderLinkId 幂等对应 v5 官方 clientOrderId 字段。

**Alternatives considered**: 市价买传 base 数量由上层换算（拒绝——价格滑点导致换算误差，报价币模式与 OKX 既有语义一致）。

---

## R-012: K 线响应排序与字段

**Decision**: `/v5/market/kline` result.list 为**倒序（最新在前）**，元素 `[startTime, open, high, low, close, volume, turnover]`；适配器反转为 openTime 升序（与 getKline 契约一致）；closeTime=openTime+intervalMs（v5 不返 closeTime，同 OKX 推算范式）；quoteVolume=turnover；tradesCount=0（v5 不返）。`end` 参数不传（取最新 N 根）。

**Rationale**: v5 kline 响应形态；升序契约是 ExchangeKline 数组的消费者约定（001 已确立）。

**Alternatives considered**: 传 start/end 窗口（拒绝——POC 只需最新 N 根，窗口查询留后续）。

---

## 决策汇总

| ID | 决策 | 状态 |
|---|---|---|
| R-001 | testnet 独立域名 + config 键整体替换，ws_url 修正 /v5/public/spot | Resolved |
| R-002 | v5 prehash=timestamp+api_key+recv_window+payload → hex，X-BAPI-* 四件套 | Resolved |
| R-003 | REST 端点映射表（category=spot，UNIFIED） | Resolved |
| R-004 | interval 分钟数字符串映射表 | Resolved |
| R-005 | 订单双端点 realtime→history 查询 | Resolved |
| R-006 | WS kline confirm:true 判 bar 关闭，op:ping 20s 心跳 | Resolved |
| R-007 | rate_limits 三类（600/5s、10/s、10/s） | Resolved |
| R-008 | retCode 分类表（含 10002 时钟硬失败、170213 cancel 特判） | Resolved（实现期逐条核对官方页） |
| R-009 | RequestSigner/SignedRequest 契约 + 基类抽象点 | Resolved |
| R-010 | available=walletBalance-locked | Resolved |
| R-011 | marketUnit=quoteCoin 市价买；orderLinkId ≤36 字符约束 | Resolved |
| R-012 | kline 倒序反升序、closeTime 推算 | Resolved |
