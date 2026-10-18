---
description: "Task list for Bitget 现货适配器实现"
---

# Tasks: Bitget 现货交易所适配器

**Input**: Design documents from `/specs/003-bitget-spot-adapter/`（[plan.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/plan.md)、[spec.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/spec.md)、[research.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/research.md)、[data-model.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/data-model.md)、[contracts/bitget-api-contract.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/contracts/bitget-api-contract.md)、[quickstart.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/quickstart.md)）

**Prerequisites**: plan.md ✓、spec.md ✓（5 个 User Story，P1→P5）、research.md ✓（R-001~R-011 决策全 Resolved，候选待核项均登记验证指针）、data-model.md ✓（DTO 字段映射 + 订单状态机）、contracts/bitget-api-contract.md ✓（REST/WS/签名/错误码契约）

**Tests**: 本 feature 显式要求离线断言（spec US4 场景2 + constitution VI），测试任务按 user story 分组生成；**纯增量回归门槛**——AbstractExchangeAdapter 与前三家适配器零改动（SC-006），Binance/OKX/Bybit 既有测试 100% 绿（SC-004）。

**Organization**: 按 user story 分组（US1 REST 行情 → US2 WS K线 → US3 账户下单 → US4 无模拟盘边界 → US5 签名器+四家横切）。Foundational 阶段仅 Bitget 映射基础设施（签名契约/基类 doRequest/前三家迁移 002 已落地，本 spec 零触碰）。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1/US2/US3/US4/US5)
- Include exact file paths in descriptions

## Path Conventions

- **Adapter**: `app/Domain/Exchange/Adapters/BitgetAdapter.php`（扩展现有占位骨架）
- **Signing**: `app/Domain/Exchange/Signing/BitgetRequestSigner.php`（新增，复用 002 RequestSigner 契约）
- **Config**: `config/exchange.php`（bitget 块补全，**无 testnet 键**，FR-013）
- **Tests**: `tests/Domain/Exchange/`（BitgetAdapterTest + Signing/BitgetRequestSignerTest + 横切三件套扩展）
- **POC Command**: `app/Console/Commands/PocVerifyBitgetFeed.php`（FR-012）

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Bitget 配置补全与 env 占位——生产端点 env 化 + rate_limits 三类（research R-001/R-007）；**显式不引入 testnet 键**（澄清决定 A / FR-013）。

- [X] T00补全 Bitget 配置于 `config/exchange.php`：`exchanges.bitget` 块——`'rest_url' => env('EXCHANGE_BITGET_REST', 'https://api.bitget.com')`、`'ws_url' => env('EXCHANGE_BITGET_WS', 'wss://ws.bitget.com/v2/ws/public')`（端点 env 化，与前三家风格对齐）；新增 `'rate_limits' => ['public' => ['limit' => 20, 'window' => 1], 'trade' => ['limit' => 10, 'window' => 1], 'account' => ['limit' => 10, 'window' => 1]]`（R-007：public 20/s per IP 已核实，trade/account 10/s per UID 候选待核，结构镜像 bybit 段）；**MUST NOT 新增 testnet/testnet_rest_url/testnet_ws_url 键**（FR-013）
- [X] T00[P] 在 `.env.example` 增加 Bitget 环境变量占位（无真实密钥）：`EXCHANGE_BITGET_REST=https://api.bitget.com`、`EXCHANGE_BITGET_WS=wss://ws.bitget.com/v2/ws/public`、`EXCHANGE_BITGET_KEY=`、`EXCHANGE_BITGET_SECRET=`、`EXCHANGE_BITGET_PASSPHRASE=`（三段凭据，同 OKX 构）；**不增加任何 TESTNET 变量**（决定 A）

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: BitgetAdapter 私有映射基础设施——interval 双形态映射、订单状态机、订单/错误码映射、凭据三段守卫。这是 US1~US3 全部测试与实现的前置；基类与签名契约 002 已就绪（SC-006 零触碰）。

**⚠️ CRITICAL**: 本阶段是全部 user story 的公共前置；与 002 不同，无基类重构量。

