# Phase 0 Research: OKX 现货适配器

**Date**: 2026-10-16 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/spec.md)

解决 plan.md Technical Context 标记的 2 个 NEEDS CLARIFICATION，并记录 OKX V5 API 关键技术决策。

## R-001: OKX 模拟盘（demo trading）WebSocket 端点路径

- **Decision**: 模拟盘公共 WS = `wss://wspap.okx.com:8443/ws/v5/public`；生产公共 WS = `wss://ws.okx.com:8443/ws/v5/public`。config 增加 `testnet_ws_url` 配置项，`wsBaseUrl()` 在 testnet=true 时返回模拟盘路径。
- **Rationale**: OKX 官方文档与多个二级来源一致确认模拟盘用独立域名 `wspap.okx.com`（非主网 ws.okx.com），路径同 `/ws/v5/public`。与 Binance 用独立 testnet 域名（stream.testnet.binance.vision）模式一致，符合现有 `wsBaseUrl()` 的 testnet 切换设计。可选 `?brokerId=9999` 参数 POC 不带（默认 broker）。
- **Alternatives considered**:
  - `wss://wsuspap.okx.com:8443/ws/v5/public`（官方文档中文版出现过此域名）—— 疑似区域/历史差异，POC 实测两个域名连通性，以官方英文文档 `wspap.okx.com` 为准。
  - 主网 + `x-simulated-trading: 1` header 切换模拟盘（REST 模式）—— WS 无此 header 机制，WS 必须用独立域名，故 WS 与 REST 模拟盘切换机制不同：REST 用 header，WS 用独立域名。

## R-002: OKX V5 限流维度与权重参数

- **Decision**: OKX 限流按 endpoint 类别 + 2 秒窗口（非 Binance 的 60 秒 weight 累计）。公共行情 20 req/2s per IP；账户 10 req/2s per UID；下单/撤单 60 req/2s per UID。现有 `RateLimiter`（60s 窗口 weight 累计）需对 OKX 调整：按 endpoint 类别分桶，2s 窗口，权重简化为 1（每次请求计 1），分类别限流。
- **Rationale**: OKX 限流模型与 Binance 根本不同（类别 + 短窗口 vs 全局 weight + 长窗口），强行套用现有 weight_limit_per_minute=600 会失真。按类别分桶 + 2s 窗口 + 权重 1 是最小适配。config 增加分类别限流配置 `rate_limits`（public/account/trade），现有 RateLimiter 支持按 `exchange:endpoint` 维度分桶，endpoint 前缀按类别区分即可。
- **Alternatives considered**:
  - 沿用 weight_limit_per_minute=600 全局限流 —— 与 OKX 实际限制不符，公共行情 600/min（10/s）会超 OKX 20/2s（10/s）边界，下单 600/min 会浪费 OKX 60/2s（1800/min）容量。
  - 重写 RateLimiter 支持 2s 窗口 —— 过度工程，现有 RateLimiter 的窗口秒数可配置（`rate_limiter.window_seconds`），改为 2s 即可，分类别靠 endpoint key 前缀。

## R-003: OKX 签名格式与认证 header

- **Decision**: 签名 = `base64(hash_hmac('sha256', timestamp.method.requestPath.body, secret))`；timestamp 用 ISO 8601 UTC（`2026-10-16T12:00:00.000Z` 格式，非毫秒整数）。Header 四件套：`OK-ACCESS-KEY`、`OK-ACCESS-SIGN`、`OK-ACCESS-TIMESTAMP`、`OK-ACCESS-PASSPHRASE`。覆写 `signQuery()` 用 base64 输出而非 hex；覆写 `httpHeaders()` 注入四件套；覆写 `currentTimestampMs()` 改为 ISO 8601 字符串（或新增 `currentTimestampIso()`）。
- **Rationale**: OKX 签名 prehash = `timestamp+method+requestPath+body`（4 段拼接，非 query string），与 Binance 的 `queryString&signature` 模式根本不同，必须覆写基类的签名链。base64 输出（非 hex）是 OKX 强制。ISO 8601 timestamp 是 OKX 强制（Binance 用毫秒整数）。
- **Alternatives considered**:
  - 改基类 `signQuery` 支持多算法 —— 违反 constitution VII（基类对齐），基类已为 Binance hex 设计，OKX 覆写即可，不污染 Binance。
  - 用 query string 作 body —— OKX prehash 用 raw body（POST 的 form body 或空），GET 请求 body 为空字符串，requestPath 含 query string。

