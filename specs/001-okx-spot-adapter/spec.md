# Feature Specification: OKX 现货交易所适配器

**Feature Branch**: `001-okx-spot-adapter`

**Created**: 2026-10-16

**Status**: Draft

**Input**: User description: "接入 OKX 交易所作为平台第二个交易所适配器，现货接口面对齐 BinanceSpotAdapter，支持模拟盘（demo trading）开关，POC 验收先用公共行情链路，真实下单待 key 后补"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - 通过 OKX 获取现货行情（REST） (Priority: P1)

作为策略运营方，我希望通过 OKX 适配器拉取现货交易对的最新 ticker 与历史 K 线，无需 API 凭据即可验证行情链路可用性，这样我能在不持有 OKX 账户的情况下先行确认 OKX 数据可接入。

**Why this priority**: 行情是所有策略与信号链路的输入前置；公共接口无需凭据，是 POC 验证的最小闭环；与 BinanceSpotAdapter 的 getTicker/getKline 接口面完全对齐，意味着验证通过后现有 BarAggregator/SignalPipeline 可零改动复用。

**Independent Test**: 可通过"向 OKX 适配器请求 BTC-USDT ticker 与最近 500 根 1m K 线，断言返回的成交价/买一/卖一/24h 成交量与 K 线 OHLCV 字段非空且数值合理"独立完成，交付"OKX 行情可读"这一可用价值。

**Acceptance Scenarios**:

1. **Given** OKX 适配器已实例化且未注入凭据，**When** 调用 getTicker('BTCUSDT')，**Then** 返回 ExchangeTicker DTO，其 lastPrice/bidPrice/askPrice/volume24h 字段为非空字符串数值，symbol 字段为请求的交易对。
2. **Given** OKX 适配器已实例化且未注入凭据，**When** 调用 getKline('BTCUSDT', '1m', 500)，**Then** 返回 ExchangeKline 数组，长度 ≤ 500，按 openTime 升序，每根 K 线的 openTime/closeTime/open/high/low/close/volume/quoteVolume 字段为合理数值。
3. **Given** OKX 适配器实例化时 config exchange.exchanges.okx.testnet=true，**When** 发起任意公共 REST 请求，**Then** 请求被路由到 OKX 模拟盘环境（通过模拟盘标识 header 或模拟盘端点路径区分），而非生产环境。

---

### User Story 2 - 通过 OKX 订阅 WS K 线 stream (Priority: P2)

作为策略运营方，我希望 OKX 适配器能被动订阅 OKX 公共 WS 的 K 线 stream，在每根 bar 关闭时回调上层，这样现有 BarAggregator → SignalFeedRunner → AiAnalyzeJob 链路能以 OKX 为数据源运行，无需改动下游代码。

**Why this priority**: WS 实时 bar 是网格/DCA/AI 信号策略的实时驱动源；公共流无需 login 鉴权，POC 可验证；与 BinanceSpotAdapter 的 subscribeKlineStream 接口面与回调契约对齐，验证通过后 market:feed 可挂接 OKX 实例。

**Independent Test**: 可通过"启动 OKX WS kline 订阅 BTC-USDT 1m，等待一根 bar 关闭，断言 onBar 回调被触发且回调参数为完整的 ExchangeKline DTO"独立完成，交付"OKX 实时 bar 可推送"这一可用价值。

**Acceptance Scenarios**:

1. **Given** OKX 适配器已实例化且未注入凭据，**When** 调用 subscribeKlineStream('BTCUSDT', '1m', $onBar) 并等待 OKX 推送一根已关闭的 1m K 线，**Then** onBar 回调被触发，回调参数为 ExchangeKline DTO，其 symbol/interval/openTime/closeTime/OHLCV 字段非空合理。
2. **Given** OKX WS 连接已建立且正在接收 bar，**When** 调用 unsubscribeStream(subscriptionId)，**Then** 对应协程退出，不再触发 onBar 回调，无资源泄漏。
3. **Given** OKX WS 连接因网络中断或心跳超时断开，**When** 断线发生，**Then** 适配器按指数退避（1s→2s→4s…上限 60s）自动重连，重连成功后恢复 bar 推送，且每个重连分支显式检查取消状态（cancel 非 sticky，异常被吞后协程不退出）。
4. **Given** OKX 适配器实例化时 testnet=true，**When** 订阅 WS kline stream，**Then** 连接到 OKX 模拟盘 WS 路径，而非生产 WS。

---

### User Story 3 - OKX 账户与现货下单（模拟盘） (Priority: P3)

作为策略运营方，我希望 OKX 适配器能在模拟盘环境下完成查询余额、下市价/限价单、撤单、按 clientOrderId 幂等查单，这样现有 spot_grid/spot_dca 策略能以 OKX 为执行端运行，且崩溃恢复能核实 pending 单真实状态。