- [X] T00BitgetAdapter 增加私有映射基础设施于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：(1) `toBitgetGranularity(string $interval): string`（REST 形态）与 `toBitgetCandleChannel(string $interval): string`（WS 形态）——平台 `1m/5m/15m/30m/1h/4h/1d` → REST `1min/5min/15min/30min/1h/4h/1day` 与 WS `candle1m/candle5m/candle15m/candle30m/candle1h/candle4h/candle1day`（R-004，3m/2h/6h/12h/1w 候选不支持），越界抛 `ExchangeApiException::clientError`；(2) `mapOrderStatus(string $bitgetStatus): string`——`new`/`init`→`submitted`、`partially_filled`→`partial`、`filled`→`filled`、`cancelled`→`canceled`、`failed` 等其他→`failed`（data-model.md State Transitions，输出落在平台七值枚举内）；(3) `mapOrderResponse(array $data, ...): ExchangeOrder` 按 data-model.md ExchangeOrder 表（orderId→exchangeOrderId、clientOid→clientOrderId、lower(side/orderType)、size→quantity、baseVolume→filledQty、priceAvg→avgFillPrice、fee、cTime/1000→placedAt、uTime/1000→filledAt；字段名候选待核，实现期对照官方文档逐条核对）；(4) `extractErrorCode` 返 V2 响应 `code` 字段字符串（`"00000"` 成功返 null）、`translateErrorCode` 按 R-008 候选分类表（40001/40009/40011/40012/40013/40014/40018→clientError，40725→serverError，43001→clientError，未知→保守 serverError；码段候选待核，数据驱动结构先行）；(5) 凭据三段守卫辅助：签名前置检查 `credentials['api_key']`/`['api_secret']`/`['passphrase']` 非空，缺失抛 `ExchangeApiException::clientError`（FR-006，前置失败不占限流额度，constitution V）

**Checkpoint**: interval/状态机/订单映射/错误码/凭据守卫基础设施就位——user story 实现可开始。

---

## Phase 3: User Story 1 - 通过 Bitget 获取现货行情（REST） (Priority: P1) 🎯 MVP

**Goal**: BitgetAdapter 实现 getTicker/getKline/symbolFilters/alignToStep/subscribeLiquidationStream（占位抛异常），无需凭据即可拉取 BTCUSDT ticker 与 500 根 1m K 线。覆盖 spec FR-001/FR-011 与 SC-001。

**Independent Test**: quickstart.md 场景 1+3——`php artisan poc:verify-bitget-feed`（或 tinker）调 getTicker('BTCUSDT')/getKline('BTCUSDT','1m',500) 断言字段非空合理；或 `php artisan test --filter=BitgetAdapterTest` 跑离线断言。

### Tests for User Story 1

