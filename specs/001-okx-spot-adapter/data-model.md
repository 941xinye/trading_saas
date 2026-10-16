# Data Model: OKX 现货适配器

**Date**: 2026-10-16 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/spec.md)

OKX 适配器不新增数据库表，复用现有 schema 与 DTO。本文件记录 OKX 响应字段到平台 DTO 的映射关系。

## Entities（复用现有，不新增）

### ExchangeTicker（不变）

| 平台字段 | OKX V5 来源（GET /api/v5/market/ticker） | 转换 |
|---------|----------------------------------------|------|
| symbol | data.instId（BTC-USDT） | 归一化为平台格式 BTCUSDT（去 `-`） |
| lastPrice | data.last | 字符串直传 |
| bidPrice | data.bidPx | 字符串直传 |
| askPrice | data.askPx | 字符串直传 |
| volume24h | data.vol24h | 字符串直传（OKX 为 24h 成交量，base 计价） |
| changePct24h | data.open24h → 计算（last-open24h)/open24h | bcmath 计算，非直传 |
| timestamp | data.ts | 毫秒整数直传 |

### ExchangeKline（不变）

| 平台字段 | OKX V5 来源（GET /api/v5/market/candles，WS candle{interval}） | 转换 |
|---------|----------------------------------------------------------|------|
| exchangeCode | 固定 "okx" | — |
| symbol | instId（BTC-USDT） | 归一化为 BTCUSDT |
| interval | bar 参数（1m/5m/...） | 平台格式直传（OKX bar 参数与平台 interval 命名一致） |
| openTime | [0]（ts，毫秒） | 整数直传 |
| closeTime | [0] + interval 推算 或 OKX 不直接返回 closeTime | openTime + interval 毫秒（bcmath 推算，OKX REST 只返回 ts） |
| open/high/low/close | [1]/[2]/[3]/[4] | 字符串直传 |
| volume | [5]（base 成交量） | 字符串直传 |
| quoteVolume | [7]（quote 成交量） | 字符串直传 |
| tradesCount | 无对应字段 | 0（OKX 不返回 trades count） |

### ExchangeOrder（不变）

| 平台字段 | OKX V5 来源（POST /api/v5/trade/order） | 转换 |
|---------|--------------------------------------|------|
| exchangeOrderId | data.ordId | 字符串直传 |
| clientOrderId | data.clOrdId 或 opts 传入 | 优先 OKX 返回，回退 opts |
| symbol | instId（BTC-USDT） | 归一化 |
| side | data.side（buy/sell） | 小写直传 |
| type | data.ordType（market/limit） | 小写直传 |
| quantity | data.sz | 字符串直传 |
| price | data.px（limit 单） | 限单直传，市价 null |
| stopPrice | data.slOrdPx/slTriggerPx | 条件单直传 |
| leverage | 无（现货无杠杆） | null |
| reduceOnly | 无（现货） | false |
| status | data.state（live/partially_filled/filled/canceled） | 见状态映射 R-006 |
| filledQty | data.accFillSz | 字符串直传 |
| avgFillPrice | data.accFillPx 或 计算成交均价 | OKX 直接返回 accFillPx，无需计算 |
| fee | data.fee | 字符串直传（OKX 返回手续费） |
| placedAt | data.cTime（毫秒） | /1000 转秒 |
| filledAt | data.uTime（毫秒，state=filled 时） | /1000 转秒 |

### ExchangeBalance（不变）

| 平台字段 | OKX V5 来源（GET /api/v5/account/balance） | 转换 |
|---------|----------------------------------------|------|
| totalEquity | data.totalEq | 字符串直传（USDT 计价） |
| totalMargin | data.margin | 字符串直传 |
| available | 数据[].availBal（USDT 资产） | 取 USDT 资产 availBal |
| unrealizedPnl | data.upl | 字符串直传 |
| assets | data[].ccy/availBal/frozenBal | 聚合为 `{USDT: {total, available}}` 映射 |

## State Transitions（订单状态机）

OKX 订单状态 → 平台枚举映射（见 research.md R-006）：

```text
OKX live            → submitted（已提交，未成交/部分成交待补）
OKX partially_filled → partial（部分成交）
OKX filled          → filled（全部成交）
OKX canceled        → canceled（已撤销）
OKX mmp_canceled 等 → failed（异常状态）
```

## Validation Rules

- instId 归一化：入参 `BTCUSDT` 必须含 USDT Quote（现货本 spec 范围只处理 USDT 对），转 `BTC-USDT`；响应反向。非 USDT Quote（如 ETH-BTC）按 Assumption 留实现期。
- passphrase 守卫：`httpHeaders()` 前检查 credentials['passphrase'] 非空，缺失抛 ExchangeApiException::clientError。
- timestamp 时钟漂移：ISO 8601 timestamp 与 OKX 服务器漂移超 recvWindow 时上层应同步（与 Binance 一致）。
