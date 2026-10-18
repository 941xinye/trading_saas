# Research: Bitget V2 API 技术决策

**Feature**: 003-bitget-spot-adapter | **Date**: 2026-10-18
**Scope**: 解决 plan Technical Context 全部未知点；镜像 001/002 research.md 决策格式（Decision / Rationale / Alternatives）。
**来源**: Bitget V2 官方文档（bitget.com/api-doc，SPA 页本地抓取受限，经 pkg.go.dev Go SDK 常量页交叉核实）+ 前三家适配器实装范式 + clarify 决定 A。
**证据分级**: 「已核实」= 两个独立来源一致；「候选待核」= 单一来源或二手转述，实现期对照官方文档逐条核对 + POC 实测佐证（与 002 R-008 既定模式一致）。

---

## R-001: 端点、凭据形态与无 testnet 边界

**Decision**: 生产 REST `https://api.bitget.com`、WS 公共流 `wss://ws.bitget.com/v2/ws/public`（已核实）。config `exchange.exchanges.bitget` 段**不含 testnet 键**（澄清决定 A：Bitget 现货无公开 testnet/模拟盘，仅合约有 demo trading）；端点 env 化（`EXCHANGE_BITGET_REST`/`EXCHANGE_BITGET_WS`），风格与前三家可读性对齐。凭据为**三段**：api_key/api_secret/passphrase（已核实，与 OKX 同构），加密存储复用 exchange_accounts 既有结构（OKX 三段先例），本 spec 不动存储层。

**Rationale**: 无可用模拟盘却引入开关 = 永久不可用的死配置（FR-013）；三段凭据是 Bitget V2 官方认证形态，passphrase 段缺失即前置失败（FR-006）。

**Alternatives considered**: 沿用 OKX 同域+header 模拟盘模式（拒绝——Bitget 现货无此机制）；引入 testnet 键占位（拒绝——澄清决定 A 明确否决，config 最干净优先）。

---

## R-002: V2 签名构造（BitgetRequestSigner）

**Decision**: HMAC-SHA256(secret, prehash) → **base64**。prehash = `timestamp(ms 字符串) + METHOD(大写) + requestPath(+?queryString) + body`，其中 GET/DELETE 请求 requestPath 后拼 `?queryString`（无参则不拼）、body 恒空串；POST 请求 body = raw JSON（与发送串逐字节一致，json_encode 确定性序列化）。请求头四件套（已核实头名）：
- `ACCESS-KEY`、`ACCESS-SIGN`（base64）、`ACCESS-TIMESTAMP`（毫秒字符串）、`ACCESS-PASSPHRASE`
- POST Content-Type `application/json`
- **无 testnet 附加头**（与 OKX `x-simulated-trading` 机制不同，R-001 边界）

**Rationale**: Bitget V2 官方认证规范；与 Binance（queryString+HMAC hex）/OKX（ISO8601 timestamp+method+requestPath+body→base64）/Bybit（timestamp+api_key+recv_window+payload→hex）形态差异完全内聚在 BitgetRequestSigner 内（spec 002 RequestSigner 契约的第四次落地，验证"纯增量"架构承诺）。「签名串=发送串」原则同 Binance 坑教训：queryString/body 必须确定性序列化且与实际发送一致。

**Alternatives considered**: timestamp 用 ISO8601（拒绝——那是 OKX 形态，Bitget 为毫秒整数串，已核实）；复用 OkxRequestSigner 改头名（拒绝——prehash 形态不同：Bitget 含 queryString 段、timestamp 形态不同，独立实现内聚差异）。

**验证指针**: 实现期以离线断言固定一组已知输入的签名输出（黄金样本），POC 真实下单前用官方文档示例逐字节核对 prehash 构造。

---

## R-003: REST 端点映射

**Decision**（全部 V2，`/api/v2/spot` 前缀已核实）：

| 平台方法 | Bitget 端点 | 说明 |
|---|---|---|
| getTicker | GET `/api/v2/spot/market/tickers?symbol={s}` | data[0] |
| getKline | GET `/api/v2/spot/market/candles?symbol={s}&granularity={g}&limit={n}` | granularity 见 R-004 |
| symbolFilters | GET `/api/v2/spot/public/symbols?symbol={s}` | tickSize/stepSize/minOrderQty；Redis 缓存 24h 镜像前三家范式 |
| getBalance | GET `/api/v2/spot/account/assets` | 签名，见 R-010 |
| placeOrder | POST `/api/v2/spot/trade/place-order` | 签名，JSON body，clientOid=clientOrderId |
| cancelOrder | POST `/api/v2/spot/trade/cancel-order` | 签名，orderId 或 clientOid |
| getOrder / getOrderByClientOrderId | GET `/api/v2/spot/trade/orderInfo` → 兜底 GET `/api/v2/spot/trade/history-orders` | 签名，见 R-005 |