- [X] T00[P] [US1] 新建 `tests/Domain/Exchange/BitgetAdapterTest.php`，写 `testToBitgetGranularityMapping` 用 `#[DataProvider]` 覆盖：REST 形态 `1m→1min`、`5m→5min`、`15m→15min`、`30m→30min`、`1h→1h`、`4h→4h`、`1d→1day` 与 WS 形态 `1m→candle1m`、`1d→candle1day` 等（R-004 双形态）；越界输入（`3m`、`2h`、`7m`、空串）抛 `ExchangeApiException`（clientError）。constitution VI：PHPUnit 12 用 `#[DataProvider]` 属性
- [X] T00[P] [US1] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testGetTickerMapsResponse`：`Http::fake` 返回 contracts/bitget-api-contract.md ticker 样例（`data[0].{symbol,lastPr,bidPr,askPr,baseVolume,change24h,ts}`，字段名候选待核），断言 ExchangeTicker.{lastPrice,bidPrice,askPrice,volume24h,changePct24h} 字符串直传、symbol 为 `BTCUSDT` 直传无分隔符转换（FR-011）、timestamp 为毫秒整数；bid < ask
- [X] T00[P] [US1] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testGetKlineMapsResponse`：`Http::fake` 返回 contracts kline 样例（data 元素为数组 `[ts,open,high,low,close,baseVolume,quoteVolume]`），分别喂**倒序与升序**两种原始方向，断言返回 ExchangeKline[] 均被归一化为 **openTime 升序**（R-011 排序后不盲信原始方向）、closeTime=openTime+intervalMs（1m=60000，bcmath 推算）、quoteVolume=[6]、tradesCount=0、exchangeCode='bitget'、symbol 平台格式、OHLCV 字段非空字符串
- [X] T00[P] [US1] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testSymbolFiltersMapsAndCaches`：`Http::fake` 返回 `/api/v2/spot/public/symbols` 响应含 tickSize/stepSize/minOrderQty 类字段（实现期按 Bitget 实际响应字段核对）；断言映射 `{tick_size, step_size, min_notional}` + 首次调用发起 HTTP 并写 Redis 缓存 key `market:symbols:bitget:{symbol}:filters`、二次调用不发起 HTTP。constitution VI：TTL 键在 setUp/tearDown 都精确 `Redis::del($key)` 清理（坑24/25）

### Implementation for User Story 1

- [X] T00[US1] 实现 `getTicker(string $symbol): ExchangeTicker` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：调公共 GET `/api/v2/spot/market/tickers`（`symbol={$symbol}` 直传不转换，FR-011）；映射 `data[0]` 按 data-model.md ExchangeTicker 表（lastPr→lastPrice、bidPr→bidPrice、askPr→askPrice、baseVolume→volume24h、change24h→changePct24h、ts→timestamp）
- [X] T00[US1] 实现 `getKline(string $symbol, string $interval, int $limit = 500): array` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：调 `/api/v2/spot/market/candles`（`symbol&granularity=toBitgetGranularity($interval)&limit=min($limit,1000)`，上限候选 1000）；遍历 data 映射 ExchangeKline（数组元素 [ts,open,high,low,close,baseVolume,quoteVolume]，data-model.md ExchangeKline 表），**`usort` 按 openTime 升序排序**（R-011 不盲信原始方向）；closeTime=bcmath `bcadd(openTime, intervalMs, 0)`
- [X] T01[US1] 实现 `symbolFilters(string $symbol): array` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：镜像 OkxAdapter::symbolFilters 范式；Redis 缓存 key `market:symbols:bitget:{symbol}:filters` TTL 86400；调 `/api/v2/spot/public/symbols?symbol=`；constitution VII：Redis facade 用 phpredis 签名（坑21/13）
- [X] T01[US1] 实现 `alignToStep(string $value, string $step): string` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：bcmath 向下取整到步长，镜像 BinanceSpotAdapter::alignToStep。constitution II：禁止浮点
- [X] T01[US1] 实现 `subscribeLiquidationStream(callable $onLiquidation): string` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：直接 `throw new \LogicException('Liquidation stream is futures-only.')`，与其他三家现货适配器行为一致（spec Edge Cases / FR-003）

**Checkpoint**: US1 完整可测——REST 行情链路就绪，POC 阶段无需凭据即可验证 SC-001。

---

## Phase 4: User Story 2 - 通过 Bitget 订阅 WS K 线 stream (Priority: P2)

**Goal**: BitgetAdapter 实现 subscribeKlineStream/unsubscribeStream，订阅 Bitget 公共 WS `candle{i}` 频道，**bar 切换检测**判定上一 bar 关闭时回调 onBar(ExchangeKline)，无需 login 鉴权。覆盖 spec FR-002 与 SC-002。

**Independent Test**: quickstart.md 场景 2——`php artisan poc:verify-bitget-feed --symbol=BTCUSDT --interval=1m --wait=120`，预期 ≤120s 内触发 onBar，回调参数为完整 ExchangeKline DTO；命令打印连续帧证据（action 值 + ts 序列）佐证 R-006 判定语义。

### Tests for User Story 2

- [X] T01[P] [US2] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testMapWsKlinePayload`：喂 contracts/bitget-api-contract.md 推送样例（arg `{instType:SPOT,channel:candle1m,instId:BTCUSDT}`，data[0] 为数组 `[ts,open,high,low,close,baseVolume,quoteVolume]`），断言映射后 ExchangeKline：openTime=[0]、OHLC=[1..4]、volume=[5]、quoteVolume=[6]、closeTime=openTime+intervalMs 推算、tradesCount=0、symbol=BTCUSDT、exchangeCode='bitget'、interval=平台格式 `1m`
- [X] T01[P] [US2] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testBarSwitchDetectionTriggersOnBarOnce`：纯函数分支单测 R-006 判定语义（无网络）——(1) 首帧 action=snapshot：只播种 lastBarOpenTime **不**触发 onBar；(2) update 帧 bar ts 与 lastBarOpenTime 相等（同 bar 快照更新）：不触发；(3) update 帧 ts 大于 lastBarOpenTime（bar 切换）：用缓存的上一 bar 完整数据触发 onBar **恰好一次**，并推进 lastBarOpenTime。保持上层"每 bar 一次"语义（BarAggregator 零改动）

### Implementation for User Story 2

- [X] T01[US2] 实现 `subscribeKlineStream(string $symbol, string $interval, callable $onBar, ?callable $onTick = null): string` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：镜像前三家 subscribeKlineStream 结构——`Coroutine::create` 内 while(true)：每分支显式 `Coroutine::isCanceled()` 检查并 return（constitution III 坑2：cancel 非 sticky），`try { runKlineWsLoop(...) } catch (CancelException) { return; } catch (Throwable) { Log::warning + 指数退避 1s→2s→4s…上限 60s 重连 }`，重连前/后退避中均检查 isCanceled；维护 `$this->subscriptions[$subId]`，subCounter 自增
- [X] T01[US2] 实现私有 `runKlineWsLoop(...)` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：连接 `wss://ws.bitget.com/v2/ws/public`（config ws_url）parse_url 拆 host/port/path；`WsClient` upgrade；连接后立即 push 订阅帧 `{"op":"subscribe","args":[{"instType":"SPOT","channel":"candle{i}","instId":"{symbol}"}]}`（args 为对象数组非字符串数组，R-006）；**应用层心跳为文本帧**：每 25s push 字符串 `ping`（analyze F5 定值：低于官方常见 30s 断开阈值留余量；已核实机制，非 JSON op 帧），收字符串 `pong`；超时未回判断线抛异常走退避重连；while(true) recv 解析 `{action,arg,data}`，按 T014 语义做 bar 切换检测并调 `$onBar($kline)`；协程退出前关闭 Timer/心跳协程防泄漏
- [X] T01[US2] 实现私有 `mapWsKlinePayload(array $row, string $symbol, string $interval): ExchangeKline` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：按 contracts 推送格式映射（[0]→openTime、[1..4]→OHLC、[5]→volume、[6]→quoteVolume、closeTime=openTime+intervalMs bcmath 推算、tradesCount=0、symbol 直传、exchangeCode='bitget'、interval 平台格式）
- [X] T01[US2] 实现 `unsubscribeStream(string $subscriptionId): void` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：镜像前三家 unsubscribeStream——`unset($this->subscriptions[$subId])`，若 `Coroutine::exists($cid)` 则 `Coroutine::cancel($cid)`

