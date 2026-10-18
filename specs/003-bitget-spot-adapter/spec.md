# Feature Specification: Bitget 现货交易所适配器

**Feature Branch**: `003-bitget-spot-adapter`

**Created**: 2026-10-18

**Status**: Draft

**Input**: User description: "Bitget 现货适配器（spec 003，P6 收尾）：对齐 BinanceSpotAdapter/OkxAdapter/BybitAdapter 既有接口面与实现范式，落地 Bitget 现货 REST 行情（ticker/kline）、WS K 线订阅流（closed bar 才 onBar）、签名下单/撤单/查单（含 clientOrderId 幂等）、testnet/模拟盘开关（若 Bitget 支持）、订单状态映射到平台七值枚举、错误码纳入统一 ErrorCodeTranslation 翻译表；复用 spec 002 抽取的 RequestSigner 签名域新增 BitgetRequestSigner；ExchangeManager 路由与策略兼容性断言扩展至四家；显式不实现合约强平流与私有 WS（抛 LogicException 防范围蔓延）；poc:verify-bitget-feed 命令镜像 poc:verify-okx-feed/poc:verify-bybit-feed 范式。"

## Clarifications

### Session 2026-10-18

- Q: Bitget 现货无公开 testnet/模拟盘（仅合约有 demo），模拟盘开关形态？ → A: **本 spec 不含环境开关**（config 无 testnet 键）；真实下单验收 = 下单响应映射/状态归一化/凭据守卫离线断言 100% 覆盖 + 主网小额市价单待用户 key 到位后按 quickstart 补测。理由：最贴合事实、config 最干净；接受代价——四家 config 结构不完全对齐、真实下单验收挂起

## User Scenarios & Testing *(mandatory)*

### User Story 1 - 通过 Bitget 获取现货行情（REST） (Priority: P1)

作为策略运营方，我希望通过 Bitget 适配器拉取现货交易对的最新 ticker 与历史 K 线，无需 API 凭据即可验证行情链路可用性，这样我能在不持有 Bitget 账户的情况下先行确认 Bitget 数据可接入。

**Why this priority**: 行情是所有策略与信号链路的输入前置；公共接口无需凭据，是 POC 验证的最小闭环；与前三家适配器的 getTicker/getKline 接口面完全对齐，验证通过后现有 BarAggregator/SignalPipeline 可零改动复用。

**Independent Test**: 可通过"向 Bitget 适配器请求 BTCUSDT ticker 与最近 500 根 1m K 线，断言返回的成交价/买一/卖一/24h 成交量与 K 线 OHLCV 字段非空且数值合理"独立完成，交付"Bitget 行情可读"这一可用价值。

**Acceptance Scenarios**:

1. **Given** Bitget 适配器已实例化且未注入凭据，**When** 调用 getTicker('BTCUSDT')，**Then** 返回 ExchangeTicker DTO，其 lastPrice/bidPrice/askPrice/volume24h 字段为非空字符串数值，symbol 字段为请求的交易对。
2. **Given** Bitget 适配器已实例化且未注入凭据，**When** 调用 getKline('BTCUSDT', '1m', 500)，**Then** 返回 ExchangeKline 数组，长度 ≤ 500，按 openTime 升序，每根 K 线的 openTime/closeTime/open/high/low/close/volume/quoteVolume 字段为合理数值。
3. **Given** Bitget 返回的 K 线原始行序与平台契约（升序 openTime）不一致，**When** 适配器映射响应，**Then** 输出数组被归一化为 openTime 升序（与 OKX/Bybit 的升序重排范式一致）。

---

### User Story 2 - 通过 Bitget 订阅 WS K 线 stream (Priority: P2)

作为策略运营方，我希望 Bitget 适配器能被动订阅 Bitget 公共 WS 的 K 线 stream，在每根 bar 关闭时回调上层，这样现有 BarAggregator → SignalFeedRunner → AiAnalyzeJob 链路能以 Bitget 为数据源运行，无需改动下游代码。

**Why this priority**: WS 实时 bar 是网格/DCA/AI 信号策略的实时驱动源；公共流无需 login 鉴权，POC 可验证；与前三家适配器的 subscribeKlineStream 接口面与回调契约对齐，验证通过后 market:feed 可挂接 Bitget 实例。

**Independent Test**: 可通过"启动 Bitget WS kline 订阅 BTCUSDT 1m，等待一根 bar 关闭，断言 onBar 回调被触发且回调参数为完整的 ExchangeKline DTO"独立完成，交付"Bitget 实时 bar 可推送"这一可用价值。