**Rationale**: V2 统一路径前缀 `/api/v2/spot`（已核实）；market/account/trade 三类子路径为官方资源划分。

**Alternatives considered**: V1 端点混用（拒绝——V2 是官方现行主线，adapter 骨架 docblock 已锚定 V2）。

**验证指针**: 各端点必填参数集实现期对照官方文档逐条核对（尤其 place-order 的 force/size 语义、orderInfo 的查询参数二选一约束）。

---

## R-004: granularity 映射（interval 转换）

**Decision**: 平台 interval → Bitget 双形态（REST 参数与 WS 频道名不同值）：

| 平台 | REST granularity | WS 频道 |
|---|---|---|
| 1m | `1min` | `candle1m` |
| 5m | `5min` | `candle5m` |
| 15m | `15min` | `candle15m` |
| 30m | `30min` | `candle30m` |
| 1h | `1h` | `candle1h` |
| 4h | `4h` | `candle4h` |
| 1d | `1day` | `candle1day` |
| 3m/2h/6h/12h/1w | 候选不支持 | 候选不支持 |

越界 interval 抛 ExchangeApiException（clientError）——与 002 Bybit R-004 同范式。映射表置于 BitgetAdapter 私有方法，DTO 层始终为平台格式。

**Rationale**: candle1m 系频道命名已核实（Go SDK 常量页）；REST `1min` 系参数形态为候选待核（单一来源）。3m/2h 等档位 Bitget 是否支持候选待核——若官方文档证实支持 6h/12h/1week 则实现期补入映射表，本表先按最小确定集记录。

**Alternatives considered**: 全量平台 interval 强行映射（拒绝——不支持的档位发出无效请求违反"前置失败"原则）。

**验证指针**: 实现期对照官方 candles 文档核对 granularity 全集；POC 只用 1m（spec 验收范围），其余档位以离线断言覆盖映射函数。

---

## R-005: 订单查询 orderInfo clientOid（崩溃恢复幂等）

**Decision**: getOrderByClientOrderId 主查 `GET /api/v2/spot/trade/orderInfo?clientOid={id}`；返回"订单不存在"类错误（候选码 43001）或空数据时，兜底查 `GET /api/v2/spot/trade/history-orders?clientOid={id}`；两端皆空返回 null（真"不存在"，FR-005 崩溃恢复核实语义）。getOrder(orderId) 同构（orderId 参数）。

**Rationale**: 002 Bybit R-005 教训——终态单可能从活跃查询端点立即移出，单查一端会漏单导致崩溃恢复误判 null 重复下单（违反 constitution IV 幂等）。Bitget orderInfo 对终态单的可见窗口候选待核，故按"主查+history 兜底"双查结构先行防御，形态与 Bybit realtime→history 一致。

**Alternatives considered**: 只查 orderInfo（拒绝——终态单可见窗口未证实，崩溃恢复窗口内误判风险不可接受）；查 unfilled-orders 列表（拒绝——该端点面向活跃单列表，按 clientOid 精确查应走 orderInfo）。

**验证指针**: 真实下单补测时实测"成交后立即 orderInfo 查询"行为，若终态单即时可见则可退化为单查（记录于实现期注释）。

---

## R-006: WS kline 协议与 bar 关闭判定（本 spec 最大技术风险点）

**Decision**: 连接 `wss://ws.bitget.com/v2/ws/public`；订阅帧：

```json
{"op":"subscribe","args":[{"instType":"SPOT","channel":"candle1m","instId":"BTCUSDT"}]}
```

- 应用层心跳：**文本帧 ping/pong**（已核实——客户端发文本 `ping`，服务端回文本 `pong`；与前三家 JSON op 帧或协议层 ping 均不同）。周期 25s（analyze F5 定值：低于官方常见 30s 断开阈值留余量），超时未回判断线。
- kline 推送 `arg.channel=candle{i}`，`data[]` 元素为数组形态 `[ts, open, high, low, close, baseVolume, quoteVolume]`（ts 为 bar 开盘毫秒）。
- **bar 关闭判定 = bar 切换检测**（候选待核，无 confirm 标志）：首帧 snapshot（action=snapshot）只播种 `lastBarOpenTime` 不回调；后续 update 帧中 bar ts 大于 `lastBarOpenTime` 时，判定上一 bar 已关闭 → 用缓存的上一 bar 完整数据触发 onBar，并推进 `lastBarOpenTime`。同一 bar 内的重复 update（ts 相等）不触发。
- 断线指数退避 1s→2s→4s…上限 60s，各分支 isCanceled 检查（坑2 教训，cancel 非 sticky）。