**Checkpoint**: US2 完整可测——WS 订阅可启动、bar 切换检测触发 onBar 恰好一次、文本帧 ping/pong 心跳 + 退避重连 + isCanceled 检查完备、unsubscribe 协程退出无泄漏。POC 阶段无需凭据即可验证 SC-002。

---

## Phase 5: User Story 3 - Bitget 账户与现货下单 (Priority: P3)

**Goal**: BitgetRequestSigner 落地 V2 签名，BitgetAdapter 实装 getBalance/placeOrder/cancelOrder/getOrder/getOrderByClientOrderId（orderInfo→history-orders 双查）。覆盖 spec FR-001/FR-005/FR-006/FR-007 与 SC-003。**Bitget 现货无公开模拟盘（决定 A）**：离线断言 100% 覆盖映射/状态归一化/凭据守卫；主网小额真实下单待用户 key 到位后按 quickstart 场景 5 补测。

**Independent Test**: quickstart.md 场景 3（离线断言）覆盖签名构造/订单映射/守卫/双查/错误归类；场景 5（主网小额单，待 key）跑 SC-003 挂起部分。

### Tests for User Story 3

- [ ] T019 [P] [US3] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testCredentialsThreePartGuardThrowsBeforeHttp`：分别构造 credentials 缺 api_key / api_secret / passphrase 任一段，调 `getBalance()`，断言抛 `ExchangeApiException::clientError`；`Http::preventUnexpectedRequests()` 断言未发起任何 HTTP（FR-006 + data-model.md 凭据三段守卫）
- [ ] T020 [P] [US3] 新建 `tests/Domain/Exchange/Signing/BitgetRequestSignerTest.php`：固定向量（黄金样本）断言 V2 签名构造（contracts/bitget-api-contract.md）——prehash=`timestamp+METHOD+requestPath(+?queryString)+body`、HMAC-SHA256 输出 **base64**（非 hex）、headers 含 `ACCESS-KEY`/`ACCESS-SIGN`/`ACCESS-TIMESTAMP`（**毫秒整数串**，非 OKX 的 ISO8601）/`ACCESS-PASSPHRASE` 四件套、**无 testnet 附加头**（决定 A）；GET 请求 SignedRequest.query=含参 queryString 且 prehash 含 queryString 段，POST 请求 body=raw JSON + contentType=`application/json` 且与签名串逐字节一致；缺凭据任一段前置抛异常；实现期用官方文档示例逐字节核对 prehash 构造
- [ ] T021 [P] [US3] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testMapOrderStatus` 用 `#[DataProvider]` 覆盖：`new→submitted`、`init→submitted`、`partially_filled→partial`、`filled→filled`、`cancelled→canceled`、`failed→failed`、未知值/空串→`failed`（data-model.md State Transitions，原始值集候选待核）；`mapOrderStatus` 留 protected 测试钩子
- [ ] T022 [P] [US3] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testPlaceOrderMarketBuyUsesQuoteSize`：`Http::fake` 捕获 POST `/api/v2/spot/trade/place-order`，断言请求 body 含 `symbol`/`side=buy`/`orderType=market`/**`size=100`（报价币 USDT 金额直传，无 marketUnit 类附加参数，R-009）**/`clientOid`（长度上限候选 64 字符，平台模板天然满足无需截断）；限价单断言含 `price`+`force=gtc`（候选枚举实现期核对）；fake 响应 `{orderId,clientOid}`，断言返回 ExchangeOrder 映射正确、clientOrderId 与传入一致
- [ ] T023 [P] [US3] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testGetOrderByClientOrderIdFallsBackOrderInfoToHistory`：(1) fake orderInfo 返回候选 43001（订单不存在）或空 data + history-orders 命中 → 返回 ExchangeOrder（R-005 双查）；(2) 两端皆空 → 返回 **null** 而非抛异常（FR-005 + constitution IV 崩溃恢复幂等）；(3) 断言两次 HTTP 调用顺序为 orderInfo→history-orders
- [ ] T024 [P] [US3] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testCancelOrderReturnsFalseOn43001`：fake POST `/api/v2/spot/trade/cancel-order` 返回候选 `code=43001`（订单不存在），断言 `cancelOrder` 返回 **false** 而非抛异常（US3 场景3 + R-008 特判，镜像 Bybit 170213 处理）；fake 其他错误码（如 40725）断言照常抛 `ExchangeApiException`
- [ ] T025 [P] [US3] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testTimestampExceededClassifiedClientErrorWithClockHint`：fake 签名请求返回候选 `code=40013`（timestamp 超窗），断言抛 `ExchangeApiException::clientError`（硬失败）、错误消息含**时钟校准提示**文案（Edge Case，与 Bybit 10002 语义对齐）、HTTP 调用次数=1（不重试、不消耗限流配额）
- [ ] T026 [P] [US3] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testGetBalanceMapsSpotAssets`：fake `/api/v2/spot/account/assets` 响应（`data[]` 含 USDT 条目 `available=900.5`/`frozen=100`，字段名候选待核），断言 available=`900.5` 直传、totalEquity=bcmath `bcadd('900.5','100',8)`=`1000.5`（R-010 镜像 Binance 现货近似）、totalMargin=`'0'`、unrealizedPnl=`'0'`、assets 聚合非零条目 `{USDT:{total:'1000.5',available:'900.5'}}`（trimZeros 同 Binance 范式）

### Implementation for User Story 3

- [ ] T027 [US3] 新建 `app/Domain/Exchange/Signing/BitgetRequestSigner.php` 实现 RequestSigner（R-002 + T020 测试向量）：prehash=`timestampMs.METHOD.path(+?queryString).body`（GET/DELETE 带参时 path 拼 `?queryString` 与实际发送串一致，body 恒空串；POST body=raw JSON），`base64_encode(hash_hmac('sha256', prehash, secret, true))`；产出 SignedRequest：headers 四件套（ACCESS-KEY/SIGN/TIMESTAMP/PASSPHRASE），GET query=queryString、POST body=raw JSON + contentType=application/json；凭据三段缺段前置抛 `ExchangeApiException::clientError`（FR-006）；**无 testnet 附加头**（决定 A）
- [ ] T028 [US3] 实现 `getBalance(): ExchangeBalance` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：签名 GET `/api/v2/spot/account/assets`（account 限流桶）；映射按 data-model.md ExchangeBalance 表（available=USDT.available 直传、totalEquity=bcmath bcadd(available,frozen) 近似、totalMargin/unrealizedPnl=`'0'`、assets 非零聚合 bcmath+trimZeros，R-010）
- [ ] T029 [US3] 实现 `placeOrder(string $symbol, string $side, string $type, string $quantity, ?string $price = null, ?string $stopPrice = null, array $opts = []): ExchangeOrder` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：body `{symbol, side:lower($side), orderType:lower($type), size:$quantity}`；市价买 size=报价币 USDT 金额直传（R-009，无附加参数），市价卖 size=base 数量；限价加 `price`+`force:'gtc'`（候选枚举实现期核对官方文档）；`clientOid`=opts.clientOrderId（上限候选 64 字符，平台模板 `{tenant_id}_{instance_id}_{timestamp}_{random}` 天然满足，constitution II）；POST `/api/v2/spot/trade/place-order`（trade 限流桶）；返回 mapOrderResponse
- [ ] T030 [US3] 实现 `cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：POST `/api/v2/spot/trade/cancel-order`（body 含 orderId 或 clientOid）；catch `ExchangeApiException`，extractErrorCode=候选 `43001` 返 false（R-008 特判，镜像 Bybit 170213 处理），其余重新抛
- [ ] T031 [US3] 实现 `getOrder(string $symbol, string $orderId): ExchangeOrder` 与 `getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder` 于 `app/Domain/Exchange/Adapters/BitgetAdapter.php`：先查 GET `/api/v2/spot/trade/orderInfo`（按 orderId 或 clientOid），返回候选 43001 或空 data 则回查 GET `/api/v2/spot/trade/history-orders`（R-005 双查防御）；两端皆空：getOrderByClientOrderId 返 **null**（FR-005 + constitution IV），getOrder 抛 clientError；命中返 mapOrderResponse（依赖 T003）

