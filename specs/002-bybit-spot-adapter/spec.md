# Feature Specification: Bybit 现货交易所适配器

**Feature Branch**: `002-bybit-spot-adapter`

**Created**: 2026-10-18

**Status**: Draft

**Input**: User description: "Bybit 现货适配器（002-bybit-spot-adapter）：对齐 001 OKX spec 范围——现货子集接口（getTicker/getKline/getBalance/placeOrder/cancelOrder/getOrder/getOrderByClientOrderId）+ WS K 线流 + 签名下单 + 模拟盘开关；横切落地 001 analyze 中 C1（ExchangeManager 'bybit' 路由断言）+ C2（策略零改动验证）+ SignatureStrategy 抽象 + translateErrorCode 抽象。Bybit v5 签名 timestamp+api_key+recv_window+queryString/body，与 OKX prehash 不同；testnet 路径 api-testnet.bybit.com / stream-testnet.bybit.com；无需 passphrase（两段认证）；instId 用 BTCUSDT 无分隔符。"

## Clarifications

### Session 2026-10-18

- Q: 签名策略抽象抽到哪一层（完整请求签名 vs 仅签名串构造）？ → A: 完整请求签名策略——输入 method/path/query/body/凭据/时间戳，输出全部签名 headers（签名串+api_key 头+时间戳头），doRequest 三家完全共用
- Q: recv_window 超窗错误归类为可自动重试还是硬失败？ → A: 归类 clientError 硬失败——不重试、不计熔断，错误消息附时钟校准提示（根因几乎总是本地时钟漂移，自动重试不自愈且消耗下单限流配额）
- Q: POC 验证以什么形式交付（独立 artisan 命令 vs 仅 PHPUnit）？ → A: 独立 artisan 命令 poc:verify-bybit-feed（REST ticker/kline + WS 单 bar 回调，对齐 001 T036 范式）承载真实流验收，PHPUnit 离线断言另行覆盖响应映射
- Q: C2 策略零改动验证用什么测试形态？ → A: 离线断言——stub/mock 适配器注入 spot_grid/spot_dca，断言经 ExchangeManager('bybit','spot') 后下单/撤单/查单调用序列与 Binance/OKX 端一致，全程无网络（constitution VI 测试隔离）

## User Scenarios & Testing *(mandatory)*

### User Story 1 - 通过 Bybit 获取现货行情（REST） (Priority: P1)

作为策略运营方，我希望通过 Bybit 适配器拉取现货交易对的最新 ticker 与历史 K 线，无需 API 凭据即可验证行情链路可用性，这样我能在不持有 Bybit 账户的情况下先行确认 Bybit 数据可接入。

**Why this priority**: 行情是所有策略与信号链路的输入前置；公共接口无需凭据，是 POC 验证的最小闭环；与 BinanceSpotAdapter/OkxAdapter 的 getTicker/getKline 接口面完全对齐，意味着验证通过后现有 BarAggregator/SignalPipeline 可零改动复用。

**Independent Test**: 可通过"向 Bybit 适配器请求 BTCUSDT ticker 与最近 500 根 1m K 线，断言返回的成交价/买一/卖一/24h 成交量与 K 线 OHLCV 字段非空且数值合理"独立完成，交付"Bybit 行情可读"这一可用价值。

**Acceptance Scenarios**:

1. **Given** Bybit 适配器已实例化且未注入凭据，**When** 调用 getTicker('BTCUSDT')，**Then** 返回 ExchangeTicker DTO，其 lastPrice/bidPrice/askPrice/volume24h 字段为非空字符串数值，symbol 字段为请求的交易对。
2. **Given** Bybit 适配器已实例化且未注入凭据，**When** 调用 getKline('BTCUSDT', '1m', 500)，**Then** 返回 ExchangeKline 数组，长度 ≤ 500，按 openTime 升序，每根 K 线的 openTime/closeTime/open/high/low/close/volume/quoteVolume 字段为合理数值。
3. **Given** Bybit 适配器实例化时 config exchange.exchanges.bybit.testnet=true，**When** 发起任意公共 REST 请求，**Then** 请求被路由到 Bybit 模拟盘独立域名（api-testnet.bybit.com），而非生产域名（api.bybit.com）。