**Acceptance Scenarios**:

1. **Given** Bitget 适配器已实例化且未注入凭据，**When** 调用 subscribeKlineStream('BTCUSDT', '1m', $onBar) 并等待 Bitget 推送一根已关闭的 1m K 线，**Then** onBar 回调被触发，回调参数为 ExchangeKline DTO，其 symbol/interval/openTime/closeTime/OHLCV 字段非空合理。
2. **Given** Bitget WS 连接已建立且正在接收 bar，**When** 调用 unsubscribeStream(subscriptionId)，**Then** 对应协程退出，不再触发 onBar 回调，无资源泄漏。
3. **Given** Bitget WS 连接因网络中断或心跳超时断开，**When** 断线发生，**Then** 适配器按指数退避（1s→2s→4s…上限 60s）自动重连，重连成功后恢复 bar 推送，且每个重连分支显式检查取消状态（cancel 非 sticky，异常被吞后协程不退出）。
4. **Given** Bitget WS 推送帧同时携带 bar 更新与 bar 关闭两种语义，**When** 适配器解析帧，**Then** 仅在 bar 关闭语义时触发 onBar，bar 更新帧不误触发（与前三家 confirm/closed 过滤语义一致）。

---

### User Story 3 - Bitget 账户与现货下单 (Priority: P3)

作为策略运营方，我希望 Bitget 适配器能完成查询余额、下市价/限价单、撤单、按 clientOrderId 幂等查单，这样现有 spot_grid/spot_dca 策略能以 Bitget 为执行端运行，且崩溃恢复能核实 pending 单真实状态。

**Why this priority**: 下单是策略执行闭环的核心；需 Bitget 账户的 API 凭据（key+secret+passphrase 三段）才能验证，POC 阶段先用公共行情验证链路，真实下单验收路径取决于 Bitget 模拟盘可用性（见 User Story 4）。

**Independent Test**: 凭据到位后，可通过"下一笔 BTCUSDT 市价买单，按 clientOrderId 查回订单状态为已成交，再查询余额变动"独立完成；无 key 时用离线断言覆盖订单响应映射与状态归一化逻辑。

**Acceptance Scenarios**:

1. **Given** Bitget 适配器已注入有效账户凭据（key/secret/passphrase），**When** 调用 getBalance()，**Then** 返回 ExchangeBalance DTO，available 字段反映账户的可用 USDT 余额。
2. **Given** Bitget 适配器已注入凭据，**When** 调用 placeOrder('BTCUSDT', 'buy', 'market', '100', opts:['clientOrderId' => '{tenant}_{instance}_{ts}_{rand}'])，**Then** 返回 ExchangeOrder DTO，status 为 submitted 或 filled，exchangeOrderId 为 Bitget 返回的订单 ID，clientOrderId 与传入一致。
3. **Given** Bitget 适配器已注入凭据，**When** 调用 cancelOrder('BTCUSDT', orderId, clientOrderId)，**Then** 撤销成功返回 true；若订单已成交/已撤销（Bitget 返回订单不存在类错误），返回 false 而非抛异常。
4. **Given** Bitget 适配器已注入凭据，**When** 调用 getOrderByClientOrderId('BTCUSDT', clientOrderId)，**Then** 查到则返回 ExchangeOrder DTO（status 映射到平台七值枚举）；查无此单返回 null（用于崩溃恢复核实 pending 单）。
5. **Given** Bitget 适配器实例化时凭据缺失 key、secret 或 passphrase 任一段，**When** 发起任意签名请求，**Then** 抛出 ExchangeApiException（凭据不完整），不发起无效请求。

---

### User Story 4 - 无模拟盘边界与真实下单验收路径 (Priority: P4)

作为策略运营方，我希望明确 Bitget 现货无公开模拟盘这一边界下的验收路径，这样 POC 阶段能在无资金风险的前提下最大化验证覆盖面，且 config 不引入永远不可用的开关。

**Why this priority**: 前三家的环境隔离形态各异（OKX 同域名+header、Bybit 独立 testnet 域名），但 Bitget 现货没有公开 testnet/模拟盘（仅合约有 demo trading）。经澄清决定：本 spec **不含环境开关**（config 无 testnet 键），验证覆盖 = 公共行情实跑 + 下单映射/状态归一化/凭据守卫离线断言 100% 覆盖；真实下单验收降级为主网小额市价单，待用户 key 到位后按 quickstart 补测。