**Checkpoint**: US3 完整可测——V2 签名策略落地、订单 CRUD 全实装、双查幂等 + cancel 特判 + timestamp 时钟守卫就绪。离线断言 100% 覆盖（SC-003 映射部分）；主网小额真实下单待 key 到位后按 quickstart 场景 5 补测（SC-003 挂起部分）。

---

## Phase 6: User Story 4 - 无模拟盘边界与真实下单验收路径 (Priority: P4)

**Goal**: 结构性验证「无环境开关」边界——config bitget 段不含 testnet 键、仅含生产端点与限流配置（澄清决定 A / FR-013）；下单相关映射/状态归一化/凭据守卫已由 US3 离线断言 100% 覆盖（US4 场景2 验收依赖）。覆盖 spec FR-013 与 US4 场景1/2。

**Independent Test**: quickstart.md 场景 4——tinker 检查 config 结构无 testnet 键；本 phase 断言使其进入 CI 常绿。

### Tests for User Story 4

- [ ] T032 [P] [US4] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testBitgetConfigHasNoTestnetKeys`：读 `config('exchange.exchanges.bitget')`，断言**不含** `testnet`/`testnet_rest_url`/`testnet_ws_url` 键（FR-013 结构性断言，防未来误加死开关）；断言含 `rest_url`=`https://api.bitget.com`、`ws_url`=`wss://ws.bitget.com/v2/ws/public`、`rate_limits` 三类（public/trade/account，各含 `limit`/`window` 键，结构镜像 bybit 段）；US4 场景1
- [ ] T033 [P] [US4] 在 `tests/Domain/Exchange/BitgetAdapterTest.php` 写 `testProductionUrlsResolved`：实例化 BitgetAdapter，断言（反射或 protected 钩子）`restBaseUrl()` 返回 `https://api.bitget.com`、`wsBaseUrl()` 返回 `wss://ws.bitget.com/v2/ws/public`；`Http::fake` 捕获公共请求，断言请求目标域名为 api.bitget.com 且签名头**不含任何环境标识附加头**（决定 A：无 OKX x-simulated-trading 类机制）