---

### User Story 2 - 通过 Bybit 订阅 WS K 线 stream (Priority: P2)

作为策略运营方，我希望 Bybit 适配器能被动订阅 Bybit 公共 WS 的 K 线 stream，在每根 bar 关闭时回调上层，这样现有 BarAggregator → SignalFeedRunner → AiAnalyzeJob 链路能以 Bybit 为数据源运行，无需改动下游代码。

**Why this priority**: WS 实时 bar 是网格/DCA/AI 信号策略的实时驱动源；公共流无需 login 鉴权，POC 可验证；与 BinanceSpotAdapter/OkxAdapter 的 subscribeKlineStream 接口面与回调契约对齐，验证通过后 market:feed 可挂接 Bybit 实例。

**Independent Test**: 可通过"启动 Bybit WS kline 订阅 BTCUSDT 1m，等待一根 bar 关闭，断言 onBar 回调被触发且回调参数为完整的 ExchangeKline DTO"独立完成，交付"Bybit 实时 bar 可推送"这一可用价值。

**Acceptance Scenarios**:

1. **Given** Bybit 适配器已实例化且未注入凭据，**When** 调用 subscribeKlineStream('BTCUSDT', '1m', $onBar) 并等待 Bybit 推送一根已关闭的 1m K 线，**Then** onBar 回调被触发，回调参数为 ExchangeKline DTO，其 symbol/interval/openTime/closeTime/OHLCV 字段非空合理。
2. **Given** Bybit WS 连接已建立且正在接收 bar，**When** 调用 unsubscribeStream(subscriptionId)，**Then** 对应协程退出，不再触发 onBar 回调，无资源泄漏。
3. **Given** Bybit WS 连接因网络中断或心跳超时断开，**When** 断线发生，**Then** 适配器按指数退避（1s→2s→4s…上限 60s）自动重连，重连成功后恢复 bar 推送，且每个重连分支显式检查取消状态（cancel 非 sticky，异常被吞后协程不退出）。
4. **Given** Bybit 适配器实例化时 testnet=true，**When** 订阅 WS kline stream，**Then** 连接到 Bybit 模拟盘 WS 路径（stream-testnet.bybit.com），而非生产 WS。

---

### User Story 3 - Bybit 账户与现货下单（模拟盘） (Priority: P3)

作为策略运营方，我希望 Bybit 适配器能在模拟盘环境下完成查询余额、下市价/限价单、撤单、按 clientOrderId 幂等查单，这样现有 spot_grid/spot_dca 策略能以 Bybit 为执行端运行，且崩溃恢复能核实 pending 单真实状态。

**Why this priority**: 下单是策略执行闭环的核心；但需 Bybit testnet 账户的 API 凭据（key+secret 两段）才能验证，POC 阶段先用公共行情验证链路，真实下单验收待凭据到位后补测（与 001 一致的离线断言+真实流双路径）。

**Independent Test**: 待 Bybit testnet key 到位后，可通过"向模拟盘下一笔 BTCUSDT 市价买单，按 clientOrderId 查回订单状态为已成交，再查询余额变动"独立完成；无 key 时用离线断言覆盖订单响应映射与状态归一化逻辑。

**Acceptance Scenarios**:

1. **Given** Bybit 适配器已注入有效的 testnet 账户凭据（key/secret），**When** 调用 getBalance()，**Then** 返回 ExchangeBalance DTO，available 字段反映模拟盘账户的可用 USDT 余额。
2. **Given** Bybit 适配器已注入 testnet 凭据，**When** 调用 placeOrder('BTCUSDT', 'buy', 'market', '100', opts:['clientOrderId' => '{tenant}_{instance}_{ts}_{rand}'])，**Then** 返回 ExchangeOrder DTO，status 为 submitted 或 filled，exchangeOrderId 为 Bybit 返回的订单 ID，clientOrderId 与传入一致。
3. **Given** Bybit 适配器已注入 testnet 凭据，**When** 调用 cancelOrder('BTCUSDT', orderId, clientOrderId)，**Then** 撤销成功返回 true；若订单已成交/已撤销（Bybit 返回订单不存在类错误），返回 false 而非抛异常。
4. **Given** Bybit 适配器已注入 testnet 凭据，**When** 调用 getOrderByClientOrderId('BTCUSDT', clientOrderId)，**Then** 查到则返回 ExchangeOrder DTO；查无此单返回 null（用于崩溃恢复核实 pending 单）。
5. **Given** Bybit 适配器实例化时凭据缺失 api_key 或 api_secret，**When** 发起任意签名请求，**Then** 抛出 ExchangeApiException（凭据不完整），不发起无效请求。