**Rationale**: Bitget V2 candle 流无官方 bar-close 标志（与 Bybit confirm/Binance x/OKX confirm 不同），「新 bar 出现 ⇒ 旧 bar 关闭」是 SDK 社区通行判定法；首帧 snapshot 不回调保持上层"每 bar 一次"语义（BarAggregator 零改动）。WS 心跳文本帧 ping/pong 已核实（Go SDK 常量 `ping`/`pong` 字符串字面量）。

**Alternatives considered**: 对所有 update 帧回调（拒绝——破坏"每 bar 一次"语义）；按本地时钟整分钟判定关闭（拒绝——时钟漂移与网络延迟导致边界抖动，bar 切换检测以服务端推送为准更可靠）。

**验证指针（关键）**: 本判定语义是 US2 场景4 的验收依赖，实现期必须：①对照官方 WS 文档核对 candle 推送帧结构；②POC 实测捕获连续帧，确认「snapshot 首帧 + update 增量 + bar 切换时新 ts 出现」行为序列，证据记录于 quickstart 故障排查节。若实测发现官方存在 confirm 类标志，则退化为 confirm 过滤（与前三家同构，实现更简单）。

---

## R-007: 限流配置

**Decision**: config `exchange.exchanges.bitget.rate_limits` 分类（镜像 002 bybit 段 `['limit'=>N,'window'=>S]` 结构）：
- `public => ['limit' => 20, 'window' => 1]`（行情类，per IP，**已核实** 20 times/1s per IP）
- `trade => ['limit' => 10, 'window' => 1]`（下单/撤单，per UID，候选待核）
- `account => ['limit' => 10, 'window' => 1]`（账户/订单查询，per UID，候选待核）

复用基类 RateLimiter 窗口参数化（对齐 001/002 分类范式，非 Binance 1 分钟 weight 制）。

**Rationale**: 公共端点 20/s per IP 为官方限流页已核实值；trade/account 10/s per UID 是交易所行业通行默认值，候选待核。

**Alternatives considered**: 复用 Binance weight 制（拒绝——Bitget 无 weight 概念）。

**验证指针**: 实现期对照官方限流文档核对 trade/account 类别窗口；超限响应码（候选 40725）归入 R-008 分类表。

---

## R-008: 错误码分类表（translateErrorCode Bitget 实现）

**Decision**: V2 响应 HTTP 200 + `code`/`msg` 业务错误结构（**`"00000"` 成功**，数字串错误码——与 Binance 负数/OKX 5xxxx/Bybit 10xxx 形态不同，FR-008）。extractErrorCode 读取 `code` 字段（"00000" 返回 null）。分类（rawCode 为 code 字符串，**全部候选待核**）：

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
| 服务器 5xx 类/其他未知 | — | 保守 serverError 可重试（与前三家未知码策略一致） |

**Rationale**: 认证类硬失败与前三家语义对齐（FR-008 重试语义跨所一致）；成功码 "00000" 已核实；错误码号段为候选待核（官方错误码页 SPA 抓取受限，二手来源一致性不足，按 002 R-008 既定模式降级记录）。

**Alternatives considered**: 未知码归 clientError（拒绝——保守可重试更安全，既有范式）；阻塞 plan 待错误码全部核实（拒绝——002 已确立"候选码+验证指针"模式，离线断言表驱动结构不依赖具体码值一次到位）。

**验证指针**: 实现期对照官方错误码文档逐条核对上表（断言数据驱动，改码值不改结构）；cancel 场景 not-exist 码以主网补测实测为准（候选 43001）；timestamp 超窗码若实测不同，更新时钟守卫断言。

---

## R-009: 市价买单报价币数量

**Decision**: placeOrder 平台语义 `placeOrder(symbol, 'buy', 'market', '100')` = 花 100 USDT 买 BTC（与 001 OKX tgtCcy=quote_ccy、002 Bybit marketUnit=quoteCoin 对齐）→ Bitget body：`{symbol, side:"buy", orderType:"market", size:"100", clientOid}`，**市价买单 size 直接传报价币金额**（候选待核：V2 市价买单 size 语义即报价币，无需类似 marketUnit 的附加参数）；市价卖单 size 传 base 数量；限价单 size=base 数量 + price 必传 + `force:"gtc"`（候选默认 normal，实现期核对枚举值）。`clientOid` = clientOrderId（长度上限候选 64 字符——比 Bybit 36 宽松，平台模板 `{tenant}_{instance}_{ts}_{rand}` 天然满足，无需截断逻辑）。

**Rationale**: 四家市价买语义必须跨所一致（策略层零改动，FR-010）；clientOid 是官方幂等字段。