**Independent Test**: 可通过"检查 config/exchange.php bitget 段不含 testnet 键 + 离线断言套件覆盖全部下单相关映射与守卫逻辑"独立完成，交付"无模拟盘边界下验证覆盖面最大化"这一确定性价值。

**Acceptance Scenarios**:

1. **Given** config exchange.exchanges.bitget 配置段，**When** 检查配置结构，**Then** 不含 testnet 键，仅含生产端点（rest_url/ws_url）与限流配置，配置风格与前三家保持可读性对齐。
2. **Given** 无凭据环境，**When** 运行离线断言套件，**Then** 下单相关的响应映射、状态归一化、凭据守卫逻辑 100% 被离线测试覆盖（不依赖任何真实环境）。
3. **Given** 用户后续提供主网小额 key，**When** 按 quickstart 执行真实下单验收，**Then** 一笔 BTCUSDT 市价买单按 clientOrderId 查回状态为已成交，余额变动反映该笔成交。

---

### User Story 5 - 签名器落地与四家横切断言扩展 (Priority: P5)

作为平台维护者，我希望 Bitget 的签名计算通过 spec 002 已落地的 RequestSigner 契约实现（新增 BitgetRequestSigner），错误码翻译、ExchangeManager 路由断言、策略兼容性断言从三家扩展至四家，这样第四家接入不引入任何新的横切抽象，纯增量落地。

**Why this priority**: spec 002 已把签名/错误码/路由/策略兼容性收敛为契约+横切测试，本 spec 的核心价值之一是验证"第四家接入纯增量"这一架构承诺——不重开抽象、不改既有三家代码、横切测试只加数据集与路由用例。

**Independent Test**: 可通过"新增 BitgetRequestSigner 与 Bitget 错误码分类后，运行 tests/Domain/Exchange 全套：三家既有测试 100% 通过（回归零变化），Bitget 签名/错误码/路由/兼容性新用例全绿"独立完成，交付"四家签名/错误/路由/策略契约统一"这一维护性价值。

**Acceptance Scenarios**:

1. **Given** BitgetRequestSigner 已实现 RequestSigner 契约，**When** 发起签名请求，**Then** 输出 Bitget 规定的全套签名头（ACCESS-KEY/ACCESS-SIGN/ACCESS-TIMESTAMP/ACCESS-PASSPHRASE），签名串按 Bitget prehash 形态构造，适配器请求模板（doRequest）四家完全共用、无按家分支。
2. **Given** 错误码翻译扩展至四家，**When** Bitget 返回认证类错误码、前三家各自返回认证类错误码，**Then** 四家均归类 clientError（硬失败，不重试）；Bitget 返回限流/服务器类错误码时归类可重试类别，语义跨所一致。
3. **Given** config 已配置 bitget 交易所，**When** 调用 ExchangeManager::adapter('bitget', 'spot', $credentials)，**Then** 返回 BitgetAdapter 实例；四家 spot 路由与 binance futures 分支均有显式测试断言。
4. **Given** Bitget 适配器已实现现货全接口，**When** 扩展 StrategyExchangeCompatibilityTest 至四家，**Then** RecordingAdapter 驱动的 spot_grid/spot_dca 决策链与崩溃恢复链断言覆盖 bitget 交易所，策略源码保持零具体适配器引用。

---

### Edge Cases

