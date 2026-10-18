# Data Model: Bitget 现货适配器

**Date**: 2026-10-18 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/spec.md)

Bitget 适配器不新增数据库表，复用现有 schema 与 DTO。本文件记录 Bitget V2 响应字段到平台 DTO 的映射关系（决策来源：[research.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/research.md) R-003/R-004/R-005/R-006/R-009/R-010/R-011）。标注「候选待核」的字段名实现期对照官方文档逐条核对（数据驱动断言，改字段名不改结构）。

## Entities（复用现有，不新增）

### ExchangeTicker（不变）

| 平台字段 | Bitget V2 来源（GET /api/v2/spot/market/tickers?symbol={s} → data[0]） | 转换 |
|---------|----------------------------------------|------|
| symbol | data.symbol（BTCUSDT） | 直传（FR-011 无转换） |
| lastPrice | data.lastPr（字段名候选待核） | 字符串直传 |
| bidPrice | data.bidPr（候选待核） | 字符串直传 |
| askPrice | data.askPr（候选待核） | 字符串直传 |
| volume24h | data.baseVolume（base 计价 24h 量，候选待核） | 字符串直传 |
| changePct24h | data.change24h（小数比率，候选待核） | 字符串直传（若官方为百分比数值则 /100，实现期核对） |
| timestamp | data.ts（候选待核；缺省取响应接收时刻） | 毫秒整数直传 |

### ExchangeKline（不变）

REST 来源：GET /api/v2/spot/market/candles → data 元素为数组 `[ts, open, high, low, close, baseVolume, quoteVolume]`，**排序方向候选待核，适配器统一按 ts 排序后输出 openTime 升序**（R-011）。
WS 来源：channel `candle{i}` → data[] 元素同为数组形态（R-006）。

| 平台字段 | Bitget V2 来源 | 转换 |
|---------|--------------|------|
| exchangeCode | 固定 "bitget" | — |
| symbol | 请求 symbol / WS arg.instId（BTCUSDT） | 直传（即平台格式，无转换） |
| interval | 平台 interval（1m/1h/...） | 平台格式直传（请求前映射为 granularity/candle 名，R-004；DTO 层始终为平台格式） |
| openTime | [0] ts（bar 开盘毫秒） | 整数直传 |
| closeTime | 无对应字段 | openTime + interval 毫秒（bcmath 推算，同 OKX/Bybit 范式） |
| open/high/low/close | [1]/[2]/[3]/[4] | 字符串直传 |
| volume | [5] baseVolume | 字符串直传 |
| quoteVolume | [6] quoteVolume | 字符串直传 |
| tradesCount | 无对应字段（候选待核） | 0 |

### ExchangeOrder（不变）

| 平台字段 | Bitget V2 来源（POST /api/v2/spot/trade/place-order；GET orderInfo / history-orders → data） | 转换 |
|---------|--------------------------------------|------|
| exchangeOrderId | data.orderId | 字符串直传 |
| clientOrderId | data.clientOid 或 opts 传入 | 优先 Bitget 返回，回退 opts（R-009：clientOid=clientOrderId，长度上限候选 64 字符） |
| symbol | data.symbol（BTCUSDT） | 直传 |
| side | data.side（buy/sell，候选待核大小写） | 小写转换 |
| type | data.orderType（market/limit） | 小写转换 |
| quantity | data.size | 字符串直传 |
| price | data.price（limit 单） | 限价单直传，市价 null |
| stopPrice | 无（现货常规单） | null |
| leverage | 无（现货无杠杆） | null |
| reduceOnly | 无（现货） | false |
| status | data.status（new/init/partially_filled/filled/cancelled/failed 等，值集候选待核） | 见状态映射 |
| filledQty | data.baseVolume（候选待核，已成交 base 量） | 字符串直传 |
| avgFillPrice | data.priceAvg（候选待核） | 字符串直传 |
| fee | data.fee（候选待核；若无单字段则实现期按明细累加或置 "0" 并注释） | 字符串直传 |
| placedAt | data.cTime（毫秒字符串，候选待核） | /1000 转秒 |
| filledAt | data.uTime（毫秒字符串，status=filled 时，候选待核） | /1000 转秒 |

