# Data Model: Bybit 现货适配器

**Date**: 2026-10-18 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/spec.md)

Bybit 适配器不新增数据库表，复用现有 schema 与 DTO。本文件记录 Bybit v5 响应字段到平台 DTO 的映射关系（决策来源：[research.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/research.md) R-003/R-004/R-005/R-006/R-010/R-011/R-012）。

## Entities（复用现有，不新增）

### ExchangeTicker（不变）

| 平台字段 | Bybit V5 来源（GET /v5/market/tickers?category=spot → result.list[0]） | 转换 |
|---------|----------------------------------------|------|
| symbol | data.symbol（BTCUSDT） | 直传（Bybit 现货 symbol 即平台格式，FR-012 无转换） |
| lastPrice | data.lastPrice | 字符串直传 |
| bidPrice | data.bid1Price | 字符串直传 |
| askPrice | data.ask1Price | 字符串直传 |
| volume24h | data.volume24h | 字符串直传（Bybit 为 24h 成交量，base 计价） |
| changePct24h | data.price24hPcnt | 字符串直传（Bybit 直接返回小数比率，与平台语义一致，无需计算） |
| timestamp | 响应顶层 time | 毫秒整数直传 |

### ExchangeKline（不变）

REST 来源：GET /v5/market/kline → result.list 元素 `[startTime, open, high, low, close, volume, turnover]`，**倒序（最新在前），适配器反转为 openTime 升序**（R-012）。
WS 来源：topic `kline.{interval}.{symbol}` → data[] 元素含 start/end/open/high/low/close/volume/turnover/confirm（R-006）。

| 平台字段 | Bybit V5 来源 | 转换 |
|---------|--------------|------|
| exchangeCode | 固定 "bybit" | — |
| symbol | 请求 symbol / WS topic 段（BTCUSDT） | 直传（即平台格式，无转换） |
| interval | 平台 interval（1m/1h/...） | 平台格式直传（请求前映射为 Bybit 分钟数/D/W，R-004；DTO 层始终为平台格式） |
| openTime | REST [0] startTime / WS start（毫秒） | 整数直传 |
| closeTime | REST 无对应字段 / WS end | REST：openTime + interval 毫秒（bcmath 推算，同 001 OKX 范式）；WS：end 直传 |
| open/high/low/close | REST [1]/[2]/[3]/[4] / WS open/high/low/close | 字符串直传 |
| volume | REST [5] / WS volume（base 成交量） | 字符串直传 |
| quoteVolume | REST [6] turnover / WS turnover | 字符串直传 |
| tradesCount | 无对应字段 | 0（v5 不返回 trades count） |

### ExchangeOrder（不变）

| 平台字段 | Bybit V5 来源（POST /v5/order/create；GET /v5/order/realtime|history → result.list[0]） | 转换 |
|---------|--------------------------------------|------|
| exchangeOrderId | data.orderId | 字符串直传 |
| clientOrderId | data.orderLinkId 或 opts 传入 | 优先 Bybit 返回，回退 opts（R-011：orderLinkId=clientOrderId，≤36 字符） |
| symbol | data.symbol（BTCUSDT） | 直传 |
| side | data.side（Buy/Sell） | 小写转换 |
| type | data.orderType（Market/Limit） | 小写转换 |
| quantity | data.qty | 字符串直传 |
| price | data.price（limit 单） | 限价单直传，市价 null |
| stopPrice | data.stopPrice | 条件单直传，现货常规单 null |
| leverage | 无（现货无杠杆） | null |
| reduceOnly | 无（现货） | false |
| status | data.orderStatus（New/PartiallyFilled/Filled/Cancelled/Rejected） | 见状态映射 |
| filledQty | data.cumExecQty | 字符串直传 |
| avgFillPrice | data.avgPrice | 字符串直传（v5 直接返回成交均价，无需计算） |
| fee | data.cumExecFee | 字符串直传 |
| placedAt | data.createdTime（毫秒字符串） | /1000 转秒 |
| filledAt | data.updatedTime（毫秒字符串，status=Filled 时） | /1000 转秒 |

### ExchangeBalance（不变）

| 平台字段 | Bybit V5 来源（GET /v5/account/wallet-balance?accountType=UNIFIED → result.list[0]） | 转换 |
|---------|----------------------------------------|------|
| totalEquity | data.totalEquity | 字符串直传（USD 计价） |
| totalMargin | data.totalMarginBalance | 字符串直传 |
| available | data.coin[] 中 USDT 条目 walletBalance − locked | bcmath bcsub（R-010 官方现货可用余额语义；不用 availableToWithdraw——含借贷抵扣语义） |
| unrealizedPnl | data.totalPerpUPL | 字符串直传 |
| assets | data.coin[].coin/walletBalance/locked | 聚合为 `{USDT: {total, available}}` 映射，非空条目全返，上层自选 |

## State Transitions（订单状态机）

Bybit 订单状态 → 平台枚举映射（语义与 001 OKX 映射逐一对齐，DTO 枚举集合不变）：

```text
Bybit New             → submitted（已提交，未成交/部分成交待补）
Bybit PartiallyFilled → partial（部分成交）
Bybit Filled          → filled（全部成交）
Bybit Cancelled       → canceled（已撤销）
Bybit Rejected 等     → failed（异常状态）
```

订单查询路径（R-005 崩溃恢复幂等）：`/v5/order/realtime`（活跃单 New/PartiallyFilled）→ 空则 `/v5/order/history`（终态单 Filled/Cancelled/Rejected）→ 两端皆空返回 null（真"不存在"，供崩溃恢复核实 pending 单）。

## Validation Rules

- **symbol 直传（FR-012）**：入参与响应均为 BTCUSDT 平台格式，适配器边界 MUST NOT 做分隔符转换（与 OKX 归一化需求不同）；现货本 spec 只处理 USDT Quote（Assumption）。
- **interval 映射（R-004）**：平台 `1m/3m/5m/15m/30m/1h/2h/4h/6h/12h/1d/1w` → Bybit `1/3/5/15/30/60/120/240/360/720/D/W`，越界抛 ExchangeApiException（clientError）。
- **凭据两段守卫（FR-007）**：签名前置检查 credentials['api_key']/['api_secret'] 非空，缺失抛 ExchangeApiException（Bybit 无 passphrase，与 OKX 三段校验不同）。
- **recv_window 时钟守卫（clarify Q2）**：默认 5000ms；retCode 10002（timestamp/recv_window 超窗）归类 clientError 硬失败——不重试、不计熔断，错误消息附时钟校准提示。
- **orderLinkId 长度（R-011）**：= clientOrderId，v5 限制 ≤36 字符；clientOrderId 模板 `{tenant_id}_{instance_id}_{timestamp}_{random}` 实现期按 36 字符截断/压缩。
- **市价买单报价币数量（R-011）**：placeOrder(..., 'buy', 'market', qty) 的 qty 为报价币（USDT）金额，body 加 `marketUnit: 'quoteCoin'`（与 001 OKX tgtCcy=quote_ccy 语义对齐）；市价卖单 qty 为 base 数量（不加 marketUnit）；限价单 qty=base 数量 + price 必传 + `timeInForce: 'GTC'`。
- **kline 排序（R-012）**：REST 响应倒序必须反转为 openTime 升序后才构造 DTO（ExchangeKline 数组消费者的既有约定）。
- **bar 关闭判定（R-006）**：WS 推送 confirm:true 才触发 onBar；confirm:false 为 bar 内快照更新，不回调（保持上层"每 bar 一次"语义，BarAggregator 零改动）。