- **国内开发机对 Bitget 端点可达性未知**：Binance 主网曾被网络层拒绝（testnet 可达，坑 #23）；Bitget 端点（api.bitget.com / ws.bitget.com）可达性需 POC 阶段实测；若不可达，离线断言覆盖响应映射逻辑，真实流验收待网络恢复后补（与前两家一致）。
- **Bitget 现货无公开 testnet/模拟盘**：仅合约有 demo trading；经澄清决定本 spec 不含环境开关（config 无 testnet 键），真实下单验收 = 离线断言全覆盖 + 主网小额单待 key 到位补测——这是本 spec 与前三家最大的范围差异点。
- **Bitget 凭据为三段（key/secret/passphrase）**：与 OKX 同构；凭据完整性校验为三段非空，passphrase 存储复用 exchange_accounts 加密结构（OKX 已有三段凭据加密存储先例）。
- **Bitget 签名 prehash 形态独有**：timestamp(ms)+METHOD+requestPath(+queryString)+body，HMAC-SHA256 输出 base64；与 Binance（queryString+HMAC hex）、OKX（timestamp+method+requestPath+body base64）、Bybit（timestamp+api_key+recv_window+payload hex）均不同 → 差异收敛在 BitgetRequestSigner 实现内。
- **Bitget symbol 与平台格式一致**：现货 symbol 用 BTCUSDT（无分隔符），与平台内部格式及 Binance/Bybit 一致，无需 OKX 的 BTC-USDT 双向转换；适配器边界不做格式变换。
- **Bitget 成功码与错误码形态**：V2 响应 code 字段 "00000" 为成功，错误码为数字串（与 Binance 负数、OKX 5xxxx、Bybit 10xxx 形态不同）→ extractErrorCode/translateErrorCode 的 Bitget 实现按 Bitget 号段分类。
- **时钟偏差**：Bitget 签名时间戳为毫秒级，本地时钟与服务器偏差过大时签名请求被拒，归类 clientError 硬失败（不重试、不计熔断），错误消息附时钟校准提示（与 Bybit recv_window 超窗处理语义一致）。
- **现货不实现强平流**：subscribeLiquidationStream 是合约接口，Bitget 现货适配器调用该方法时抛 LogicException，与其他三家现货适配器行为一致。
- **Bitget WS 公共流无需 login**：K 线 stream 属公共流，订阅时无需 login 鉴权；私有流（账户/订单更新）不在本 spec 范围。
- **WS 心跳机制差异**：Bitget WS 心跳为文本帧 ping/pong（与前三家机制不同），重连循环需按 Bitget 心跳协议实现并保持取消检查。

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: 系统 MUST 实现 ExchangeAdapter 接口的现货子集：getTicker、getKline、getBalance、placeOrder、cancelOrder、getOrder、getOrderByClientOrderId，接口签名与返回 DTO 与前三家适配器完全对齐。
- **FR-002**: 系统 MUST 实现 subscribeKlineStream 与 unsubscribeStream，在 Bitget 公共 WS K 线 bar 关闭时回调 onBar(ExchangeKline)，bar 更新帧不误触发，无需 login 鉴权；重连循环按指数退避（1s→2s→4s…上限 60s）且各分支显式检查协程取消状态。
- **FR-003**: 系统 MUST 在现货适配器调用 subscribeLiquidationStream 时抛 LogicException（现货边界，与前三家一致）。
- **FR-004**: 系统 MUST 复用 AbstractExchangeAdapter 基类的限流前置、健康检查埋点、异常分类（clientError 硬失败/serverError 可重试/networkError 可重试）能力，不绕过基类模板。
- **FR-005**: 系统 MUST 支持 clientOrderId 幂等：placeOrder 透传 clientOrderId，getOrderByClientOrderId 按 clientOrderId 查单返回 ExchangeOrder 或 null（查无此单）。
- **FR-006**: 适配器凭据 MUST 支持三段认证（key/secret/passphrase），任一段缺失时签名请求前置失败，不发起无效请求。
- **FR-007**: 系统 MUST 新增 BitgetRequestSigner 实现 spec 002 的 RequestSigner 契约：输出 Bitget 全套签名头（ACCESS-KEY/ACCESS-SIGN/ACCESS-TIMESTAMP/ACCESS-PASSPHRASE），签名串按 timestamp+METHOD+requestPath(+queryString)+body 形态 HMAC-SHA256 并 base64 输出；四家适配器的请求模板（doRequest）保持完全共用、无按家分支。
- **FR-008**: 系统 MUST 实现 Bitget 错误码分类：extractErrorCode 识别 V2 响应 code 字段（"00000" 为成功），translateErrorCode 将认证类归为 clientError（硬失败）、限流/服务器类归为可重试类别；ErrorCodeTranslationTest 扩展至四家分类一致性。
- **FR-009**: ExchangeManager MUST 能通过 adapter('bitget', 'spot', $credentials) 返回 BitgetAdapter 实例；ExchangeManagerRouteTest MUST 扩展覆盖四家 spot 路由 + binance futures 分支 + 非法 code 抛异常。
- **FR-010**: 现有 spot_grid/spot_dca 策略 MUST 能以 Bitget 为交易所运行，策略代码零改动；StrategyExchangeCompatibilityTest MUST 扩展至四家，RecordingAdapter 驱动决策链与崩溃恢复链断言覆盖 bitget，全程无网络、无凭据依赖，纳入 CI 稳定运行。
- **FR-011**: Bitget 现货 symbol 与平台内部格式一致（BTCUSDT），适配器边界 MUST NOT 做 symbol 分隔符转换。
- **FR-012**: 系统 MUST 提供 `poc:verify-bitget-feed` 独立 artisan 命令（镜像 poc:verify-okx-feed/poc:verify-bybit-feed 范式）：覆盖 REST ticker/kline 拉取断言 + WS 单 bar 回调等待（≤120 秒），公共流无需凭据，退出码反映成败。
- **FR-013**: 系统 MUST NOT 引入模拟盘/testnet 环境开关（Bitget 现货无公开模拟盘，澄清决定 A）；config/exchange.php 的 bitget 配置段仅含生产端点（rest_url/ws_url）与限流配置，不含 testnet 键；真实下单验收以主网小额市价单形式记录于 quickstart，待 key 到位补测。