---

### User Story 4 - 模拟盘环境开关 (Priority: P4)

作为策略运营方，我希望通过 config 开关在 Bybit 生产环境与模拟盘之间切换，所有 REST 与 WS 请求自动路由到对应环境的独立域名，这样 POC 阶段用模拟盘验证真实下单，正式运行切到生产环境无需改代码。

**Why this priority**: 模拟盘开关是 POC 验证真实下单的前提（避免主网资金风险）；与 OKX 的"同域名+header 标识"机制不同，Bybit 用完全独立的 testnet 域名区分环境，适配器在请求层整体替换 base URL，不注入额外 header。

**Independent Test**: 可通过"切换 testnet 开关后，检查同一公共请求的目标 URL 域名是 api.bybit.com 还是 api-testnet.bybit.com / WS 是 stream.bybit.com 还是 stream-testnet.bybit.com"独立验证。

**Acceptance Scenarios**:

1. **Given** config exchange.exchanges.bybit.testnet=false，**When** 发起 REST 与 WS 请求，**Then** 请求路由到 Bybit 生产域名（api.bybit.com / stream.bybit.com）。
2. **Given** config exchange.exchanges.bybit.testnet=true，**When** 发起 REST 请求，**Then** 请求目标域名为 api-testnet.bybit.com，响应来自模拟盘。
3. **Given** config exchange.exchanges.bybit.testnet=true，**When** 订阅 WS stream，**Then** 连接到 stream-testnet.bybit.com，而非生产 WS。

---

### User Story 5 - 签名策略与错误码翻译抽象落地 (Priority: P5)

作为平台维护者，我希望三家适配器（Binance/OKX/Bybit）的签名计算与错误码翻译收敛到统一抽象契约，各家差异收敛在各自策略实现内，这样新增交易所时签名/错误处理逻辑不重复散落，且 Binance/OKX 既有行为在抽象落地后完全不变。

**Why this priority**: 这是 001 analyze 沉淀的横切关注点（签名形态差异：Binance queryString+HMAC、OKX prehash+base64、Bybit timestamp+api_key+recv_window+payload；错误码形态差异：Binance 负数、OKX 五位数、Bybit 10xxx）。抽象是"收敛重复"而非新功能，放在 002 落地是因为第三家（Bybit）接入让差异点完全显现；抽象不引入行为变化，验收标准是 Binance/OKX 既有测试全绿。

**Independent Test**: 可通过"抽象落地后运行 Binance/OKX 适配器既有测试套件全绿（回归不变），且 Bybit 适配器通过同一抽象契约实现 v5 签名与 10xxx 错误码分类"独立完成，交付"三家签名/错误翻译契约统一"这一维护性价值。

**Acceptance Scenarios**:

1. **Given** 签名策略抽象已落地，**When** 对比 Binance/OKX 适配器抽象前后的签名请求行为，**Then** 两者完全一致（既有测试全绿），签名逻辑从各自适配器内迁移到各自策略实现。
2. **Given** Bybit 适配器通过签名策略契约实现，**When** 发起 v5 签名请求，**Then** 签名串按 timestamp+api_key+recv_window+queryString/body 形态构造，与 OKX prehash 形态的差异收敛在 Bybit 策略实现内，适配器请求模板（doRequest）三家共用。
3. **Given** 错误码翻译抽象已落地，**When** Bybit 返回 10003（API key 无效类）、Binance 返回 -2011、OKX 返回 51404，**Then** 三家适配器通过统一翻译契约将其归类为 clientError（硬失败，不重试），分类语义跨所一致。
4. **Given** Bybit 返回服务器/限流类错误码，**When** 翻译契约处理，**Then** 归类为 serverError/可重试类别，与 Binance/OKX 同类错误的重试语义一致。