**Why this priority**: 下单是策略执行闭环的核心；但需 OKX demo 账户的 API 凭据（key+secret+passphrase 三段）才能验证，POC 阶段先用公共行情验证链路，真实下单验收待凭据到位后补测（与 P5/P5.5 一致的离线断言+真实流双路径）。

**Independent Test**: 待 OKX demo key 到位后，可通过"向模拟盘下一笔 BTC-USDT 市价买单，按 clientOrderId 查回订单状态为已成交，再查询余额变动"独立完成；无 key 时用离线断言覆盖订单响应映射与状态归一化逻辑。

**Acceptance Scenarios**:

1. **Given** OKX 适配器已注入有效的 demo 账户凭据（key/secret/passphrase），**When** 调用 getBalance()，**Then** 返回 ExchangeBalance DTO，available 字段反映模拟盘账户的可用 USDT 余额。
2. **Given** OKX 适配器已注入 demo 凭据，**When** 调用 placeOrder('BTCUSDT', 'buy', 'market', '100', opts:['clientOrderId' => '{tenant}_{instance}_{ts}_{rand}'])，**Then** 返回 ExchangeOrder DTO，status 为 submitted 或 filled，exchangeOrderId 为 OKX 返回的订单 ID，clientOrderId 与传入一致。
3. **Given** OKX 适配器已注入 demo 凭据，**When** 调用 cancelOrder('BTCUSDT', orderId, clientOrderId)，**Then** 撤销成功返回 true；若订单已成交/已撤销（OKX 返回订单不存在类错误），返回 false 而非抛异常。
4. **Given** OKX 适配器已注入 demo 凭据，**When** 调用 getOrderByClientOrderId('BTCUSDT', clientOrderId)，**Then** 查到则返回 ExchangeOrder DTO；查无此单返回 null（用于崩溃恢复核实 pending 单）。
5. **Given** OKX 适配器已注入凭据但缺少 passphrase，**When** 发起任意签名请求，**Then** 抛出 ExchangeApiException（凭据不完整），不发起无效请求。

---

### User Story 4 - 模拟盘环境开关 (Priority: P4)

作为策略运营方，我希望通过 config 开关在 OKX 生产环境与模拟盘之间切换，所有 REST 与 WS 请求自动路由到对应环境，这样 POC 阶段用模拟盘验证真实下单，正式运行切到生产环境无需改代码。

**Why this priority**: 模拟盘开关是 POC 验证真实下单的前提（避免主网资金风险），但开关本身是配置层能力，P1-P3 接口实现后开关才生效；与 Binance 的独立 testnet 域名不同，OKX 用同一端点 + 模拟盘标识区分，需要适配器在请求层注入标识。

**Independent Test**: 可通过"切换 testnet 开关后，对比同一公共请求的响应数据特征（模拟盘行情与主网行情数据不同）或检查请求 header/路径是否携带模拟盘标识"独立验证。

**Acceptance Scenarios**:

1. **Given** config exchange.exchanges.okx.testnet=false，**When** 发起 REST 与 WS 请求，**Then** 请求路由到 OKX 生产环境，不携带模拟盘标识。
2. **Given** config exchange.exchanges.okx.testnet=true，**When** 发起 REST 请求，**Then** 请求携带模拟盘标识（OKX demo trading 的环境区分机制），响应来自模拟盘。
3. **Given** config exchange.exchanges.okx.testnet=true，**When** 订阅 WS stream，**Then** 连接到 OKX 模拟盘 WS 路径，而非生产 WS。

---

### Edge Cases

