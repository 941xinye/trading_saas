---
description: "Task list for Bybit 现货适配器实现"
---

# Tasks: Bybit 现货交易所适配器

**Input**: Design documents from `/specs/002-bybit-spot-adapter/`（[plan.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/plan.md)、[spec.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/spec.md)、[research.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/research.md)、[data-model.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/data-model.md)、[contracts/bybit-api-contract.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/contracts/bybit-api-contract.md)、[quickstart.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/quickstart.md)）

**Prerequisites**: plan.md ✓、spec.md ✓（6 个 User Story，P1→P5）、research.md ✓（R-001~R-012 决策全 Resolved）、data-model.md ✓（DTO 字段映射 + 订单状态机）、contracts/bybit-api-contract.md ✓（REST/WS/签名/错误码契约）

**Tests**: 本 feature 显式要求离线断言（spec Assumptions + constitution VI + clarify Q4），测试任务按 user story 分组生成；抽象迁移以 Binance/OKX 既有测试全绿为回归门槛（FR-008/FR-009/SC-006）。

**Organization**: 按 user story 分组（US1 REST 行情 → US2 WS K线 → US3 签名下单 → US4 模拟盘开关 → US5 签名/错误码抽象回归 → US6 路由与策略兼容断言）。Foundational 阶段为跨 story 共享的签名策略契约 + 基类 doRequest 收敛 + Binance 迁移（阻塞 US3 签名路径）+ Bybit 映射基础设施。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1/US2/US3/US4/US5/US6)
- Include exact file paths in descriptions

## Path Conventions