**Checkpoint**: US4 完整可测——「无模拟盘」边界以结构性断言固化，US4 场景1/2 离线覆盖达成；US4 场景3（主网小额单）挂起待 key。

---

## Phase 7: User Story 5 - 签名器落地与四家横切断言扩展 (Priority: P5)

**Goal**: 错误码翻译、ExchangeManager 路由断言、策略兼容性断言从三家扩展至四家；验证「第四家接入纯增量」架构承诺——AbstractExchangeAdapter 零按家分支、前三家代码零触碰、横切测试只加数据集与路由用例（SC-004/SC-005/SC-006）。覆盖 spec FR-008/FR-009/FR-010。

**Independent Test**: `php artisan test` 跑四家全部 Exchange 测试套件全绿；前三家既有用例 100% 通过零变化；横切三件套新用例全绿。

### Tests for User Story 5

- [ ] T034 [P] [US5] 扩展 `tests/Domain/Exchange/ErrorCodeTranslationTest.php` 至四家（FR-008）：Bitget 候选 `40012`（key 无效）/`40009`（签名）/`40011`（passphrase）与 Binance `-2011`/OKX `51404`/Bybit `10003` 同归 clientError（认证类硬失败，跨所语义一致）；Bitget 候选 `40725`（限流）归 serverError 可重试，与前三家限流类重试语义一致；Bitget 候选 `40013`（timestamp 超窗）归 clientError 硬失败；四家未知码均保守 serverError（数据驱动只加 Bitget 数据集，不改断言结构）
- [ ] T035 [P] [US5] 扩展 `tests/Domain/Exchange/ExchangeManagerRouteTest.php` 至四家（FR-009）：断言 `ExchangeManager::adapter('bitget','spot',$credentials)` 返回 BitgetAdapter（路由代码已预留 L48，零改动只补断言）；四家 spot 路由（binance/okx/bybit/bitget）+ binance futures 分支 + 非法 exchange/market 组合抛异常均有显式断言
- [ ] T036 [US5] 扩展 `tests/Domain/Exchange/StrategyExchangeCompatibilityTest.php` 至四家（FR-010）：RecordingAdapter 驱动（stub/mock 注入，无网络无凭据），分别经 `ExchangeManager::adapter('bitget','spot')` 与其他三家获取适配器驱动 spot_grid 与 spot_dca 策略核心流程，断言：策略代码零具体适配器引用，且下单（placeOrder 含 clientOrderId 模板）/撤单（cancelOrder）/查单（getOrderByClientOrderId）调用序列四家一致（SC-005）

### Implementation for User Story 5

- [ ] T037 [US5] 全量回归验证 SC-004/SC-006：`php artisan test --filter='Binance|Okx|Bybit|Bitget|Signer|Translation|Route|Compatibility'` 全绿（前三家既有用例 100% 通过零变化）；人工核对 `AbstractExchangeAdapter` 自 002 落地后**零改动**（git diff 为空）且无任何按家分支（grep `instanceof`/按 `exchangeCode` 分支为零），四家 signed 请求统一走 限流→signer→HTTP→埋点→错误分类 单一路径；grep `BitgetAdapter.php` 确认无自建 HTTP 通道——仅经基类 `httpSigned`/`doRequest`，无 curl/guzzle 直调（FR-004 显式补强，analyze F3）