### ExchangeBalance（不变）

| 平台字段 | Bitget V2 来源（GET /api/v2/spot/account/assets → data[]） | 转换 |
|---------|----------------------------------------|------|
| totalEquity | USDT 条目 available + frozen | bcmath bcadd（R-010：现货无账户聚合字段，镜像 Binance 先例以 USDT 余额近似） |
| totalMargin | 无 | "0"（现货无保证金语义） |
| available | data[] 中 USDT 条目 available | 字符串直传（US3 场景1 断言字段） |
| unrealizedPnl | 无 | "0"（现货无持仓盈亏语义） |
| assets | data[] coin/available/frozen | 非零条目聚合 `{coin: {total: available+frozen, available}}`（bcmath + trimZeros，同 Binance 范式；frozen 字段名候选待核） |

## State Transitions（订单状态机）

Bitget 订单状态 → 平台枚举映射（语义与前三家逐一对齐，DTO 枚举集合不变；Bitget 原始值集候选待核，按下表名实现期核对）：

```text
Bitget new / init       → submitted（已提交，未成交/部分成交待补）
Bitget partially_filled → partial（部分成交）
Bitget filled           → filled（全部成交）
Bitget cancelled        → canceled（已撤销）
Bitget failed / rejected 等 → failed（异常状态）
```

订单查询路径（R-005 崩溃恢复幂等）：`/api/v2/spot/trade/orderInfo?clientOid=`（主查）→ "订单不存在"类错误（候选 43001）或空数据则 `/api/v2/spot/trade/history-orders?clientOid=`（兜底）→ 两端皆空返回 null（真"不存在"，供崩溃恢复核实 pending 单）。

## Validation Rules

- **symbol 直传（FR-011）**：入参与响应均为 BTCUSDT 平台格式，适配器边界 MUST NOT 做分隔符转换；现货本 spec 只处理 USDT Quote（Assumption）。
- **interval 映射（R-004）**：平台 `1m/5m/15m/30m/1h/4h/1d` → REST `1min/5min/15min/30min/1h/4h/1day` 与 WS `candle1m/candle5m/candle15m/candle30m/candle1h/candle4h/candle1day` 双形态；3m/2h/6h/12h/1w 候选不支持，越界抛 ExchangeApiException（clientError）。
- **凭据三段守卫（FR-006）**：签名前置检查 credentials['api_key']/['api_secret']/['passphrase'] 非空，缺失抛 ExchangeApiException（前置失败不占限流额度，constitution V）。
- **时钟守卫（Edge Case）**：签名 timestamp 为毫秒串；候选码 40013（timestamp 超窗）归类 clientError 硬失败——不重试、不计熔断，错误消息附时钟校准提示（与 Bybit 10002 语义对齐）。
- **clientOid 长度（R-009）**：= clientOrderId，长度上限候选 64 字符；平台模板 `{tenant_id}_{instance_id}_{timestamp}_{random}` 天然满足，无需截断逻辑（与 Bybit 36 字符约束不同，实现期核对官方上限）。
- **市价买单报价币数量（R-009）**：placeOrder(..., 'buy', 'market', qty) 的 qty 为报价币（USDT）金额，body size 直传（候选无需附加参数）；市价卖单 qty 为 base 数量；限价单 size=base 数量 + price 必传 + `force: 'gtc'`（候选，实现期核对枚举）。
- **kline 排序（R-011）**：REST 响应统一按 ts 排序后构造 DTO 数组（openTime 升序契约），不盲信原始方向。
- **bar 关闭判定（R-006）**：WS 首帧 action=snapshot 只播种 lastBarOpenTime 不回调；update 帧 bar ts 推进时判定上一 bar 关闭并回调（用缓存的上一 bar 完整数据）；同 bar 重复 update 不触发——保持上层"每 bar 一次"语义，BarAggregator 零改动。
- **无 testnet 键（FR-013）**：config exchange.exchanges.bitget 段 MUST NOT 含 testnet 相关键（澄清决定 A）；该约束由 US4 离线断言结构性覆盖。