- **Adapter**: `app/Domain/Exchange/Adapters/BybitAdapter.php`（扩展现有占位骨架）
- **Base**: `app/Domain/Exchange/Adapters/AbstractExchangeAdapter.php`（doRequest 收敛 + 抽象点）
- **Signing**: `app/Domain/Exchange/Signing/`（RequestSigner/SignedRequest/三家实现，新建目录）
- **Config**: `config/exchange.php`（bybit 块修正）
- **Tests**: `tests/Domain/Exchange/`（BybitAdapterTest + Signing/*SignerTest + ExchangeManagerRouteTest + StrategyExchangeCompatibilityTest）
- **POC Command**: `app/Console/Commands/PocVerifyBybitFeed.php`（clarify Q3）

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Bybit 配置修正与 env 占位补全——config 现有 `ws_url` 错指 `/v5/public/linear`（合约），须修正为 spot 路径并补 testnet 独立域名与限流分类（research R-001/R-007）。

- [X] T001 修正 Bybit 配置于 `config/exchange.php`：`exchanges.bybit` 块——`'ws_url'` 由 `wss://stream.bybit.com/v5/public/linear` 修正为 `wss://stream.bybit.com/v5/public/spot`（R-001）；新增 `'testnet' => env('EXCHANGE_BYBIT_TESTNET', false)`、`'testnet_rest_url' => env('EXCHANGE_BYBIT_TESTNET_REST', 'https://api-testnet.bybit.com')`、`'testnet_ws_url' => env('EXCHANGE_BYBIT_TESTNET_WS', 'wss://stream-testnet.bybit.com/v5/public/spot')`；新增 `'rate_limits' => ['public' => ['limit' => 600, 'window' => 5], 'trade' => ['limit' => 10, 'window' => 1], 'account' => ['limit' => 10, 'window' => 1]]`（R-007：v5 无 weight 概念，按类别窗口参数化，per IP / per UID）
- [X] T002 [P] 在 `.env.example` 增加 Bybit 环境变量占位（无真实密钥）：`EXCHANGE_BYBIT_TESTNET=false`、`EXCHANGE_BYBIT_KEY=`、`EXCHANGE_BYBIT_SECRET=`（Bybit 两段凭据，无 passphrase）、`EXCHANGE_BYBIT_TESTNET_REST=https://api-testnet.bybit.com`、`EXCHANGE_BYBIT_TESTNET_WS=wss://stream-testnet.bybit.com/v5/public/spot`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: 签名策略契约 + 基类 doRequest 收敛 + Binance 迁移 + Bybit 映射基础设施。这些是 US3 签名路径的前置，也是 US5 抽象的主体迁移量；Binance 迁移与基类重构不可分割（Binance 用基类 doRequest），必须同任务落地并保持既有测试绿。

**⚠️ CRITICAL**: US3 的所有签名方法依赖本阶段的 RequestSigner 契约与基类收敛后的 doRequest；US1/US2（公共无签名）实际只依赖 T001 与 T005，可在 T003/T004 就绪前并行起步（见 Dependencies）。

- [X] T003 新建签名策略契约（纯新增，零行为变化，research R-009 + clarify Q1）：`app/Domain/Exchange/Signing/RequestSigner.php` 接口 `signRequest(string $method, string $path, array $params, ?string $body, array $credentials, int $timestampMs): SignedRequest`；`app/Domain/Exchange/Signing/SignedRequest.php` value object `{headers: array<string,string>, query: string|null, body: string|null, contentType: string|null}`——GET 签名请求策略产出 `query`（含签名参数的完整 queryString），POST/DELETE 产出 `body`（raw 串）+ `contentType`；策略纯函数无状态，可独立单测
- [X] T004 重构 `app/Domain/Exchange/Adapters/AbstractExchangeAdapter.php` + Binance 迁移（FR-008/FR-009）：(1) `doRequest` 收敛为签名策略驱动——限流 →（signed 时委托 `$this->requestSigner()` 获取策略产出 SignedRequest：GET 拼 `base+path+'?'+query`，POST/DELETE `withBody(body, contentType)`）→ HTTP → 健康埋点 → 异常分类；序列化必须确定性且与实际发送串逐字节一致（Binance 坑"签名串=发送串"）；(2) 新增抽象点 `extractErrorCode(array $responseBody): ?string` 与 `translateErrorCode(string $rawCode, int $httpStatus): 'client'|'server'`；(3) 新建 `app/Domain/Exchange/Signing/BinanceRequestSigner.php`（queryString+HMAC-SHA256 hex + `X-MBX-APIKEY`），BinanceSpotAdapter/BinanceFuturesAdapter 删除签名相关 override 改为提供 signer，对 HTTP 4xx/5xx 现状分类逐一对齐（含 429 现状，不改变）；(4) OkxAdapter 新增 `extractErrorCode`/`translateErrorCode` 实现（从现有 doRequest override 内联逻辑平移，行为不变），其 doRequest/signQuery override **暂保留**待 US5 T040 删除；(5) 保留 OkxAdapter override 依赖的基类成员（rateLimiter/healthChecker 可见性）不动。**门槛：Binance 既有测试全绿（含签名断言），行为零变化**
- [X] T005 BybitAdapter 增加私有映射基础设施于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：(1) `toBybitInterval(string $interval): string`——平台 `1m/3m/5m/15m/30m/1h/2h/4h/6h/12h/1d/1w` → Bybit `1/3/5/15/30/60/120/240/360/720/D/W`（R-004），越界抛 `ExchangeApiException::clientError`；(2) `mapOrderStatus(string $bybitStatus): string`——`New`→`submitted`、`PartiallyFilled`→`partial`、`Filled`→`filled`、`Cancelled`→`canceled`、`Rejected` 等其他→`failed`（data-model.md State Transitions），输出落在 ExchangeOrder.status 枚举内；(3) `mapOrderResponse(array $data, ...): ExchangeOrder` 按 data-model.md ExchangeOrder 表（orderId→exchangeOrderId、orderLinkId→clientOrderId、lower(side/orderType)、cumExecQty→filledQty、avgPrice→avgFillPrice、cumExecFee→fee、createdTime/1000→placedAt、updatedTime/1000→filledAt）；(4) `extractErrorCode` 返 `retCode` 字符串、`translateErrorCode` 按 R-008 分类表（10002/10003/10004/10005/10010/110001/110004→clientError，10006/10016→serverError，170213→clientError，未知→保守 serverError）；(5) 凭据两段守卫辅助：签名前置检查 `credentials['api_key']`/`['api_secret']` 非空，缺失抛 `ExchangeApiException::clientError`（FR-007，无 passphrase）

**Checkpoint**: RequestSigner 契约就绪、基类 doRequest 三家可共用、Binance 迁移回归绿、Bybit 映射/错误码基础设施就位——user story 实现可开始。

---

## Phase 3: User Story 1 - 通过 Bybit 获取现货行情（REST） (Priority: P1) 🎯 MVP

**Goal**: BybitAdapter 实现 getTicker/getKline/symbolFilters/alignToStep/subscribeLiquidationStream（占位抛异常），无需凭据即可拉取 BTCUSDT ticker 与 500 根 1m K 线。覆盖 spec FR-001/FR-012 与 SC-001。

**Independent Test**: quickstart.md 场景 1+3——`php artisan poc:verify-bybit-feed`（或 tinker）调 getTicker('BTCUSDT')/getKline('BTCUSDT','1m',500) 断言字段非空合理；或 `php artisan test --filter=BybitAdapterTest` 跑离线断言。

### Tests for User Story 1

- [X] T006 [P] [US1] 新建 `tests/Domain/Exchange/BybitAdapterTest.php`，写 `testToBybitIntervalMapping` 用 `#[DataProvider]` 覆盖：`1m→1`、`3m→3`、`5m→5`、`15m→15`、`30m→30`、`1h→60`、`2h→120`、`4h→240`、`6h→360`、`12h→720`、`1d→D`、`1w→W`；越界输入（如 `7m`、`1M`、空串）抛 `ExchangeApiException`（R-004）。constitution VI：PHPUnit 12 用 `#[DataProvider]` 属性
- [X] T007 [P] [US1] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testGetTickerMapsResponse`：`Http::fake` 返回 contracts/bybit-api-contract.md ticker 样例（`result.list[0].{symbol,lastPrice,bid1Price,ask1Price,volume24h,price24hPcnt}` + 顶层 `time`），断言 ExchangeTicker.{lastPrice,bidPrice,askPrice,volume24h,changePct24h} 字符串直传（changePct24h=price24hPcnt 直传不计算）、symbol 为 `BTCUSDT` 直传无分隔符转换（FR-012）、timestamp 为毫秒整数；bid < ask
- [X] T008 [P] [US1] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testGetKlineMapsResponse`：`Http::fake` 返回 contracts kline 样例（result.list **倒序**，元素 `[startTime,open,high,low,close,volume,turnover]`），断言返回 ExchangeKline[] 被**反转为 openTime 升序**（R-012）、closeTime=openTime+intervalMs（1m=60000，bcmath 推算）、quoteVolume=turnover、tradesCount=0、exchangeCode='bybit'、symbol 平台格式、OHLCV 字段非空字符串
- [X] T009 [P] [US1] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testSymbolFiltersMapsAndCaches`：`Http::fake` 返回 `/v5/market/instruments-info` 响应含 `priceFilter.tickSize`/`lotSizeFilter.basePrecision`/`lotSizeFilter.minOrderAmt`；断言映射 `{tick_size, step_size, min_notional}`（实现期按 Bybit 实际响应字段核对）+ 首次调用发起 HTTP 并写 Redis 缓存 key `market:symbols:bybit:{symbol}:filters`、二次调用不发起 HTTP。constitution VI：TTL 键在 setUp/tearDown 都精确 `Redis::del($key)` 清理（坑24/25）

### Implementation for User Story 1

- [X] T010 [US1] 实现 `getTicker(string $symbol): ExchangeTicker` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：调公共 GET `/v5/market/tickers`（`category=spot&symbol={$symbol}`，symbol 直传不转换，FR-012）；映射 `result.list[0]` 按 data-model.md ExchangeTicker 表（bid1Price→bidPrice、ask1Price→askPrice、price24hPcnt→changePct24h 直传、顶层 time→timestamp）
- [X] T011 [US1] 实现 `getKline(string $symbol, string $interval, int $limit = 500): array` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：调 `/v5/market/kline`（`category=spot&symbol&interval=toBybitInterval($interval)&limit=min($limit,1000)`，R-003 上限 1000）；遍历 result.list 映射 ExchangeKline（data-model.md ExchangeKline 表），**`array_reverse` 反转为 openTime 升序**（R-012）；closeTime=bcmath `bcadd(openTime, intervalMs, 0)`
- [X] T012 [US1] 实现 `symbolFilters(string $symbol): array` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：镜像 OkxAdapter::symbolFilters 范式；Redis 缓存 key `market:symbols:bybit:{symbol}:filters` TTL 86400；调 `/v5/market/instruments-info?category=spot&symbol=`；constitution VII：Redis facade 用 phpredis 签名 `Redis::connection('default')->get/setex`（坑21/13）
- [X] T013 [US1] 实现 `alignToStep(string $value, string $step): string` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：bcmath 向下取整到步长，镜像 BinanceSpotAdapter::alignToStep。constitution II：禁止浮点
- [X] T014 [US1] 实现 `subscribeLiquidationStream(callable $onLiquidation): string` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：直接 `throw new \LogicException('Liquidation stream is futures-only.')`，与 BinanceSpotAdapter/OkxAdapter 行为一致（spec Edge Cases）

**Checkpoint**: US1 完整可测——REST 行情链路就绪，POC 阶段无需凭据即可验证 SC-001。

---

## Phase 4: User Story 2 - 通过 Bybit 订阅 WS K 线 stream (Priority: P2)

**Goal**: BybitAdapter 实现 subscribeKlineStream/unsubscribeStream，订阅 Bybit 公共 WS `kline.{interval}.{symbol}` topic，`confirm:true`（bar 关闭）时回调 onBar(ExchangeKline)，无需 login 鉴权。覆盖 spec FR-002 与 SC-002。

**Independent Test**: quickstart.md 场景 2——`php artisan poc:verify-bybit-feed --symbol=BTCUSDT --interval=1m --wait=120`，预期 ≤120s 内触发 onBar，回调参数为完整 ExchangeKline DTO。

### Tests for User Story 2

- [X] T015 [P] [US2] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testMapWsKlinePayload`：喂 contracts/bybit-api-contract.md 推送样例（topic `kline.1.BTCUSDT`，data[0] 含 `start/end/open/high/low/close/volume/turnover/confirm:true`），断言映射后 ExchangeKline：openTime=start、closeTime=end（WS 直传，无需推算）、quoteVolume=turnover、tradesCount=0、symbol=BTCUSDT、exchangeCode='bybit'、interval=平台格式 `1m`
- [X] T016 [P] [US2] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testConfirmTrueTriggersOnBarOnly`：断言 `confirm:false` 的 data 元素**不**触发 onBar（bar 内快照更新），`confirm:true` 触发一次（R-006"每 bar 一次"语义，BarAggregator 零改动）；无网络环境用纯函数分支单测

### Implementation for User Story 2

- [X] T017 [US2] 实现 `subscribeKlineStream(string $symbol, string $interval, callable $onBar, ?callable $onTick = null): string` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：镜像 OkxAdapter::subscribeKlineStream 结构——`Coroutine::create` 内 while(true)：每分支显式 `Coroutine::isCanceled()` 检查并 return（constitution III 坑2：cancel 非 sticky），`try { runKlineWsLoop(...) } catch (CancelException) { return; } catch (Throwable) { Log::warning + 指数退避 1s→2s→4s…上限 60s 重连 }`，重连前/后退避中均检查 isCanceled；topic=`kline.{toBybitInterval($interval)}.{$symbol}`；维护 `$this->subscriptions[$subId]`，subCounter 自增
- [X] T018 [US2] 实现私有 `runKlineWsLoop(...)` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：连接 `$this->wsBaseUrl()`（testnet 自动切 stream-testnet.bybit.com，T001 配置）parse_url 拆 host/port/path；`WsClient` upgrade `/v5/public/spot`；连接后立即 push `{"op":"subscribe","args":["kline.{i}.{s}"]}`；**应用层心跳**：每 20s push `{"op":"ping"}`（独立定时协程或 Timer，收 `{"op":"pong"}`；超时未回判断线抛异常走退避重连，R-006）；while(true) recv 解析 `{topic,data}`，遍历 data[] 调 `mapWsKlinePayload`，`confirm===true` 调 `$onBar($kline)`，false 不回调（T016 语义）；协程退出前关闭 Timer/心跳协程防泄漏
- [X] T019 [US2] 实现私有 `mapWsKlinePayload(array $row, string $symbol, string $interval): ExchangeKline` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：按 contracts 推送格式映射（start→openTime、end→closeTime、OHLC 直传、volume、turnover→quoteVolume、tradesCount=0、symbol 直传、exchangeCode='bybit'、interval 平台格式）
- [X] T020 [US2] 实现 `unsubscribeStream(string $subscriptionId): void` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：镜像 OkxAdapter::unsubscribeStream——`unset($this->subscriptions[$subId])`，若 `Coroutine::exists($cid)` 则 `Coroutine::cancel($cid)`

**Checkpoint**: US2 完整可测——WS 订阅可启动、confirm:true 触发 onBar、op:ping 心跳 + 退避重连 + isCanceled 检查完备、unsubscribe 协程退出无泄漏。POC 阶段无需凭据即可验证 SC-002。

---

## Phase 5: User Story 3 - Bybit 账户与现货下单（模拟盘） (Priority: P3)

**Goal**: BybitRequestSigner 落地 v5 签名，BybitAdapter 实装 getBalance/placeOrder/cancelOrder/getOrder/getOrderByClientOrderId（双端点）。覆盖 spec FR-001/FR-005/FR-006/FR-007 与 SC-003。离线断言先行，真实下单待 testnet key 后补。

**Independent Test**: quickstart.md 场景 3（离线断言）覆盖签名构造/订单映射/守卫/双端点/错误归类；场景 5（真实下单，待 testnet key）跑 SC-003 闭环。

### Tests for User Story 3

- [X] T021 [P] [US3] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testCredentialsMissingGuardThrowsBeforeHttp`：构造 credentials 缺 api_key 或 api_secret，调 `getBalance()`，断言抛 `ExchangeApiException::clientError`；`Http::preventUnexpectedRequests()` 断言未发起任何 HTTP（FR-007 + data-model.md 凭据两段守卫，无 passphrase）
- [X] T022 [P] [US3] 新建 `tests/Domain/Exchange/Signing/BybitRequestSignerTest.php`：固定向量断言 v5 签名构造（contracts/bybit-api-contract.md）——prehash=`timestamp+api_key+recv_window+payload`、HMAC-SHA256 输出 **hex**（非 base64）、headers 含 `X-BAPI-API-KEY`/`X-BAPI-SIGN`/`X-BAPI-TIMESTAMP`（毫秒字符串）/`X-BAPI-RECV-WINDOW: 5000`/`X-BAPI-SIGN-TYPE: 2`；GET 请求 SignedRequest.query=含参 queryString，POST 请求 body=raw JSON + contentType=`application/json`；缺凭据前置抛异常
- [X] T023 [P] [US3] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testMapOrderStatus` 用 `#[DataProvider]` 覆盖：`New→submitted`、`PartiallyFilled→partial`、`Filled→filled`、`Cancelled→canceled`、`Rejected→failed`、空串→`failed`（data-model.md State Transitions）；`mapOrderStatus` 留 protected 测试钩子
- [X] T024 [P] [US3] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testPlaceOrderMarketBuyUsesQuoteCoin`：`Http::fake` 捕获 POST `/v5/order/create`，断言请求 body 含 `category=spot`/`side=Buy`/`orderType=Market`/`qty=100`/**`marketUnit=quoteCoin`**（R-011，与 001 OKX tgtCcy=quote_ccy 语义对齐）/`orderLinkId`（≤36 字符，R-011 截断约束）；fake 响应 `{orderId,orderLinkId}`，断言返回 ExchangeOrder 映射正确、clientOrderId 与传入一致
- [X] T025 [P] [US3] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testGetOrderByClientOrderIdFallsBackRealtimeToHistory`：(1) fake realtime 空 list + history 命中 → 返回 ExchangeOrder（R-005 双端点）；(2) 两端皆空 list → 返回 **null** 而非抛异常（FR-006 + constitution IV 崩溃恢复幂等）；(3) 断言两次 HTTP 调用顺序为 realtime→history
- [X] T026 [P] [US3] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testCancelOrderReturnsFalseOn170213`：fake POST `/v5/order/cancel` 返回 `retCode=170213`（订单不存在），断言 `cancelOrder` 返回 **false** 而非抛异常（US3 场景3 + R-008 特判）；fake 其他错误码（如 10016）断言照常抛 `ExchangeApiException`
- [X] T027 [P] [US3] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testRecvWindowExceededClassifiedClientErrorWithClockHint`：fake 签名请求返回 `retCode=10002`，断言抛 `ExchangeApiException::clientError`（硬失败）、错误消息含**时钟校准提示**文案（clarify Q2）、HTTP 调用次数=1（不重试、不消耗限流配额）
- [X] T028 [P] [US3] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testGetBalanceMapsUnifiedWallet`：fake `/v5/account/wallet-balance?accountType=UNIFIED` 响应（`result.list[0].{totalEquity,totalMarginBalance,totalPerpUPL}` + `coin[]` 含 USDT 条目 `walletBalance=1000.5`/`locked=100`），断言 available=bcmath `bcsub('1000.5','100',8)`=`900.5`（R-010，不用 availableToWithdraw）、assets 聚合 `{USDT:{total,available}}`、totalEquity/unrealizedPnl 直传

### Implementation for User Story 3

- [X] T029 [US3] 新建 `app/Domain/Exchange/Signing/BybitRequestSigner.php` 实现 RequestSigner（R-002 + T022 测试向量）：prehash=`timestampMs.api_key.recv_window.payload`（GET payload=http_build_query(params)，POST payload=raw JSON body），`bin2hex(hash_hmac('sha256', prehash, secret, true))`；产出 SignedRequest：headers 五件套（X-BAPI-API-KEY/SIGN/TIMESTAMP/RECV-WINDOW=5000/SIGN-TYPE=2），GET query=queryString、POST body=raw JSON + contentType=application/json；凭据缺段前置抛 `ExchangeApiException::clientError`（FR-007）
- [X] T030 [US3] 实现 `getBalance(): ExchangeBalance` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：签名 GET `/v5/account/wallet-balance?accountType=UNIFIED`（account 限流桶）；映射按 data-model.md ExchangeBalance 表，available=bcmath `bcsub(walletBalance, locked, 8)`（R-010）
- [X] T031 [US3] 实现 `placeOrder(string $symbol, string $side, string $type, string $quantity, ?string $price = null, ?string $stopPrice = null, array $opts = []): ExchangeOrder` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：body `{category:spot, symbol, side:ucfirst($side), orderType:ucfirst($type), qty:$quantity}`；市价买加 `marketUnit:'quoteCoin'`（qty=USDT 金额，R-011），市价卖不加（qty=base 数量）；限价加 `price`+`timeInForce:'GTC'`；`orderLinkId`=opts.clientOrderId，**超 36 字符按 v5 限制截断/压缩**（R-011，constitution II 模板 `{tenant_id}_{instance_id}_{timestamp}_{random}` 实现期压缩）；POST `/v5/order/create`（trade 限流桶）；返回 mapOrderResponse
- [X] T032 [US3] 实现 `cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：POST `/v5/order/cancel`（body 含 orderId 或 orderLinkId）；catch `ExchangeApiException`，extractErrorCode=`170213` 返 false（R-008 特判，镜像 Binance -2011 处理），其余重新抛
- [X] T033 [US3] 实现 `getOrder(string $symbol, string $orderId): ExchangeOrder` 与 `getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder` 于 `app/Domain/Exchange/Adapters/BybitAdapter.php`：先查 GET `/v5/order/realtime`（活跃单 New/PartiallyFilled），result.list 空则回查 GET `/v5/order/history`（终态单 Filled/Cancelled/Rejected，R-005）；两端皆空：getOrderByClientOrderId 返 **null**（FR-006 + constitution IV），getOrder 抛 clientError；命中返 mapOrderResponse（依赖 T005）

**Checkpoint**: US3 完整可测——v5 签名策略落地、订单 CRUD 全实装、双端点幂等查询 + cancel 特判 + recv_window 时钟守卫就绪。离线断言全覆盖；真实下单待 Bybit testnet key 到位后跑 SC-003。

---

## Phase 6: User Story 4 - 模拟盘环境开关 (Priority: P4)

**Goal**: 验证 config `exchange.exchanges.bybit.testnet` 开关生效——testnet=true 时 REST/WS 整体切换到独立域名（api-testnet.bybit.com / stream-testnet.bybit.com），不注入额外 header（与 OKX 机制根本不同，R-001）。机制由基类 `restBaseUrl()/wsBaseUrl()` + T001 配置键天然支持，本 phase 仅补开关断言。覆盖 spec FR-003/FR-004 与 SC-004。

**Independent Test**: quickstart.md 场景 4——切换 testnet 后对比 REST/WS 目标域名区分环境。

### Tests for User Story 4

- [X] T034 [P] [US4] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testTestnetSwitchRoutesRestUrl`：`config(['exchange.exchanges.bybit.testnet' => true])`，实例化 BybitAdapter，断言（反射或 protected 钩子）`restBaseUrl()` 返回 `https://api-testnet.bybit.com`；`Http::fake` 捕获公共请求，断言请求目标域名为 api-testnet.bybit.com 且**不**含任何模拟盘标识 header（R-001：独立域名非 header 标识）
- [X] T035 [P] [US4] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testTestnetSwitchRoutesWsUrl`：`config(['exchange.exchanges.bybit.testnet' => true])`，断言 `wsBaseUrl()` 返回 `wss://stream-testnet.bybit.com/v5/public/spot`
- [X] T036 [P] [US4] 在 `tests/Domain/Exchange/BybitAdapterTest.php` 写 `testProductionSwitchDefaultUrls`：`config(['exchange.exchanges.bybit.testnet' => false])`，断言 `restBaseUrl()`=`https://api.bybit.com`、`wsBaseUrl()`=`wss://stream.bybit.com/v5/public/spot`（T001 修正后的 spot 路径，非 linear）

**Checkpoint**: US4 完整可测——testnet 开关在 REST 与 WS 双路径生效，证明 SC-004 满足。无需 testnet key 即可离线断言。

---

## Phase 7: User Story 5 - 签名策略与错误码翻译抽象落地 (Priority: P5)

**Goal**: OKX 迁移到 OkxRequestSigner（删除 doRequest/signQuery override），三家签名/错误码收敛到统一契约并有逐字节回归断言；Binance/OKX 既有测试 100% 绿（SC-006 回归零变化）。覆盖 spec FR-008/FR-009。

**Independent Test**: `php artisan test` 跑 Binance+OKX+Bybit 全部 Exchange 测试套件全绿；Signing/*SignerTest 逐字节断言通过；错误码一致性断言通过。

### Tests for User Story 5

- [X] T037 [P] [US5] 新建 `tests/Domain/Exchange/Signing/OkxRequestSignerTest.php`（先写，对尚未存在的 OkxRequestSigner 失败）：断言 prehash=`timestamp+method+requestPath+body`、base64 输出、headers 含 `OK-ACCESS-KEY/SIGN/TIMESTAMP/PASSPHRASE` 四件套、timestamp ISO 8601 UTC、testnet=true 时含 `x-simulated-trading: 1`；POST body=raw JSON + contentType=application/json；缺 passphrase 前置抛异常
- [X] T038 [P] [US5] 新建 `tests/Domain/Exchange/Signing/BinanceRequestSignerTest.php`：逐字节断言签名输出与迁移前 Binance 行为一致——queryString 原样签名（`signature=` 附后）、HMAC-SHA256 hex、`X-MBX-APIKEY` 头；GET 走 query 通道、POST/DELETE body 通道与现状一致
- [X] T039 [P] [US5] 新建 `tests/Domain/Exchange/ErrorCodeTranslationTest.php`：跨所一致性断言——Bybit `10003` / Binance `-2011` / OKX `51404` 同归 clientError（认证类硬失败，FR-009）；Bybit `10006`/`10016` 归 serverError 可重试，与 Binance/OKX 限流/服务器类重试语义一致；Bybit `10002` 归 clientError 硬失败（clarify Q2）；三家未知码均保守 serverError

### Implementation for User Story 5

- [X] T040 [US5] 新建 `app/Domain/Exchange/Signing/OkxRequestSigner.php`（依赖 T037 测试向量）+ 迁移 OkxAdapter：策略实现 prehash=ISO timestamp+method+requestPath(含 query)+raw JSON body → base64，headers 四件套 + testnet 时 `x-simulated-trading: 1`；**删除 OkxAdapter 的 doRequest/signQuery/httpHeaders override**（T004 已平移 extractErrorCode/translateErrorCode），改为提供 signer。**门槛：001 OkxAdapterTest 全绿（含 T033-T035 testnet header 断言），行为零变化**
- [X] T041 [US5] 全量回归验证 SC-006：`php artisan test --filter='Binance|Okx|Bybit|Signer|Translation'` 全绿；人工核对 `AbstractExchangeAdapter::doRequest` 无任何按家分支（grep `instanceof`/`exchangeCode` 分支为零），三家 signed 请求统一走 限流→signer→HTTP→埋点→错误分类 单一路径

**Checkpoint**: US5 完整可测——三家签名/错误码契约统一，doRequest 完全共用，SC-006 回归零变化达成。

---

## Phase 8: User Story 6 - 路由断言与策略零改动验证 (Priority: P5)

**Goal**: 补齐 001 analyze 沉淀的 C1（ExchangeManager 三家路由显式断言）与 C2（spot_grid/spot_dca 策略以 Bybit 为执行端零改动离线断言）。覆盖 spec FR-010/FR-011 与 SC-005。

**Independent Test**: `php artisan test --filter='ExchangeManagerRouteTest|StrategyExchangeCompatibilityTest'` 通过，全程无网络、无凭据（clarify Q4 + constitution VI）。

### Tests for User Story 6

- [X] T042 [P] [US6] 新建 `tests/Domain/Exchange/ExchangeManagerRouteTest.php`：断言 `ExchangeManager::adapter('binance','spot',$credentials)` 返回 BinanceSpotAdapter、`('okx','spot',...)` 返回 OkxAdapter、`('bybit','spot',...)` 返回 BybitAdapter（FR-010，C1 收尾——路由代码已就绪，仅补三家显式断言）；非法 exchange/market 组合抛异常
- [X] T043 [US6] 新建 `tests/Domain/Exchange/StrategyExchangeCompatibilityTest.php`：stub/mock ExchangeAdapter 注入（记录方法调用序列），分别经 `ExchangeManager::adapter('bybit','spot')`/`('okx','spot')`/`('binance','spot')` 获取适配器驱动 spot_grid 与 spot_dca 策略核心流程（离线，无网络无凭据），断言：策略代码零改动引入，且下单（placeOrder 含 clientOrderId 模板）/撤单（cancelOrder）/查单（getOrderByClientOrderId）调用序列三家一致（FR-011 + SC-005，C2 收尾）

**Checkpoint**: US6 完整可测——交易所扩展对策略透明的架构承诺有显式回归保障。

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: POC 验证命令、quickstart 端到端验收、跨产物一致性校验。

- [X] T044 [P] 新建 `app/Console/Commands/PocVerifyBybitFeed.php` 镜像 `app/Console/Commands/PocVerifyOkxFeed.php` 范式（clarify Q3）：signature `poc:verify-bybit-feed --symbol=BTCUSDT --interval=1m --wait=120`；handle 内 `Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL)`（constitution III）→ `Coroutine\run` 内依次调 getTicker/getKline/subscribeKlineStream 等 wait 秒数 → unsubscribeStream → 打印 PASS/FAIL 与退出码；POC 阶段公共流无需凭据
- [X] T045 运行 [quickstart.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/quickstart.md) 验收矩阵场景 1+2（poc:verify-bybit-feed，SC-001/SC-002）+ 场景 3（离线断言全绿）；场景 4 已由 US4 T034-T036 离线断言覆盖；场景 5（真实下单）待 Bybit testnet key + 网络可达后补测，不阻塞本 feature 收尾
- [X] T046 [P] 运行 `/speckit-analyze` 跨产物一致性校验（spec.md ↔ plan.md ↔ tasks.md ↔ data-model.md ↔ contracts/bybit-api-contract.md），处理任何 flagged gap：补缺漏 task、修正 spec/plan 不一致点、更新 Complexity Tracking 若有违反需 justify（implement 前已执行：0 CRITICAL/HIGH、2 MEDIUM 备注 C1/C2 已分别由 T042/T043 落实；plan.md Project Structure 补 ErrorCodeTranslationTest 一行）

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖，立即可开始
- **Foundational (Phase 2)**: T003→T004→T005 串行（T004 依赖 T003 契约；T005 实现 T004 声明的抽象点）；**T004 阻塞 US3/US5 的签名路径**；US1/US2（公共无签名）实际只依赖 T001+T005 的 interval 映射，可在 T003/T004 进行中并行起步
- **US1 (Phase 3, MVP)**: 依赖 T001 配置 + T005 interval 映射；与 US2/US4 无相互依赖
- **US2 (Phase 4)**: 依赖 T001（ws_url/testnet_ws_url 配置）+ T005 interval 映射；与 US1 独立（公共流无需签名）；实现层同文件 BybitAdapter.php 建议与 US1 串行
- **US3 (Phase 5)**: 依赖 Foundational 全部（T003 契约 + T004 收敛后 doRequest + T005 映射/错误码）；T029 signer 依赖 T003；实现层与 US1/US2 串行（同文件）
- **US4 (Phase 6)**: 依赖 T001 配置键；机制现成，纯测试断言，可在 US3 后任意时点插入
- **US5 (Phase 7)**: T040 依赖 T003+T004（契约 + 基类收敛）与 T037 测试向量；与 US3/US4 在文件层独立（OkxAdapter/Signing 目录），但全量回归 T041 需 Bybit 测试就绪，建议排在 US3 后
- **US6 (Phase 8)**: 依赖 US3（Bybit 下单链路就绪，C2 断言调用序列需要完整接口面）+ T004（Binance 迁移后三家可比）
- **Polish (Phase 9)**: T044 依赖 US1+US2；T045 依赖 T044；T046 analyze 是收尾门禁，依赖全部 story 完成

### User Story Dependencies

- **US1 (P1)**: T001+T005 完成即可开始，无 story 间依赖
- **US2 (P2)**: T001+T005 完成即可开始，与 US1 实现层串行（同文件）但测试可并行
- **US3 (P3)**: Foundational 完成即可开始，实现层与 US1/US2 串行（同文件），测试可并行；真实下单验收待 testnet key
- **US4 (P4)**: T001 完成即可开始，纯断言零依赖
- **US5 (P5)**: T003+T004 完成即可开始；建议排在 US3 后以便 T041 全量回归一次跑尽
- **US6 (P5)**: US3 + T004 完成后开始

### Within Each User Story

- 测试先写并失败，再实现（constitution VI：离线断言先行）
- 私有辅助（interval 映射、订单映射）先于公共方法
- 签名策略（T029）先于依赖它的 getBalance/placeOrder（T030/T031）
- Story 内 checkpoint 验证后再进下一 priority

### Parallel Opportunities

- **Phase 1 Setup**：T001 与 T002 不同文件，可并行
- **Phase 3 US1 测试**：T006/T007/T008/T009 独立测试方法可并行起草（最终合并同一测试文件）
- **Phase 4 US2 测试**：T015/T016 可并行
- **Phase 5 US3 测试**：T021/T022（不同文件）+ T023~T028（独立方法）可并行起草
- **Phase 6 US4 测试**：T034/T035/T036 可并行
- **Phase 7 US5 测试**：T037/T038/T039 不同文件可并行；T040 依赖 T037（测试先写失败再实现）
- **Phase 8 US6 测试**：T042 与 T043 不同文件可并行
- **Phase 9 Polish**：T044（POC 命令）与 T046（analyze 预备）不同范畴；T045 需 US1+US2+T044 完成后跑

---

## Parallel Example: User Story 3

```bash
# 并行起草 US3 测试（BybitAdapterTest 独立方法 + Signing 目录独立文件）：
Task: "T022 BybitRequestSignerTest in tests/Domain/Exchange/Signing/BybitRequestSignerTest.php"
Task: "T021 testCredentialsMissingGuardThrowsBeforeHttp in tests/Domain/Exchange/BybitAdapterTest.php"
Task: "T024 testPlaceOrderMarketBuyUsesQuoteCoin in tests/Domain/Exchange/BybitAdapterTest.php"
Task: "T025 testGetOrderByClientOrderIdFallsBackRealtimeToHistory in tests/Domain/Exchange/BybitAdapterTest.php"

# 然后串行实现（BybitRequestSigner → BybitAdapter.php 同文件方法）：
Task: "T029 BybitRequestSigner in app/Domain/Exchange/Signing/BybitRequestSigner.php"
Task: "T030 getBalance → T031 placeOrder → T032 cancelOrder → T033 getOrder/getOrderByClientOrderId in app/Domain/Exchange/Adapters/BybitAdapter.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1 Setup（T001-T002）：配置修正 + env 占位
2. Phase 2 Foundational（T003-T005）：签名契约 + 基类收敛 + Binance 迁移绿 + Bybit 映射基础设施
3. Phase 3 User Story 1（T006-T014）：REST 行情全实装 + 离线断言
4. **STOP and VALIDATE**：跑 `php artisan test --filter=BybitAdapterTest` + 网络可达时跑 `poc:verify-bybit-feed`
5. Deploy/Demo：Bybit 公共行情可读（SC-001 达成），无需凭据

### Incremental Delivery

1. Setup + Foundational → 基础设施就绪（Binance 迁移回归绿）
2. US1 → 离线断言通过 + REST 行情可用 → Demo MVP（SC-001）
3. US2 → WS bar 可推送 → Demo 实时链路（SC-002）
4. US3 → v5 签名 + 订单 CRUD + 双端点幂等 → 待 testnet key 跑 SC-003
5. US4 → testnet 域名切换离线断言 → 证明 SC-004
6. US5 → OKX 迁移 + 三家回归全绿 → 证明 SC-006
7. US6 → C1/C2 断言落地 → 证明 SC-005
8. Polish → POC 命令 + quickstart 端到端 + speckit-analyze

### Parallel Team Strategy

- 一人主写 BybitAdapter.php 实现（串行 US1→US2→US3，避免同文件冲突）
- 一人并行写测试（US1/US2/US3/US4 测试方法可并行起草）
- 一人负责 Foundational（T003-T005，基类 + Binance 迁移需熟悉签名坑）
- US5/US6 在 US3 后可由第二人接手（不同文件：OkxAdapter/Signing/Manager 测试）

---

## Notes

- [P] tasks = different files OR independent test methods, no dependencies
- [Story] label maps task to US1~US6 for spec.md traceability
- 每个 user story 应独立可完成可测试（US3 签名路径依赖 Foundational 的 T003/T004，已在 Dependencies 显式标注）
- 测试先写并失败再实现（constitution VI：离线断言先行）
- 每个 task 或逻辑组后 commit
- 任何 checkpoint 都可暂停验证当前 story
- 避免：vague tasks、同文件并行冲突、跨 story 的强耦合依赖
- constitution 合规：II bcmath/UTC/clientOrderId 模板（orderLinkId ≤36 字符约束）、III isCanceled 检查/CURL hook 入口控制、IV realtime→history 双端点幂等、VI #[DataProvider]/TTL 键精确清理/C2 无网络、VII PSR-4 单文件单类/Redis phpredis 签名
- 迁移回归门槛：T004（Binance）与 T038（OKX）以既有测试全绿为完成定义，抽象不引入任何行为变化