**Checkpoint**: US5 完整可测——四家签名/错误码/路由/策略契约统一，「第四家接入纯增量」架构承诺有显式回归保障。

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: POC 验证命令、quickstart 端到端验收、跨产物一致性校验。

- [ ] T038 [P] 新建 `app/Console/Commands/PocVerifyBitgetFeed.php` 镜像 `app/Console/Commands/PocVerifyBybitFeed.php` 范式（FR-012）：signature `poc:verify-bitget-feed --symbol=BTCUSDT --interval=1m --wait=120`；handle 内 `Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL)`（constitution III）→ `Coroutine\run` 内依次调 getTicker/getKline/subscribeKlineStream 等 wait 秒数 → unsubscribeStream → 打印 PASS/FAIL 与退出码；**额外打印两项证据**：(1) kline 首末 ts 与原始排序方向（R-011 验证指针）；(2) WS 连续帧 action 值与 data[0][0] ts 序列（R-006 bar 切换判定验证指针）；POC 阶段公共流无需凭据
- [ ] T039 运行 [quickstart.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/quickstart.md) 验收矩阵场景 1+2（poc:verify-bitget-feed，SC-001/SC-002，若主网不可达则记录待补）+ 场景 3（离线断言全绿）+ 场景 4（无模拟盘边界核查，已由 T032/T033 CI 覆盖）；场景 5（主网小额真实下单）**待用户 key 到位后补测**（决定 A，挂起不阻塞本 feature 收尾）
- [X] T040 [P] 运行 `/speckit-analyze` 跨产物一致性校验（spec.md ↔ plan.md ↔ tasks.md ↔ data-model.md ↔ contracts/bitget-api-contract.md），处理任何 flagged gap：补缺漏 task、修正 spec/plan 不一致点、更新 Complexity Tracking 若有违反需 justify（implement 前已执行：0 CRITICAL/HIGH、2 MEDIUM——F1 候选码群/F2 bar 判定已由验证指针内嵌化解不阻塞、3 LOW——F3/F5/F6 已修补落盘）

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖，立即可开始
- **Foundational (Phase 2)**: T003 依赖 T001（config 就绪）；**T003 阻塞全部 user story**（interval 映射/状态机/错误码为公共前置）；与 002 不同，无基类重构串行链
- **US1 (Phase 3, MVP)**: 依赖 T001 配置 + T003 interval 映射；与 US2/US4 无相互依赖
- **US2 (Phase 4)**: 依赖 T001（ws_url 配置）+ T003 candle 频道映射；与 US1 独立（公共流无需签名）；实现层同文件 BitgetAdapter.php 建议与 US1 串行
- **US3 (Phase 5)**: 依赖 Foundational（T003 映射/错误码/守卫）；T027 signer 为 T028-T031 前置（签名路径）；实现层与 US1/US2 串行（同文件）
- **US4 (Phase 6)**: 依赖 T001 配置键；纯断言零依赖，可在 US3 后任意时点插入（其场景2 验收依赖 US3 离线断言覆盖）
- **US5 (Phase 7)**: 依赖 US3（Bitget 下单链路就绪，T036 策略兼容性断言需要完整接口面）；T037 全量回归需四家测试就绪
- **Polish (Phase 8)**: T038 依赖 US1+US2；T039 依赖 T038；T040 analyze 是收尾门禁，依赖全部 story 完成

### User Story Dependencies

- **US1 (P1)**: T001+T003 完成即可开始，无 story 间依赖
- **US2 (P2)**: T001+T003 完成即可开始，与 US1 实现层串行（同文件）但测试可并行
- **US3 (P3)**: T003 完成即可开始，实现层与 US1/US2 串行（同文件），测试可并行；真实下单验收待主网 key（决定 A 挂起）
- **US4 (P4)**: T001 完成即可开始，纯断言零依赖
- **US5 (P5)**: US3 完成后开始（策略兼容性需完整接口面）；T034/T035 文件独立可提前起草

### Within Each User Story

- 测试先写并失败，再实现（constitution VI：离线断言先行）
- 私有辅助（granularity 映射、订单映射）先于公共方法
- 签名策略（T027）先于依赖它的 getBalance/placeOrder（T028/T029）
- Story 内 checkpoint 验证后再进下一 priority

### Parallel Opportunities

