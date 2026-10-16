---
description: "Task list for OKX 现货适配器实现"
---

# Tasks: OKX 现货交易所适配器

**Input**: Design documents from `/specs/001-okx-spot-adapter/`（[plan.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/plan.md)、[spec.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/spec.md)、[research.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/research.md)、[data-model.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/data-model.md)、[contracts/okx-api-contract.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/contracts/okx-api-contract.md)、[quickstart.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/quickstart.md)）

**Prerequisites**: plan.md ✓、spec.md ✓（4 个 User Story，P1→P4）、research.md ✓（R-001~R-006 决策）、data-model.md ✓（DTO 字段映射）、contracts/okx-api-contract.md ✓（REST/WS 契约）

**Tests**: 本 feature 显式要求离线断言（spec Assumptions + constitution VI），测试任务按 user story 分组生成。

**Organization**: 按 user story 分组（US1 REST 行情 → US2 WS K线 → US3 签名下单 → US4 模拟盘开关），每个 story 独立可测。Foundational 阶段为跨 story 共享的适配器基础设施（基类可见性、instId 归一化、订单映射）。

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1/US2/US3/US4)
- Include exact file paths in descriptions

## Path Conventions

- **Adapter**: `app/Domain/Exchange/Adapters/OkxAdapter.php`（扩展现有占位骨架）
- **Base**: `app/Domain/Exchange/Adapters/AbstractExchangeAdapter.php`（仅一处可见性修改）
- **Config**: `config/exchange.php`（补全 okx 配置）
- **Tests**: `tests/Domain/Exchange/OkxAdapterTest.php`（新建，离线断言）
- **POC Command**: `app/Console/Commands/PocVerifyOkxFeed.php`（可选）

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: OKX 配置与 env 占位补全，为后续适配器实现提供运行时配置。

- [ ] T001 补全 OKX 配置于 `config/exchange.php`：在 `exchanges.okx` 下增加 `'testnet' => env('EXCHANGE_OKX_TESTNET', false)`、`'testnet_ws_url' => env('EXCHANGE_OKX_TESTNET_WS', 'wss://wspap.okx.com:8443/ws/v5/public')`、`'testnet_rest_url' => null`（OKX REST 模拟盘同域 + header 区分，无独立域名，见 research.md R-005）；移除 `'weight_limit_per_minute' => 600`（OKX 用 2s 分类别限流，见 research.md R-002），新增 `'rate_limits' => ['public' => 20, 'account' => 10, 'trade' => 60]` 与 `'window_seconds' => 2`
- [ ] T002 [P] 在 `.env.example` 增加 OKX 环境变量占位（无真实密钥）：`EXCHANGE_OKX_TESTNET=false`、`EXCHANGE_OKX_KEY=`、`EXCHANGE_OKX_SECRET=`、`EXCHANGE_OKX_PASSPHRASE=`、`EXCHANGE_OKX_TESTNET_WS=wss://wspap.okx.com:8443/ws/v5/public`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: OkxAdapter 跨 story 共享的基础设施——基类签名路径解锁、instId 归一化、订单状态/响应映射。这些是所有 user story 的前置。

**⚠️ CRITICAL**: US1+ 的所有适配器方法都依赖本阶段完成的 instId 归一化与（US3+ 的）订单映射基础设施。