---

### User Story 6 - 路由断言与策略零改动验证 (Priority: P5)

作为策略运营方，我希望 ExchangeManager 能通过 ('bybit', 'spot') 路由返回 Bybit 适配器实例，且现有 spot_grid/spot_dca 策略以 Bybit 为交易所运行时代码零改动，这样交易所扩展对策略层完全透明。

**Why this priority**: 这是 001 analyze 沉淀的 C1/C2 收尾项——001 中 OKX 的 ExchangeManager 路由与策略零改动未显式断言，留到 002 一并补齐为两家生效的回归断言。验证本身不新增功能，但它是"适配器满足接口契约即策略可用"这一架构承诺的显式保障。

**Independent Test**: 可通过"调用 ExchangeManager::adapter('bybit', 'spot', $credentials) 断言返回 BybitAdapter 实例；用 spot_grid/spot_dca 策略绑定 bybit 交易所运行离线断言，断言策略代码零改动且行为与 Binance/OKX 端一致"独立完成，交付"交易所扩展对策略透明"这一架构价值。

**Acceptance Scenarios**:

1. **Given** config 已配置 bybit 交易所，**When** 调用 ExchangeManager::adapter('bybit', 'spot', $credentials)，**Then** 返回 BybitAdapter 实例；同时对 ('okx', 'spot') 与 ('binance', 'spot') 的路由断言一并补齐，三家路由均有显式测试覆盖。
2. **Given** Bybit 适配器已实现现货全接口，**When** spot_grid 或 spot_dca 策略通过 ExchangeManager('bybit', 'spot') 获取适配器运行（离线断言：stub/mock 适配器注入，无网络），**Then** 策略代码零改动，策略行为（下单/撤单/查单调用序列）与 Binance/OKX 端一致。

---

### Edge Cases