### Key Entities *(include if feature involves data)*

- **BitgetAdapter**: Bitget 现货适配器实例，继承 AbstractExchangeAdapter，持有 key/secret/passphrase 三段凭据，实现 ExchangeAdapter 现货子集。
- **BitgetRequestSigner**: RequestSigner 契约的 Bitget 实现，封装 Bitget prehash 构造与 ACCESS-* 四件套签名头输出。
- **无环境开关边界**: config exchange.exchanges.bitget 仅含生产端点与限流配置（澄清决定 A：Bitget 现货无公开模拟盘，不引入 testnet 键）。
- **错误码翻译契约**: AbstractExchangeAdapter 定义的统一抽象，Bitget 实现将 V2 数字串错误码映射为 clientError/serverError/networkError 三分类。

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: POC 验证命令 `poc:verify-bitget-feed` 能通过 Bitget 公共 REST 获取 BTCUSDT ticker 与 500 根 1m K 线，无需 API 凭据，返回字段非空合理。
- **SC-002**: POC 验证命令 `poc:verify-bitget-feed` 能通过 Bitget 公共 WS 订阅 BTCUSDT 1m K 线，在单根 bar 关闭（≤120 秒内）触发 onBar 回调，回调参数为完整 ExchangeKline DTO。
- **SC-003**: 下单响应映射、状态归一化、凭据三段守卫逻辑被离线断言 100% 覆盖；真实下单验收为主网一笔 BTCUSDT 小额市价单按 clientOrderId 查回成交，待 key 到位后按 quickstart 补测（Bitget 现货无公开模拟盘，澄清决定 A）。
- **SC-004**: BitgetRequestSigner 落地后，tests/Domain/Exchange 全套测试中前三家（Binance/OKX/Bybit）既有用例 100% 通过（回归零变化），Bitget 签名/错误码/路由/策略兼容性新用例全绿。
- **SC-005**: 现有 spot_grid 或 spot_dca 策略能通过 ExchangeManager('bitget', 'spot') 运行（离线断言），策略代码零改动，策略行为与其他三家端一致。
- **SC-006**: AbstractExchangeAdapter 与本 spec 相关的共享代码保持零按家分支（四家差异全部收敛在各自 Signer/翻译实现内）。

## Assumptions

- Bitget 账户的 API 凭据（key/secret/passphrase 三段）由用户提供，POC 阶段先用公共行情验证；Bitget 现货无公开模拟盘（澄清决定 A），真实下单验收为主网小额单，待 key 到位后补测。
- Bitget 生产端点对国内开发机出口的可达性 POC 阶段实测；若不可达，离线断言覆盖响应映射逻辑，真实流验收待网络恢复后补（与前两家一致）。
- 现有 ExchangeAdapter 接口、RequestSigner 契约、错误码翻译契约均不变；本 spec 纯增量，不修改前三家适配器代码。
- 现有 config/exchange.php 补全 bitget 配置项（rest_url/ws_url/rate_limits），不含 testnet/模拟盘相关键（澄清决定 A）。
- Bitget 合约与强平流不在本 spec 范围，留后续 spec；现货适配器对 subscribeLiquidationStream 抛 LogicException。
- Bitget 私有 WS 流（账户余额/订单更新推送）不在本 spec 范围，本 spec 只含公共 K 线 stream。
- passphrase 凭据的加密存储复用 exchange_accounts 既有结构（OKX 三段凭据已有先例），本 spec 不涉及存储层变更。
- Bitget 现货只覆盖 USDT 报价交易对，其他 Quote 币种规则在实现期按 Bitget 实际格式补全。