- **国内开发机对 OKX 主网端点可达性未知**：Binance 主网曾被网络层拒绝（testnet 可达），OKX 端点可达性需 POC 阶段实测；若主网不可达，模拟盘端点可达性需单独验证（OKX 模拟盘与主网端点同域，但标识不同）。spec 验收路径按"离线断言+真实流可后补"设计。
- **OKX instId 格式与平台 symbol 格式不同**：OKX 用 BTC-USDT（含连字符），平台内部与现有 Binance 适配器用 BTCUSDT（无连字符）。适配器必须在边界层归一化，策略层不感知格式差异。
- **OKX passphrase 缺失或错误**：OKX 签名要求 key+secret+passphrase 三段，passphrase 缺失或错误时 OKX 返回签名类错误，适配器应归类为硬失败（clientError，不重试，不计入熔断）。
- **OKX WS 公共流无需 login**：与 OKX 私有流（需 login 鉴权）不同，K 线 stream 属公共流，订阅时无需发送 login 消息；spec 范围只含公共流，私有流（账户/订单更新）留后续。
- **现货不实现强平流**：subscribeLiquidationStream 是合约接口（BinanceFuturesAdapter 实现），OKX 现货适配器调用该方法时抛 LogicException，与 BinanceSpotAdapter 行为一致。
- **OKX 限流与 Binance 不同**：OKX 限流维度（按 endpoint 类别/UID）与权重计算与 Binance 不同，适配器复用 AbstractExchangeAdapter 的 RateLimiter 基类，权重参数按 OKX 实际限制配置。

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: 系统 MUST 实现 ExchangeAdapter 接口的现货子集：getTicker、getKline、getBalance、placeOrder、cancelOrder、getOrder、getOrderByClientOrderId，接口签名与返回 DTO 与 BinanceSpotAdapter 完全对齐。
- **FR-002**: 系统 MUST 实现 subscribeKlineStream 与 unsubscribeStream，在 OKX 公共 WS K 线 bar 关闭时回调 onBar(ExchangeKline)，无需 login 鉴权。
- **FR-003**: 系统 MUST 支持模拟盘环境开关：config exchange.exchanges.okx.testnet=true 时，所有 REST 请求注入模拟盘标识、WS 连接模拟盘路径；testnet=false 时路由生产环境。
- **FR-004**: 系统 MUST 在现货适配器调用 subscribeLiquidationStream 时抛 LogicException（现货边界，与 BinanceSpotAdapter 一致）。
- **FR-005**: 系统 MUST 复用 AbstractExchangeAdapter 基类的限流前置、健康检查埋点、异常分类（clientError 硬失败/serverError 可重试/networkError 可重试）能力，不绕过基类模板。
- **FR-006**: 系统 MUST 支持 clientOrderId 幂等：placeOrder 透传 clientOrderId，getOrderByClientOrderId 按 clientOrderId 查单返回 ExchangeOrder 或 null（查无此单）。
- **FR-007**: 适配器凭据 MUST 支持三段认证（api_key/api_secret/passphrase），passphrase 缺失时签名请求前置失败，不发起无效请求。
- **FR-008**: 系统 MUST 归一化 OKX instId 格式（BTC-USDT）与平台 symbol 格式（BTCUSDT），策略层传入/接收的 symbol 始终为平台格式，格式转换在适配器边界内完成。
- **FR-009**: ExchangeManager MUST 能通过 adapter('okx', 'spot', $credentials) 返回 OkxAdapter 实例（路由已预留，适配器实现后即生效）。
- **FR-010**: 现有 spot_grid/spot_dca 策略 MUST 能以 OKX 为交易所运行，策略代码零改动（适配器满足接口契约即满足）。

### Key Entities *(include if feature involves data)*

- **OkxAdapter**: OKX 现货适配器实例，继承 AbstractExchangeAdapter，持有 key/secret/passphrase 三段凭据，实现 ExchangeAdapter 现货子集。
- **模拟盘开关**: config exchange.exchanges.okx.testnet 布尔值，控制 REST 请求标识与 WS 路径的模拟盘/生产环境路由。
- **instId 归一化映射**: OKX 交易所 instId 格式（BTC-USDT）与平台内部 symbol 格式（BTCUSDT）的双向转换，仅存在于适配器边界层，策略层不可见。

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: POC 验证脚本能通过 OKX 公共 REST 获取 BTC-USDT ticker 与 500 根 1m K 线，无需 API 凭据，返回字段非空合理。
- **SC-002**: POC 验证脚本能通过 OKX 公共 WS 订阅 BTC-USDT 1m K 线，在单根 bar 关闭（≤120 秒内）触发 onBar 回调，回调参数为完整 ExchangeKline DTO。
- **SC-003**: 待 OKX demo 凭据到位后，能在模拟盘完成一笔 BTC-USDT 市价买单，按 clientOrderId 查回状态为已成交，余额变动反映该笔成交。
- **SC-004**: 切换 testnet 开关后，同一公共请求的响应数据特征或请求标识能区分模拟盘与生产环境（证明开关生效）。
- **SC-005**: 现有 spot_grid 或 spot_dca 策略能通过 ExchangeManager('okx', 'spot') 运行，策略代码零改动，策略行为与 Binance 端一致。

## Assumptions

- OKX 模拟盘（demo trading）账户的 API 凭据（key/secret/passphrase）由用户提供，POC 阶段先用公共行情验证，真实下单验收待凭据到位后补测。
- OKX 公共 REST/WS 端点对国内开发机出口的可达性 POC 阶段实测；若不可达，离线断言覆盖响应映射逻辑，真实流验收待网络恢复后补（与 P5/P5.5 一致）。
- 现有 ExchangeAdapter 接口与 AbstractExchangeAdapter 基类不变，OKX 适配器复用基类能力，不新增接口方法。
- 现有 config/exchange.php 已预留 okx 配置占位（rest_url/ws_url/markets），spec 落地时补全 testnet 开关与模拟盘端点配置。
- OKX 合约（SWAP 永续）与强平流不在本 spec 范围，留后续 spec；现货适配器对 subscribeLiquidationStream 抛 LogicException。
- OKX 私有 WS 流（账户余额/订单更新推送）不在本 spec 范围，本 spec 只含公共 K 线 stream；私有流留后续以支持实时持仓同步。
- OKX instId 归一化只处理现货 USDT 交易对（如 BTC-USDT → BTCUSDT），其他Quote币种（如 ETH-BTC）的归一化规则在实现期按 OKX 实际格式补全。