- [ ] T003 将 `AbstractExchangeAdapter::doRequest()` 的可见性由 `private` 改为 `protected`（仅一处可见性修改，无逻辑改动）于 `app/Domain/Exchange/Adapters/AbstractExchangeAdapter.php` —— 解锁 OkxAdapter 覆写 OKX 签名路径（OKX 签名与 Binance 根本不同：prehash=timestamp+method+requestPath+body，见 contracts/okx-api-contract.md）。BinanceSpotAdapter 不受影响（继承不变）。constitution VII：覆写对齐父类声明形态
- [ ] T004 OkxAdapter 增加私有 instId 归一化辅助方法 `toOkxInstId(string $symbol): string`（平台 `BTCUSDT` → OKX `BTC-USDT`，USDT quote 前插 `-`）与 `toPlatformSymbol(string $instId): string`（OKX `BTC-USDT` → 平台 `BTCUSDT`，去 `-`）于 `app/Domain/Exchange/Adapters/OkxAdapter.php`。约束（data-model.md Validation Rules）：本 spec 范围只处理 USDT quote 现货对，非 USDT quote（如 ETH-BTC）按 spec Assumption 留实现期（抛 RuntimeException 或直传，本 task 直传即可）
- [ ] T005 OkxAdapter 增加私有 `mapOrderStatus(string $okxState): string` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`。映射规则（research.md R-006 + data-model.md State Transitions）：`live`→`submitted`、`partially_filled`→`partial`、`filled`→`filled`、`canceled`→`canceled`、其他（`mmp_canceled` 等）→`failed`。输出枚举必须落在 ExchangeOrder.status 七值之一（pending|submitted|partial|filled|canceled|rejected|failed）
- [ ] T006 OkxAdapter 增加私有 `mapOrderResponse(array $data, string $symbol, string $side, string $type, string $quantity, ?string $price, ?string $stopPrice, array $opts): ExchangeOrder` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`。字段映射（data-model.md ExchangeOrder）：exchangeOrderId=`data.ordId`、clientOrderId=`data.clOrdId` ?? `opts.clientOrderId`、symbol=平台格式（入参已归一化）、side=lower(`data.side`)、type=lower(`data.ordType`)、quantity=`data.sz`、price=`data.px`（市价 null）、stopPrice=`data.slOrdPx`/`slTriggerPx`、leverage=null（现货无杠杆）、reduceOnly=false、status=`mapOrderStatus(data.state)`、filledQty=`data.accFillSz`、avgFillPrice=`data.accFillPx`（OKX 直返，无需计算）、fee=`data.fee`、placedAt=`data.cTime`/1000（ms→s）、filledAt=state==filled 时 `data.uTime`/1000

**Checkpoint**: 基础设施就绪——instId 归一化 + 订单映射 + 基类 doRequest 可覆写。user story 实现可开始。

---

## Phase 3: User Story 1 - 通过 OKX 获取现货行情（REST） (Priority: P1) 🎯 MVP

**Goal**: OkxAdapter 实现 getTicker/getKline/symbolFilters/alignToStep/subscribeLiquidationStream（占位），无需凭据即可拉取 BTC-USDT ticker 与 500 根 1m K 线，覆盖 spec FR-001/FR-004/FR-005/FR-008 与 SC-001。

**Independent Test**: quickstart.md 场景 1（REST 行情）+ 场景 3（离线断言）—— `php artisan tinker` 实例化 OkxAdapter 调用 getTicker('BTCUSDT') 与 getKline('BTCUSDT','1m',500)，断言字段非空合理；或 `php artisan test --filter=OkxAdapterTest` 跑离线断言。

### Tests for User Story 1