- **国内开发机对 Bybit 主网端点可达性未知**：Binance 主网曾被网络层拒绝（testnet 可达），OKX 端点可达性 POC 实测；Bybit 端点（api.bybit.com）与模拟盘端点（api-testnet.bybit.com）可达性需 POC 阶段分别实测；若不可达，离线断言覆盖响应映射逻辑，真实流验收待网络恢复后补（与 001 一致）。
- **Bybit testnet 是独立域名而非 header 标识**：与 OKX 的"同端点+x-simulated-trading header"机制不同，Bybit 模拟盘是完全独立的域名（api-testnet.bybit.com / stream-testnet.bybit.com），testnet 开关实现为 base URL 整体替换，不注入额外 header。
- **Bybit v5 签名形态与 Binance/OKX 均不同**：v5 签名 prehash 为 timestamp+api_key+recv_window+queryString（GET）或 timestamp+api_key+recv_window+body（POST），HMAC-SHA256 输出 hex（非 base64）；与 Binance 的 queryString+HMAC hex、OKX 的 timestamp+method+requestPath+body+base64 均不同 → 签名逻辑收敛到签名策略契约的各自实现。
- **Bybit 凭据为两段（key+secret）**：无 passphrase；凭据完整性校验只需 key/secret 非空，与 OKX 三段校验逻辑不同。
- **Bybit symbol 与平台格式一致**：Bybit 现货 instId 用 BTCUSDT（无分隔符），与平台内部格式及 Binance 一致，无需 OKX 的 BTC-USDT 双向转换；适配器边界不做格式变换。
- **Bybit 错误码形态为 10xxx 数字**：与 Binance 负数（-2011）/OKX 五位数（51404）形态不同 → translateErrorCode 契约的 Bybit 实现按 10xxx 号段分类（认证类→clientError 硬失败；限流/服务器类→可重试）。
- **recv_window 时钟偏差**：Bybit v5 签名含 recv_window（默认 5000ms），本地时钟与服务器偏差超窗时返回 timestamp/recv_window 类错误，归类为 clientError 硬失败（不重试、不计入熔断），错误消息附时钟校准提示（根因几乎总是本地时钟漂移，自动重试不自愈且消耗下单限流配额）。
- **现货不实现强平流**：subscribeLiquidationStream 是合约接口，Bybit 现货适配器调用该方法时抛 LogicException，与 BinanceSpotAdapter/OkxAdapter 行为一致。
- **Bybit WS 公共流无需 login**：K 线 stream 属公共流，订阅时无需 login 鉴权；私有流（账户/订单更新）不在本 spec 范围。
- **Bybit 限流维度与其他所不同**：Bybit v5 限流按 UID+endpoint 类别（如行情 600/5s、下单 10/s），适配器复用 AbstractExchangeAdapter 的 RateLimiter 基类，权重参数按 Bybit 实际限制配置。
- **抽象重构的回归风险**：SignatureStrategy 与 translateErrorCode 抽象涉及 Binance/OKX 既有代码迁移，必须以既有测试全绿为验收门槛，抽象本身不引入任何行为变化。

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: 系统 MUST 实现 ExchangeAdapter 接口的现货子集：getTicker、getKline、getBalance、placeOrder、cancelOrder、getOrder、getOrderByClientOrderId，接口签名与返回 DTO 与 BinanceSpotAdapter/OkxAdapter 完全对齐。
- **FR-002**: 系统 MUST 实现 subscribeKlineStream 与 unsubscribeStream，在 Bybit 公共 WS K 线 bar 关闭时回调 onBar(ExchangeKline)，无需 login 鉴权。
- **FR-003**: 系统 MUST 支持模拟盘环境开关：config exchange.exchanges.bybit.testnet=true 时，REST 请求路由 api-testnet.bybit.com、WS 连接 stream-testnet.bybit.com；testnet=false 时路由生产域名（api.bybit.com / stream.bybit.com）；切换方式为 base URL 整体替换，不注入额外 header。
- **FR-004**: 系统 MUST 在现货适配器调用 subscribeLiquidationStream 时抛 LogicException（现货边界，与 BinanceSpotAdapter/OkxAdapter 一致）。
- **FR-005**: 系统 MUST 复用 AbstractExchangeAdapter 基类的限流前置、健康检查埋点、异常分类（clientError 硬失败/serverError 可重试/networkError 可重试）能力，不绕过基类模板。
- **FR-006**: 系统 MUST 支持 clientOrderId 幂等：placeOrder 透传 clientOrderId，getOrderByClientOrderId 按 clientOrderId 查单返回 ExchangeOrder 或 null（查无此单）。
- **FR-007**: 适配器凭据 MUST 支持两段认证（api_key/api_secret），任一段缺失时签名请求前置失败，不发起无效请求。
- **FR-008**: 系统 MUST 落地签名策略抽象：定义**完整请求签名**策略契约——输入 method/path/query/body/凭据/时间戳，输出该家全部签名相关请求头（签名串头+api_key 头+时间戳头，如 Binance `X-MBX-APIKEY`、OKX `OK-ACCESS-*`、Bybit `X-BAPI-*`）；Binance（queryString+HMAC hex）、OKX（prehash+base64）、Bybit（timestamp+api_key+recv_window+payload+hex）各自实现；三家适配器的请求模板（doRequest）收敛为完全共用、无按家分支；抽象迁移后 Binance/OKX 既有测试全绿。
- **FR-009**: 系统 MUST 落地错误码翻译抽象：AbstractExchangeAdapter 定义统一翻译契约，三家各自实现错误码分类（Binance 负数号段、OKX 5xxxx、Bybit 10xxx），翻译结果归入基类既有的 clientError/serverError/networkError 三分类，重试语义跨所一致。
- **FR-010**: ExchangeManager MUST 能通过 adapter('bybit', 'spot', $credentials) 返回 BybitAdapter 实例；('binance', 'spot') 与 ('okx', 'spot') 的路由 MUST 有显式测试断言（001 C1 收尾）。
- **FR-011**: 现有 spot_grid/spot_dca 策略 MUST 能以 Bybit 为交易所运行，策略代码零改动（适配器满足接口契约即满足）；该能力 MUST 有显式**离线断言**（001 C2 收尾）：用 stub/mock 适配器注入策略，断言经 ExchangeManager('bybit', 'spot') 获取适配器后的下单/撤单/查单调用序列与 Binance/OKX 端一致，全程无网络依赖、无凭据依赖，纳入 CI 稳定运行。
- **FR-012**: Bybit 现货 symbol 与平台内部格式一致（BTCUSDT），适配器边界 MUST NOT 做 symbol 分隔符转换（与 OKX 归一化需求不同）。