- **Phase 1 Setup**：T001 与 T002 不同文件，可并行
- **Phase 3 US1 测试**：T004/T005/T006/T007 独立测试方法可并行起草（最终合并同一测试文件）
- **Phase 4 US2 测试**：T013/T014 可并行
- **Phase 5 US3 测试**：T019/T020（不同文件）+ T021~T026（独立方法）可并行起草
- **Phase 6 US4 测试**：T032/T033 可并行
- **Phase 7 US5 测试**：T034/T035/T036 不同文件可并行起草；T037 回归需全部就绪
- **Phase 8 Polish**：T038（POC 命令）与 T040（analyze 预备）不同范畴；T039 需 US1+US2+T038 完成后跑

---

## Parallel Example: User Story 3

```bash
# 并行起草 US3 测试（BitgetAdapterTest 独立方法 + Signing 目录独立文件）：
Task: "T020 BitgetRequestSignerTest in tests/Domain/Exchange/Signing/BitgetRequestSignerTest.php"
Task: "T019 testCredentialsThreePartGuardThrowsBeforeHttp in tests/Domain/Exchange/BitgetAdapterTest.php"
Task: "T022 testPlaceOrderMarketBuyUsesQuoteSize in tests/Domain/Exchange/BitgetAdapterTest.php"
Task: "T023 testGetOrderByClientOrderIdFallsBackOrderInfoToHistory in tests/Domain/Exchange/BitgetAdapterTest.php"

# 然后串行实现（BitgetRequestSigner → BitgetAdapter.php 同文件方法）：
Task: "T027 BitgetRequestSigner in app/Domain/Exchange/Signing/BitgetRequestSigner.php"
Task: "T028 getBalance → T029 placeOrder → T030 cancelOrder → T031 getOrder/getOrderByClientOrderId in app/Domain/Exchange/Adapters/BitgetAdapter.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1 Setup（T001-T002）：配置补全 + env 占位
2. Phase 2 Foundational（T003）：Bitget 映射基础设施（无基类重构量，002 已前置）
3. Phase 3 User Story 1（T004-T012）：REST 行情全实装 + 离线断言
4. **STOP and VALIDATE**：跑 `php artisan test --filter=BitgetAdapterTest` + 网络可达时跑 `poc:verify-bitget-feed`
5. Deploy/Demo：Bitget 公共行情可读（SC-001 达成），无需凭据

### Incremental Delivery

1. Setup + Foundational → 基础设施就绪（纯增量，前三家零触碰）
2. US1 → 离线断言通过 + REST 行情可用 → Demo MVP（SC-001）
3. US2 → WS bar 可推送（bar 切换检测）→ Demo 实时链路（SC-002）
4. US3 → V2 签名 + 订单 CRUD + 双查幂等 → 离线断言 100%（SC-003 映射部分）；主网小额单待 key 补测
5. US4 → 无 testnet 键结构性断言 → 证明 FR-013（US4 场景1/2）
6. US5 → 四家横切断言 + 全量回归零变化 → 证明 SC-004/SC-005/SC-006
7. Polish → POC 命令 + quickstart 端到端 + speckit-analyze

### Parallel Team Strategy

- 一人主写 BitgetAdapter.php 实现（串行 US1→US2→US3，避免同文件冲突）
- 一人并行写测试（US1/US2/US3/US4 测试方法可并行起草）
- US5 横切三件套扩展（T034/T035/T036）文件独立，可由第二人提前起草
- Foundational 仅 T003 一个任务，无 002 式多周串行链

---

## Notes

- [P] tasks = different files OR independent test methods, no dependencies
- [Story] label maps task to US1~US5 for spec.md traceability
- 每个 user story 应独立可完成可测试（US3 依赖 Foundational T003，已在 Dependencies 显式标注）
- 测试先写并失败再实现（constitution VI：离线断言先行）
- 每个 task 或逻辑组后 commit
- 任何 checkpoint 都可暂停验证当前 story
- 避免：vague tasks、同文件并行冲突、跨 story 的强耦合依赖
- constitution 合规：II bcmath/UTC/clientOrderId 模板（clientOid 上限候选 64 字符）、III isCanceled 检查/文本帧 ping/pong 心跳/CURL hook 入口控制、IV orderInfo→history-orders 双查幂等、V 凭据三段守卫前置失败不占额度、VI #[DataProvider]/TTL 键精确清理/离线断言无网络、VII PSR-4 单文件单类/Redis phpredis 签名
- **纯增量门槛（本 spec 核心架构验证）**：T037 以 AbstractExchangeAdapter git diff 为空 + 前三家测试 100% 绿为完成定义；候选待核项（错误码段/字段名/限流类别）实现期对照官方文档逐条核对，数据驱动断言改值不改结构
- **最大技术风险点（R-006 bar 切换检测）**：T014 离线仿真先行，T038 POC 命令打印连续帧证据佐证；若实测发现官方 confirm 类标志，退化实现为 confirm 过滤并同步更新 contract/data-model