- [ ] T007 [P] [US1] 新建 `tests/Domain/Exchange/OkxAdapterTest.php`，写 `testInstIdNormalization` 用 `#[DataProvider]` 覆盖：`BTCUSDT`↔`BTC-USDT`、`ETHUSDT`↔`ETH-USDT`、`SOLUSDT`↔`SOL-USDT`；边界：空字符串、单字符、已有 `-` 的输入（应幂等）。 constitution VI：PHPUnit 12 用 `#[DataProvider]` 属性
- [ ] T008 [P] [US1] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testGetTickerMapsResponse`：`Http::fake` 返回 OKX `data.{last,bidPx,askPx,vol24h,open24h,ts,instId}` 样本（参考 contracts/okx-api-contract.md），断言 ExchangeTicker.{lastPrice,bidPrice,askPrice,volume24h} 字符串直传、symbol 为平台格式 `BTCUSDT`（归一化后）、changePct24h 为 bcmath 计算 `(last-open24h)/open24h*100`、timestamp 为毫秒整数。bid < ask
- [ ] T009 [P] [US1] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testGetKlineMapsResponse`：`Http::fake` 返回 OKX `data[][ts,O,H,L,C,vol,volCcy,volCcyQuote]` 数组样本（参考 data-model.md ExchangeKline 表），断言返回 ExchangeKline[] 按 openTime 升序、closeTime=openTime+60000（1m interval，bcmath 推算）、tradesCount=0（OKX 不返回）、symbol 平台格式、OHLCV 字段非空字符串
- [ ] T010 [P] [US1] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testSymbolFiltersMapsAndCaches`：`Http::fake` 返回 OKX `/api/v5/public/instruments` 响应含 `tickSz/lotSz/minSz`；断言首次调用发起 HTTP + 写 Redis 缓存 key `market:symbols:okx:{symbol}:filters`，二次调用不发起 HTTP。constitution VI：TTL 键在 setUp/tearDown 都精确 `Redis::del($key)` 清理

### Implementation for User Story 1

- [ ] T011 [US1] 实现 `getTicker(string $symbol): ExchangeTicker` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：调 `httpGet('/api/v5/market/ticker', ['instId' => $this->toOkxInstId($symbol)])`；映射 `data.{last→lastPrice, bidPx→bidPrice, askPx→askPrice, vol24h→volume24h}` 字符串直传，changePct24h 用 bcmath `bcdiv(bcsub(last,open24h,12),open24h,12)`×100（参考 data-model.md ExchangeTicker 表），symbol=`toPlatformSymbol(data.instId)`，timestamp=`data.ts`（毫秒整数）。bid < ask 是数据特征非代码断言
- [ ] T012 [US1] 实现 `getKline(string $symbol, string $interval, int $limit = 500): array` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：调 `httpGet('/api/v5/market/candles', ['instId' => $this->toOkxInstId($symbol), 'bar' => $interval, 'limit' => min($limit, 300)])`（OKX limit 上限 300）；遍历 `data[]` 行映射为 ExchangeKline：openTime=`row[0]`（ms 整数）、closeTime=openTime+intervalMs（bcmath `bcadd(openTime, 60000, 0)` 对 1m，按 interval 推算）、open/high/low/close=`row[1..4]`、volume=`row[5]`、quoteVolume=`row[7]`（volCcyQuote）、tradesCount=0（OKX 不返回，data-model.md）、exchangeCode='okx'、symbol=平台格式。返回按 openTime 升序（OKX 默认升序）
- [ ] T013 [US1] 实现 `symbolFilters(string $symbol): array` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：参考 BinanceSpotAdapter::symbolFilters 范式；Redis 缓存 key `market:symbols:okx:{symbol}:filters` TTL 86400；调 `httpGet('/api/v5/public/instruments', ['instType' => 'SPOT', 'instId' => $this->toOkxInstId($symbol)])`；映射 `data[0].{tickSz→tick_size, lotSz→step_size, minSz→min_notional}`（OKX 字段名参考 contracts/okx-api-contract.md）。constitution VII：Redis facade 用 phpredis 签名 `Redis::connection('default')->get/setex`
- [ ] T014 [US1] 实现 `alignToStep(string $value, string $step): string` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：bcmath 向下取整到步长（`bcdiv`→`bcmul`→trim），镜像 BinanceSpotAdapter::alignToStep 实现。constitution II：禁止浮点
- [ ] T015 [US1] 实现 `subscribeLiquidationStream(callable $onLiquidation): string` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：直接 `throw new \LogicException('Liquidation stream is futures-only. Use OkxFuturesAdapter (TBD).')`，与 BinanceSpotAdapter 行为一致（spec FR-004：现货边界）

**Checkpoint**: US1 完整可测——getTicker/getKline/symbolFilters/alignToStep 离线断言通过，REST 行情链路就绪。POC 阶段无需凭据即可验证 SC-001。

---

## Phase 4: User Story 2 - 通过 OKX 订阅 WS K 线 stream (Priority: P2)

**Goal**: OkxAdapter 实现 subscribeKlineStream/unsubscribeStream，被动订阅 OKX 公共 WS `candle{interval}` channel，bar 关闭（confirm=1）时回调 onBar(ExchangeKline)，无需 login 鉴权。覆盖 spec FR-002 与 SC-002。

**Independent Test**: quickstart.md 场景 2（WS K 线）—— `php artisan poc:verify-okx-feed --symbol=BTCUSDT --interval=1m --wait=120`，预期 ≤120s 内触发 onBar，回调参数为完整 ExchangeKline DTO。

### Tests for User Story 2

- [ ] T016 [P] [US2] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testMapWsKlinePayload`：喂数据样本（参考 contracts/okx-api-contract.md 推送格式 `{"arg":{"channel":"candle1m","instId":"BTC-USDT"},"data":[["1697443200000","0.001","0.002","0.0005","0.0015","100","0.1","1","1","0","0","0"]]}`），断言 `data[0]` 数组字段映射：[0]→openTime(ms)、[1-4]→OHLC、[5]→volume、[7]→quoteVolume、[8]→confirm 信号；映射后 ExchangeKline 字段非空合理，symbol 为平台格式 BTCUSDT
- [ ] T017 [P] [US2] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testConfirmFlagTriggersOnBarOnly`：标记 `@group ws-integration` 跳过 CI（真实 WS 需网络），手动验证用——断言当 `data[0][8]=1`（confirm=1，bar close）时 onBar 被调用，`data[0][8]=0` 时 onTick（若传入）被调用而非 onBar。无网络环境用纯函数 `mapWsKlinePayload()` 单测替代

### Implementation for User Story 2

- [ ] T018 [US2] 实现 `subscribeKlineStream(string $symbol, string $interval, callable $onBar, ?callable $onTick = null): string` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：镜像 BinanceSpotAdapter::subscribeKlineStream 结构——`Coroutine::create` 内 while(true) 循环：每分支显式 `Coroutine::isCanceled()` 检查并 return（constitution III 坑2：cancel 非 sticky，异常被吞后协程不退出），`try { $this->runKlineWsLoop(...) } catch (CancelException) { return; } catch (Throwable) { Log::warning + 指数退避 1s→2s→4s…上限 60s 重连 }`，重连前/后退避中均检查 isCanceled。订阅参数：channel=`candle{interval}`（如 candle1m）、instId=`toOkxInstId($symbol)`（如 BTC-USDT）。维护 `$this->subscriptions[$subId] = ['cid' => $cid, ...]`，subCounter 自增
- [ ] T019 [US2] 实现私有 `runKlineWsLoop(string $okxInstId, string $channel, callable $onBar, ?callable $onTick, int $heartbeatTimeout): void` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：连接 `$this->wsBaseUrl()`（testnet=true 自动返回 wspap 路径，见 research.md R-001）parse_url 拆 host/port/path；`new WsClient($host, $port, $ssl)` + `set(['timeout' => $heartbeatTimeout, 'keep_alive' => true])`；`$client->upgrade('/ws/v5/public')`（OKX 公共流路径，无需 query string，与 Binance combined stream 不同）；连接后立即 `push(json_encode(['op' => 'subscribe', 'args' => [['channel' => $channel, 'instId' => $okxInstId]]]))`（参考 contracts/okx-api-contract.md 订阅格式）；while(true) recv，解析 `{"arg, data}` payload，遍历 `data[]` 行调 `mapWsKlinePayload($frame->data, $okxInstId, $interval)`，若 `confirm=1` 调 `$onBar($kline)`，否则（confirm=0）若有 `$onTick` 则用 close 近似 lastPrice 构造 ExchangeTicker 调 onTick（镜像 BinanceSpotAdapter::runKlineWsLoop 行为）。OKX 30s 心跳由 WsClient 自动处理
- [ ] T020 [US2] 实现私有 `mapWsKlinePayload(array $row, string $okxInstId, string $interval): ExchangeKline` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：参考 contracts/okx-api-contract.md data[0] 字段：[0] ts→openTime、[1] open、[2] high、[3] low、[4] close、[5] vol→volume、[7] volCcyQuote→quoteVolume、[8] confirm（仅用于触发判断，不入 DTO）；closeTime=openTime+intervalMs（bcmath 推算，OKX 不直返 closeTime）、tradesCount=0、symbol=`toPlatformSymbol($okxInstId)`、exchangeCode='okx'、interval=平台格式（OKX `candle1m` 去前缀 `candle` 后与平台 `1m` 一致）
- [ ] T021 [US2] 实现 `unsubscribeStream(string $subscriptionId): void` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：镜像 BinanceSpotAdapter::unsubscribeStream——`unset($this->subscriptions[$subId])`，若 `Coroutine::exists($cid)` 则 `Coroutine::cancel($cid)`

**Checkpoint**: US2 完整可测——WS K 线订阅可启动、bar close 触发 onBar、退避重连 + isCanceled 检查完备、unsubscribe 协程退出无泄漏。POC 阶段无需凭据即可验证 SC-002。

---

## Phase 5: User Story 3 - OKX 账户与现货下单（模拟盘） (Priority: P3)

**Goal**: OkxAdapter 覆写签名路径（signQuery/doRequest）实现 OKX 三段凭据认证，实装 getBalance/placeOrder/cancelOrder/getOrder/getOrderByClientOrderId。覆盖 spec FR-001/FR-005/FR-006/FR-007 与 SC-003。POC 离线断言先行，真实下单待 demo key 后补。

**Independent Test**: quickstart.md 场景 3（离线断言）覆盖订单响应映射与状态归一化、passphrase 缺失守卫；场景 5（真实下单，待 demo key）跑 SC-003 闭环。

### Tests for User Story 3

- [ ] T022 [P] [US3] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testPassphraseMissingGuardThrowsBeforeHttp`：构造 credentials 仅含 api_key/api_secret 无 passphrase，调 `getBalance()`，断言抛 `ExchangeApiException::clientError`；用 `Http::preventUnexpectedRequests()` 断言未发起任何 HTTP（spec FR-007 + data-model.md Validation Rules passphrase 守卫）。constitution V：前置失败不占额度
- [ ] T023 [P] [US3] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testMapOrderStatus` 用 `#[DataProvider]` 覆盖：`live`→`submitted`、`partially_filled`→`partial`、`filled`→`filled`、`canceled`→`canceled`、`mmp_canceled`→`failed`、空字符串→`failed`（参考 research.md R-006）。需要先暴露 `mapOrderStatus` 为 `protected` 或通过反射调用，建议 protected 留测试钩子
- [ ] T024 [P] [US3] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testPlaceOrderMapsResponse`：`Http::fake` 返回 OKX `POST /api/v5/trade/order` 响应 `data.ordId/clOrdId/state/sz/accFillSz/accFillPx/fee/cTime/uTime` 样本，传入 opts.clientOrderId=`1_1_1697443200_abcd1234`（constitution II 模板 `{tenant_id}_{instance_id}_{timestamp}_{random}`），断言返回 ExchangeOrder.{exchangeOrderId: ordId, clientOrderId: 与传入一致, status: state 映射后, placedAt: cTime/1000}
- [ ] T025 [P] [US3] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testGetOrderByClientOrderIdReturnsNullOnNotFound`：`Http::fake` 返回 OKX 401/-code 51404 风格响应（订单不存在），断言 `getOrderByClientOrderId($symbol, $clientOrderId)` 返回 null 而非抛异常（spec FR-006 + constitution IV：崩溃恢复幂等查询，查无此单返回 null）。注意 OKX 实际错误码以 POC 实测为准，fake 时按 contracts 描述构造

### Implementation for User Story 3

- [ ] T026 [US3] 覆写 `signQuery(string $prehash): string` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：`base64_encode(hash_hmac('sha256', $prehash, (string) ($this->credentials['api_secret'] ?? ''), true))`（raw_binary=true，base64 输出非 hex，参考 research.md R-003 + contracts/okx-api-contract.md 签名 prehash 构造）。注意 OKX 入参是完整 prehash（timestamp+method+requestPath+body），非 Binance 的 query string
- [ ] T027 [US3] 覆写 `doRequest(string $method, string $path, array $query, bool $signed, int $weight): array` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`（依赖 T003 解锁的 protected 可见性 + T026 signQuery）：OKX 签名路径——(1) 限流 `$this->rateLimiter->tryAcquire('okx', "{$method} {$path}", $weight)` 失败抛 rateLimited；(2) 构造 requestPath：GET 拼接 `?`.http_build_query($query)，POST/DELETE requestPath=$path（body 单独构造）；(3) body：GET 为空字符串，POST/DELETE 为 `json_encode($query)`（OKX 要求 raw JSON body 非 form-encoded，参考 contracts/okx-api-contract.md）；(4) timestamp：ISO 8601 UTC `gmdate('Y-m-d\TH:i:s.v\Z')`（research.md R-003，非 Binance 毫秒整数）；(5) 若 $signed 且 credentials['passphrase'] 缺失，前置抛 `ExchangeApiException::clientError`（FR-007）；(6) prehash=timestamp.method.requestPath.body（4 段拼接，参考 contracts）；(7) sign=`$this->signQuery($prehash)`；(8) headers：Content-Type=application/json，signed 时注入 OK-ACCESS-KEY/SIGN/TIMESTAMP/PASSPHRASE 四件套，testnet=true 时额外注入 `x-simulated-trading: 1`（research.md R-005）；(9) `Http::timeout(10)->withHeaders($headers)->send($method, $base.$requestPath, $signed && $method!=='GET' ? ['body' => $body] : [])`；(10) 失败分类 4xx→clientError/5xx→serverError/网络→networkError（镜像基类异常分类）；(11) finally 块 healthChecker->record + Log::warning 包裹（镜像基类）。constitution III：REST 走阻塞 curl（CURL hook 关闭），无协程化担忧
- [ ] T028 [US3] 实现 `getBalance(): ExchangeBalance` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：调 `httpSigned('GET', '/api/v5/account/balance', [], weight: 1)`；映射 `data.{totalEq→totalEquity, margin→totalMargin, upl→unrealizedPnl}` 字符串直传，遍历 `data[]` 聚合 assets = `{ccy: {total: bcadd(availBal, frozenBal, 8), available: availBal}}`，available 字段取 USDT 资产 availBal（参考 data-model.md ExchangeBalance 表）。trimZeros 复用（可提为 protected trait 或在本类复制）
- [ ] T029 [US3] 实现 `placeOrder(string $symbol, string $side, string $type, string $quantity, ?string $price = null, ?string $stopPrice = null, array $opts = []): ExchangeOrder` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：构造 body `['instId' => $this->toOkxInstId($symbol), 'side' => strtolower($side), 'ordType' => strtolower($type), 'sz' => $quantity]`；若 opts.clientOrderId 非空透传为 `clOrdId`（constitution II 模板 `{tenant_id}_{instance_id}_{timestamp}_{random}`）；type=limit 时 `px = $price`、`tdlEq` 不传；调 `httpSigned('POST', '/api/v5/trade/order', $body, weight: 1)`；返回 `$this->mapOrderResponse($data, $symbol, $side, $type, $quantity, $price, $stopPrice, $opts)`（依赖 T006）。注意：OKX 市价买单 quantity 是 base 数量（无 Binance quoteOrderQty 等价机制，spec 未要求）
- [ ] T030 [US3] 实现 `cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：body `['instId' => $this->toOkxInstId($symbol)]`，clientOrderId 非空时 `clOrdId = $clientOrderId`，否则 `ordId = $orderId`；调 `httpSigned('DELETE', '/api/v5/trade/order', $body, weight: 1)`；成功返 true；catch `ExchangeApiException`，若消息含 OKX 订单已撤销/已成交错误码（POC 实测确认，预期类似 `51404` 或 `-1`）返 false（镜像 BinanceSpotAdapter::cancelOrder -2011 处理），其余重新抛
- [ ] T031 [US3] 实现 `getOrder(string $symbol, string $orderId): ExchangeOrder` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：调 `httpSigned('GET', '/api/v5/trade/order', ['instId' => $this->toOkxInstId($symbol), 'ordId' => $orderId], weight: 1)`；返回 `$this->mapOrderResponse($data, $symbol, strtolower($data['side'] ?? 'buy'), strtolower($data['ordType'] ?? 'market'), (string)($data['sz'] ?? '0'), $data['px'] ?? null, null, [])`（依赖 T006）
- [ ] T032 [US3] 实现 `getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder` 于 `app/Domain/Exchange/Adapters/OkxAdapter.php`：调 `httpSigned('GET', '/api/v5/trade/order', ['instId' => $this->toOkxInstId($symbol), 'clOrdId' => $clientOrderId], weight: 1)`；try/catch `ExchangeApiException`，订单不存在类错误返 null（spec FR-006 + constitution IV：崩溃恢复幂等查询），其余重新抛；成功返 `$this->mapOrderResponse($data, $symbol, strtolower($data['side'] ?? 'buy'), strtolower($data['ordType'] ?? 'market'), (string)($data['sz'] ?? '0'), $data['px'] ?? null, null, ['clientOrderId' => $clientOrderId])`

**Checkpoint**: US3 完整可测——签名路径覆写、订单 CRUD 全实装、passphrase 守卫 + clientOrderId 幂等查询就绪。离线断言覆盖订单映射与守卫；真实下单待 OKX demo key 到位后跑 SC-003。

---

## Phase 6: User Story 4 - 模拟盘环境开关 (Priority: P4)

**Goal**: 验证 config `exchange.exchanges.okx.testnet` 开关生效——testnet=true 时 REST 注入 `x-simulated-trading: 1` header、WS 连接 `wspap.okx.com`；testnet=false 时无 header、连 `ws.okx.com`。开关机制在 Phase 5 T027（doRequest override）与继承的 `wsBaseUrl()` 中已实现，本 phase 仅补开关验证测试。覆盖 spec FR-003 与 SC-004。

**Independent Test**: quickstart.md 场景 4（模拟盘开关）—— 切换 testnet 后对比请求 header/WS 路径区分环境。

### Tests for User Story 4

- [ ] T033 [P] [US4] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testTestnetSwitchInjectsHeader`：`config(['exchange.exchanges.okx.testnet' => true])`，实例化带凭据 OkxAdapter，调 `getBalance()`，用 `Http::fake` 捕获请求，断言请求头含 `x-simulated-trading: 1` + OK-ACCESS-* 四件套
- [ ] T034 [P] [US4] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testTestnetSwitchRoutesWsUrl`：`config(['exchange.exchanges.okx.testnet' => true])`，实例化 OkxAdapter，断言通过反射或保护方法访问 `wsBaseUrl()` 返回 `wss://wspap.okx.com:8443/ws/v5/public`（research.md R-001）
- [ ] T035 [P] [US4] 在 `tests/Domain/Exchange/OkxAdapterTest.php` 写 `testProductionSwitchNoHeader`：`config(['exchange.exchanges.okx.testnet' => false])`，实例化 OkxAdapter，`Http::fake` 捕获请求，断言请求头不含 `x-simulated-trading`；`wsBaseUrl()` 返回 `wss://ws.okx.com:8443/ws/v5/public`

**Checkpoint**: US4 完整可测——testnet 开关在 REST header 与 WS 路径双路径生效，证明 spec FR-003 与 SC-004 满足。无需 demo key 即可离线断言开关行为。

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: POC 验证命令、quickstart 端到端验收、跨产物一致性校验。

- [ ] T036 [P] 新建 `app/Console/Commands/PocVerifyOkxFeed.php` 镜像 `app/Console/Commands/PocVerifyFeedCommand.php` 范式：signature `poc:verify-okx-feed --symbol=BTCUSDT --interval=1m --wait=120`；handle 内 `Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL)`（constitution III）→ `Coroutine\run` 内依次调 getTicker/getKline x5/subscribeKlineStream 等 wait 秒数 → unsubscribeStream → 报 PASS/FAIL。POC 阶段公共流无需凭据。description 注明 OKX 公共 REST+WS 验证
- [ ] T037 运行 [quickstart.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/quickstart.md) 验收矩阵场景 1（REST 行情）+ 场景 2（WS bar）+ 场景 3（离线断言），确认 SC-001/SC-002 通过；场景 4（模拟盘开关）+ 场景 5（真实下单）待 OKX demo key + 网络可达后补测，不阻塞本 feature 收尾
- [ ] T038 [P] 运行 `/speckit-analyze` 跨产物一致性校验（spec.md ↔ plan.md ↔ tasks.md ↔ data-model.md ↔ contracts/okx-api-contract.md），处理任何 flagged gap：补缺漏 task、修正 spec/plan 不一致点、更新 Complexity Tracking 若有违反需 justify

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: 无依赖，立即可开始
- **Foundational (Phase 2)**: 依赖 Setup 完成（T001 配置就绪才能验证 testnet 开关）；**BLOCKS 所有 user story**
- **US1 (Phase 3, MVP)**: 依赖 Foundational 的 T004（instId 归一化）；与 US2/US3/US4 无相互依赖，可独立交付
- **US2 (Phase 4)**: 依赖 Foundational 的 T004（instId 归一化，WS 订阅 instId 要归一化）；与 US1/US3 独立（公共流无需签名），可并行
- **US3 (Phase 5)**: 依赖 Foundational 全部（T003 解锁 doRequest + T004 instId + T005/T006 订单映射）；与 US1/US2/US4 在测试层独立，实现层因都改 OkxAdapter.php 建议串行
- **US4 (Phase 6)**: 依赖 Phase 5 T027（doRequest override 注入 x-simulated-trading header）+ T001 配置（testnet_ws_url）；测试任务与 US3 实现层独立
- **Polish (Phase 7)**: 依赖所有 user story 完成；T038 analyze 是收尾门禁

### User Story Dependencies

- **US1 (P1)**: Foundational 完成即可开始，无 story 间依赖
- **US2 (P2)**: Foundational 完成即可开始，与 US1 实现层串行（同文件 OkxAdapter.php）但测试可并行
- **US3 (P3)**: Foundational 完成即可开始，实现层与 US1/US2 串行（同文件），测试可并行；真实下单验收待 demo key
- **US4 (P4)**: 测试任务依赖 US3 的 T027 实现（doRequest override 注入 header）；机制本身在 T027 已就绪，US4 仅验证

### Within Each User Story

- 测试（若包含）先写并失败，再实现（constitution VI：离线断言先行）
- 私有辅助（instId 归一化、订单映射）先于公共方法
- 公共方法（getTicker/getBalance/placeOrder 等）按 spec 接口签名顺序
- Story 内 checkpoint 验证后再进下一 priority

### Parallel Opportunities

- **Phase 1 Setup**：T001 与 T002 不同文件，可并行
- **Phase 2 Foundational**：T003（基类）与 T004/T005/T006（OkxAdapter）不同文件可并行；OkxAdapter 内 T004→T005→T006 串行（同文件）
- **Phase 3 US1 测试**：T007/T008/T009/T010 同测试文件，但独立测试方法可由不同人并行添加（最终合并）
- **Phase 3 US1 实现**：T011→T012→T013→T014→T015 串行（同 OkxAdapter.php）
- **Phase 4 US2 测试**：T016/T017 独立测试方法可并行
- **Phase 5 US3 测试**：T022/T023/T024/T025 独立测试方法可并行
- **Phase 6 US4 测试**：T033/T034/T035 独立测试方法可并行
- **Phase 7 Polish**：T036（POC 命令）与 T038（analyze）不同范畴可并行；T037 需 US1+US2 完成后跑

---

## Parallel Example: User Story 1

```bash
# 并行起草 US1 测试方法（独立方法，最终合并到同一测试文件）：
Task: "T007 testInstIdNormalization in tests/Domain/Exchange/OkxAdapterTest.php"
Task: "T008 testGetTickerMapsResponse in tests/Domain/Exchange/OkxAdapterTest.php"
Task: "T009 testGetKlineMapsResponse in tests/Domain/Exchange/OkxAdapterTest.php"
Task: "T010 testSymbolFiltersMapsAndCaches in tests/Domain/Exchange/OkxAdapterTest.php"

# 然后串行实现 US1 公共方法（同 OkxAdapter.php 文件）：
Task: "T011 getTicker in app/Domain/Exchange/Adapters/OkxAdapter.php"
Task: "T012 getKline in app/Domain/Exchange/Adapters/OkxAdapter.php"
Task: "T013 symbolFilters in app/Domain/Exchange/Adapters/OkxAdapter.php"
Task: "T014 alignToStep in app/Domain/Exchange/Adapters/OkxAdapter.php"
Task: "T015 subscribeLiquidationStream in app/Domain/Exchange/Adapters/OkxAdapter.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1 Setup（T001-T002）：配置 + env 占位
2. Phase 2 Foundational（T003-T006）：基类解锁 + instId 归一化 + 订单映射基础设施
3. Phase 3 User Story 1（T007-T015）：REST 行情全实装 + 离线断言
4. **STOP and VALIDATE**：跑 `php artisan test --filter=OkxAdapterTest` + 网络可达时跑 quickstart 场景 1/2
5. Deploy/Demo：OKX 公共行情可读（SC-001 达成），无需凭据

### Incremental Delivery

1. Setup + Foundational → 基础设施就绪
2. US1 → 离线断言通过 + REST 行情可用 → Demo MVP（SC-001）
3. US2 → WS bar 可推送 → Demo 实时链路（SC-002）
4. US3 → 签名接口 + 离线断言 → 待 demo key 跑 SC-003 真实下单
5. US4 → testnet 开关离线断言通过 → 证明 SC-004
6. Polish → POC 命令 + quickstart 端到端 + speckit-analyze

### Parallel Team Strategy

- 一人主写 OkxAdapter.php 实现（串行 US1→US2→US3，避免同文件冲突）
- 一人并行写 tests/Domain/Exchange/OkxAdapterTest.php 测试方法（US1/US2/US3/US4 测试可并行起草）
- Foundational 与 Setup 可由第三人并行

---

## Notes

- [P] tasks = different files OR independent test methods, no dependencies
- [Story] label maps task to US1/US2/US3/US4 for spec.md traceability
- 每个 user story 应独立可完成可测试（除 US4 测试依赖 US3 的 T027 实现机制）
- 测试先写并失败再实现（constitution VI：离线断言先行）
- 每个 task 或逻辑组后 commit
- 任何 checkpoint 都可暂停验证当前 story
- 避免：vague tasks、同文件并行冲突、跨 story 的强耦合依赖
-constitution 合规：II bcmath/UTC/clientOrderId 模板、III isCanceled 检查、IV getOrderByClientOrderId 幂等、VI #[DataProvider] + TTL 键清理、VII 覆写对齐父类 + Redis phpredis 签名