### Key Entities *(include if feature involves data)*

- **BybitAdapter**: Bybit 现货适配器实例，继承 AbstractExchangeAdapter，持有 key/secret 两段凭据，实现 ExchangeAdapter 现货子集。
- **模拟盘开关**: config exchange.exchanges.bybit.testnet 布尔值，控制 REST/WS base URL 在生产域名与 testnet 独立域名之间整体切换。
- **签名策略契约**: 三家适配器共用的签名计算抽象，封装各家签名串构造与摘要算法差异，由适配器在签名请求时委托调用。
- **错误码翻译契约**: AbstractExchangeAdapter 定义的统一抽象，将各家数字形态错误码映射为 clientError/serverError/networkError 三分类。

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: POC 验证命令 `poc:verify-bybit-feed`（独立 artisan 命令，对齐 001 `poc:verify-okx-feed` 范式）能通过 Bybit 公共 REST 获取 BTCUSDT ticker 与 500 根 1m K 线，无需 API 凭据，返回字段非空合理。
- **SC-002**: POC 验证命令 `poc:verify-bybit-feed` 能通过 Bybit 公共 WS 订阅 BTCUSDT 1m K 线，在单根 bar 关闭（≤120 秒内）触发 onBar 回调，回调参数为完整 ExchangeKline DTO。
- **SC-003**: 待 Bybit testnet 凭据到位后，能在模拟盘完成一笔 BTCUSDT 市价买单，按 clientOrderId 查回状态为已成交，余额变动反映该笔成交。
- **SC-004**: 切换 testnet 开关后，同一公共请求的目标域名能在 api.bybit.com 与 api-testnet.bybit.com 之间切换（证明开关生效）。
- **SC-005**: 现有 spot_grid 或 spot_dca 策略能通过 ExchangeManager('bybit', 'spot') 运行，策略代码零改动，策略行为与 Binance/OKX 端一致。
- **SC-006**: 签名策略与错误码翻译抽象落地后，Binance/OKX 适配器既有测试套件 100% 通过（回归零变化），Bybit 适配器通过同一契约实现签名与错误分类。

## Assumptions

- Bybit testnet 账户的 API 凭据（key/secret 两段）由用户提供，POC 阶段先用公共行情验证，真实下单验收待凭据到位后补测。
- Bybit 生产/模拟盘端点对国内开发机出口的可达性 POC 阶段实测；若不可达，离线断言覆盖响应映射逻辑，真实流验收待网络恢复后补（与 001 一致）。
- 现有 ExchangeAdapter 接口不变，AbstractExchangeAdapter 基类只做"收敛共用"的最小改造（签名/错误翻译抽抽象点），不新增面向策略层的接口方法。
- 签名策略抽象与错误码翻译抽象的落地以 Binance/OKX 既有测试全绿为门槛；若既有测试本身缺失某分支覆盖，抽象迁移不借机补充新行为，只迁移既有行为。
- 现有 config/exchange.php 补全 bybit 配置项（rest_url/ws_url/testnet 模拟盘端点/rate_limits）。
- Bybit 合约与强平流不在本 spec 范围，留后续 spec；现货适配器对 subscribeLiquidationStream 抛 LogicException。
- Bybit 私有 WS 流（账户余额/订单更新推送）不在本 spec 范围，本 spec 只含公共 K 线 stream。
- Bybit v5 签名 recv_window 取平台默认值 5000ms，实现期按实际服务端时钟偏差容忍度调整。
- Bybit 现货只覆盖 USDT 报价交易对，其他 Quote 币种规则在实现期按 Bybit 实际格式补全。