**Alternatives considered**: 市价买传 base 数量由上层换算（拒绝——价格滑点换算误差，002 已否决同方案）。

**验证指针**: 实现期对照官方 place-order 文档核对：市价买单 size 币种语义、force 枚举值集、clientOid 长度上限；主网补测时以一笔小额市价单实际成交佐证。

---

## R-010: 余额映射（spot/account/assets）

**Decision**: `GET /api/v2/spot/account/assets` → `data[]` coin 列表（字段候选：coin/available/frozen）。镜像 Binance 纯现货先例（BinanceSpotAdapter::getBalance L165-189）：

| 平台字段 | 映射 |
|---|---|
| available | USDT 条目 `available` |
| totalEquity | USDT 条目 `available + frozen`（bcmath bcadd，近似值——现货无账户级聚合字段） |
| totalMargin | `"0"`（现货无保证金语义） |
| unrealizedPnl | `"0"`（现货无持仓盈亏语义） |
| assets | 非零条目聚合 `{coin: {total: available+frozen, available}}`（bcmath，trimZeros 同 Binance 范式） |

无凭据/凭据缺段 → 签名前置抛 ExchangeApiException（FR-006）。

**Rationale**: Bitget assets 端点只返 coin 列表，无 totalEquity 类账户聚合字段；ExchangeBalance DTO 四字段非空 string，Binance 现货先例即以 USDT 余额近似 totalEquity、保证金/盈亏置 "0"——同构处理保持四家 DTO 语义一致，US3 场景1 只断言 available 合理。

**Alternatives considered**: 按各币 usdtValue 求和 totalEquity（拒绝——依赖价格换算字段，候选未核实且 POC 不需要；后续 spec 需要全资产权益时再议）；账户级字段抛异常（拒绝——破坏 DTO 非空契约与四家一致性）。

**验证指针**: 实现期对照官方 assets 文档核对字段名（frozen vs lock）；主网补测时以实际持仓响应佐证。

---

## R-011: K 线响应排序与字段

**Decision**: `/api/v2/spot/market/candles` data 为数组的数组 `[ts, open, high, low, close, baseVolume, quoteVolume]`，**排序方向候选待核（大概率为倒序最新在前）**——适配器统一按 ts 排序后输出 openTime 升序（无论原始方向，与 001/002 升序契约一致，spec US1 场景3 显式要求归一化）；closeTime=openTime+intervalMs（bcmath 推算，同 OKX/Bybit 范式）；tradesCount=0（候选无此字段）；limit 上限候选 1000（POC 用 500 安全）。symbol 直传无转换（FR-011，BTCUSDT 即平台格式）。

**Rationale**: 升序契约是 ExchangeKline 数组消费者的既定约定；"排序后输出"而非"反转"对原始方向不确定性鲁棒（002 Bybit 实测为倒序故反转，Bitget 未实测故排序）。

**Alternatives considered**: 盲信倒序直接反转（拒绝——若实为升序则产出倒序数组，静默错误比显式排序更危险）。

**验证指针**: POC 场景1 实测打印首末 ts 确认原始方向，证据记录于 quickstart。

---

## 决策汇总

| ID | 决策 | 状态 |
|---|---|---|
| R-001 | 生产端点 env 化、无 testnet 键（决定 A）、三段凭据 | Resolved（端点已核实） |
| R-002 | prehash=ts+METHOD+path(+query)+body → base64，ACCESS-* 四件套 | Resolved（头名已核实，黄金样本断言兜底） |
| R-003 | REST 端点映射表（/api/v2/spot 前缀） | Resolved（前缀已核实，参数集实现期核对） |
| R-004 | granularity 双形态映射（1min 系/candle1m 系），越界 clientError | Resolved（candle 系已核实，REST 系候选待核） |
| R-005 | orderInfo 主查 + history-orders 兜底双查 | Resolved（双查结构防御，实现期可退化） |
| R-006 | WS candle bar 切换检测判关闭、文本帧 ping/pong 心跳 | Resolved（心跳已核实；**bar 关闭判定候选待核，POC 实测佐证**） |
| R-007 | rate_limits 三类（public 20/1s 已核实，trade/account 10/1s 候选） | Resolved（实现期核对） |
| R-008 | 错误码分类表（00000 成功已核实，码段全部候选待核） | Resolved（数据驱动断言，实现期逐条核对官方页） |
| R-009 | 市价买 size=报价币金额、force=gtc、clientOid ≤64 候选 | Resolved（实现期核对 + 主网补测佐证） |
| R-010 | 余额镜像 Binance 现货先例（available=USDT.available，聚合字段近似/置零） | Resolved（字段名实现期核对） |
| R-011 | kline 排序后升序输出、closeTime 推算、tradesCount=0 | Resolved（原始方向 POC 实测记录） |