## R-004: OKX instId 归一化

- **Decision**: OKX instId 格式 `BTC-USDT`（现货），平台内部 `BTCUSDT`。适配器边界双向转换：入参 `BTCUSDT` → OKX `BTC-USDT`（`-` 分隔 Quote 前）；OKX 响应 `BTC-USDT` → 平台 `BTCUSDT`（去 `-`）。归一化在 OkxAdapter 内部私有方法，ExchangeKline/ExchangeTicker 的 symbol 字段对外始终是平台格式。
- **Rationale**: 现有策略层（BarAggregator/SignalPipeline/strategy_orders）全部用 `BTCUSDT` 格式，OKX 格式含 `-` 会破坏下游字符串匹配与 Redis key。归一化在适配器边界完成，策略层零改动（满足 spec FR-008/SC-005）。
- **Alternatives considered**:
  - 策略层适配 OKX 格式 —— 违反 spec"策略代码零改动"，且会污染 Binance 路径。
  - 用统一 `BTC-USDT` 全局格式 —— 破坏现有 Binance/Redis key/DB 数据，不可行。

## R-005: OKX 模拟盘 REST 切换机制

- **Decision**: 模拟盘 REST 复用主网域名 `https://www.okx.com`，通过 header `x-simulated-trading: 1` 切换（非独立域名）。`httpHeaders()` 在 testnet=true 时注入此 header。模拟盘 WS 用独立域名（见 R-001），故 REST 与 WS 模拟盘切换机制不同。
- **Rationale**: OKX 官方设计：REST 同域 + header，WS 独立域名。现有 `restBaseUrl()` 的 testnet 切换逻辑需调整——OKX testnet 时 rest_url 不变（仍 www.okx.com），靠 header 区分。config 的 `testnet_rest_url` 对 OKX 留空或同主网，`testnet_ws_url` 填 `wss://wspap.okx.com:8443/ws/v5/public`。
- **Alternatives considered**:
  - 全用独立域名 —— OKX REST 无独立模拟盘域名，只有 header 机制。

## R-006: OKX 订单状态映射

- **Decision**: OKX 订单状态 `live`→`submitted`，`partially_filled`→`partial`，`filled`→`filled`，`canceled`→`canceled`，其他（`mmp_canceled` 等）→`failed`。与 BinanceSpotAdapter 的 `mapOrderStatus` 输出枚举一致（满足 ExchangeOrder DTO 约束）。
- **Rationale**: ExchangeOrder.status 枚举固定（pending|submitted|partial|filled|canceled|rejected|failed），OKX 状态需映射到此枚举，确保下游 OrderExecutor/RiskGuard 不感知交易所差异。
- **Alternatives considered**: 无，枚举对齐是契约约束。

## Summary of Resolved NEEDS CLARIFICATION

| ID | Topic | Resolution |
|----|-------|-----------|
| R-001 | 模拟盘 WS 路径 | `wss://wspap.okx.com:8443/ws/v5/public` |
| R-002 | 限流权重 | 按 endpoint 类别 2s 窗口，公共 20/2s、账户 10/2s、交易 60/2s |

## Sources

- [OKX V5 API 接入指南（中文）](https://app.okx.com/docs-v5/zh/)
- [OKX V5 WebSocket URL 变更公告](https://hk.okx.com/help/changes-to-v5-api-websocket-subscription-parameter-and-url)
- [OKX V5 API 变更日志（英文）](https://hk.okx.com/docs-v5/log_en/)
- [OKX V5 API 限流与认证（Openclaw Skills）](https://www.toolify.ai/openclaw-skills/okx-api-19355)
- [OKX V5 Authentication Python Guide](https://voiceofchain.com/academy/okx-v5-api-authentication-python)
- [OKX V5 WebSocket API Guide](https://voiceofchain.com/academy/okx-v5-api-websocket-python)
