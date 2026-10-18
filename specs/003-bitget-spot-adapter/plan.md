# Implementation Plan: Bitget 现货交易所适配器

**Branch**: `003-bitget-spot-adapter` | **Date**: 2026-10-18 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/003-bitget-spot-adapter/spec.md)

**Input**: Feature specification from `/specs/003-bitget-spot-adapter/spec.md`

## Summary

接入 Bitget 作为平台第四个交易所现货适配器，接口面与 BinanceSpotAdapter/OkxAdapter/BybitAdapter 完全对齐（getTicker/getKline/getBalance/placeOrder/cancelOrder/getOrder/getOrderByClientOrderId + WS kline stream）。本 feature 的核心架构命题是验证 **"第四家接入纯增量"**（US5）：签名差异收敛于新增 `BitgetRequestSigner`（复用 spec 002 落地的 RequestSigner 契约）、错误码差异收敛于 `extractErrorCode/translateErrorCode` 的 Bitget 实现，**AbstractExchangeAdapter 零改动、零按家分支（SC-006）**，前三家代码零触碰。Bitget 技术差异：V2 prehash=timestamp(ms)+METHOD+requestPath(+queryString)+body（base64 输出）、ACCESS-KEY/SIGN/TIMESTAMP/PASSPHRASE 四件套、三段凭据（key/secret/passphrase 同 OKX 构）、symbol 即平台格式 BTCUSDT、WS 心跳为文本帧 ping/pong、kline 流**无官方 bar-close 标志**（bar 切换检测判定，本 spec 最大技术风险点，R-006）。经澄清决定 A：Bitget 现货无公开模拟盘，config **不含 testnet 键**（FR-013），真实下单验收 = 下单映射/状态归一化/凭据守卫离线断言 100% 覆盖 + 主网小额市价单待 key 到位按 quickstart 补测。

## Technical Context

**Language/Version**: PHP 8.4（项目已确立，WSL Ubuntu-22.04 开发机 + ServBay MySQL 8.4 生产）

**Primary Dependencies**: Laravel 13.x + Swoole 6.2.3 协程 + Redis（phpredis connector）+ MySQL 8.4。Bitget 适配器复用现有 `AbstractExchangeAdapter` 基类、`ExchangeAdapter` 契约与 spec 002 的 `RequestSigner` 签名策略域，无新 Composer 依赖。

**Storage**: MySQL 8.4（strategy_orders / kline_bars / exchange_accounts / balance_snapshots / exchange_health）+ Redis（限流令牌桶、symbol filters 缓存 `market:symbols:bitget:{symbol}:filters`、崩溃恢复 state）。Bitget 适配器不新增表；passphrase 三段凭据加密存储复用 exchange_accounts 既有结构（OKX 先例）。

**Testing**: PHPUnit 12（`#[DataProvider]` 属性，坑27）。BitgetAdapterTest 镜像前三家离线断言；BitgetRequestSignerTest 黄金样本逐字节断言；ExchangeManagerRouteTest / ErrorCodeTranslationTest / StrategyExchangeCompatibilityTest 三家扩展至四家；真实流验收由 artisan 命令 `poc:verify-bitget-feed` 承载（FR-012），主网小额真实下单待 key 到位后补跑（决定 A）。

**Target Platform**: Linux server（market:feed 单实例协程化长驻；queue:work 独立 worker）。

**Project Type**: web-service（Laravel SaaS monolith，多租户）。

**Performance Goals**: WS bar close → onBar 回调延迟 ≤1s（bar 切换检测以服务端推送为准，无额外等待）；REST ticker/kline p95 <200ms；WS 订阅不阻塞 market:feed 主循环（协程化）。

**Constraints**:
- 协程隔离（constitution III）：WS 重连循环各分支显式 `Coroutine::isCanceled()` 检查并 return（cancel 非 sticky，坑2）；REST 走 Http facade；WS 心跳为**文本帧 ping/pong**（非 JSON op 帧，R-006）。
- 金融精度（constitution II）：bcmath 字符串计算，UTC 毫秒时间戳，clientOrderId 模板 `{tenant_id}_{instance_id}_{timestamp}_{random}`（Bitget clientOid 上限候选 64 字符，天然满足，R-009）。
- 崩溃恢复幂等（constitution IV）：getOrderByClientOrderId 必须实现（orderInfo 主查 → history-orders 兜底双查，R-005；cancelOrder 对订单不存在类错误返回 false 不抛异常）。
- 纯增量回归门槛（US5/SC-004/SC-006）：AbstractExchangeAdapter 零改动零按家分支；Binance/OKX/Bybit 既有测试 100% 绿，不引入任何行为变化；不重开任何横切抽象。
- 时钟守卫：候选码 40013（timestamp 超窗）归类 clientError 硬失败+时钟校准提示（与 Bybit 10002 语义对齐，Edge Case）。
- 无 testnet 键（FR-013/决定 A）：config bitget 段仅生产端点 env 化 + rate_limits；Bitget 生产端点 `https://api.bitget.com` / `wss://ws.bitget.com/v2/ws/public`（已核实）。

**Scale/Scope**: 单实例多租户，Bitget 作为第四交易所接入；前三家适配器零改动；现有 spot_grid/spot_dca 策略代码零改动；私有 WS 流与合约/强平流显式出范围（subscribeLiquidationStream 抛 LogicException）。

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | Plan Compliance | Status |
|---|-----------|------------------|--------|
| I | SDD 优先 | 本 feature 走完整 SDD 五阶段（specify✓ → clarify✓(1问，决定A) → plan当前 → tasks → analyze → implement） | ✓ PASS |
| II | 金融精度与时间基准 | Bitget 金额/数量用 bcmath；symbol 即平台格式无转换（FR-011）；clientOrderId 模板沿用；时间戳 UTC 毫秒（Bitget timestamp 毫秒整数串，R-002）；closeTime bcmath 推算（R-011） | ✓ PASS |
| III | 协程隔离与连接边界 | WS 重连循环各分支显式 `Coroutine::isCanceled()` 检查并 return；文本帧 ping/pong 心跳循环保持取消检查（R-006）；REST 走 Http facade；适配器无慢调用 | ✓ PASS |
| IV | 状态持久化与崩溃恢复幂等 | getOrderByClientOrderId 实现（orderInfo→history-orders 双查，R-005）；cancelOrder 对订单不存在（候选 43001）返回 false 不抛异常（US3 场景3） | ✓ PASS |
| V | 计费语义与风控边界 | placeOrder 由上层 OrderExecutor 包裹 QuotaGuard，适配器只负责交易所受理；凭据三段守卫前置失败不占限流额度（FR-006）；信号只通知不下单 | ✓ PASS |
| VI | 测试隔离与完整路径 | BitgetAdapterTest/SignerTest 离线断言 + `#[DataProvider]`；测试间共享 TTL 键 setUp/tearDown 精确清理（坑24/25）；C2 离线断言无网络；真实流 poc 命令 + 主网小额单后补不阻塞（决定 A） | ✓ PASS |
| VII | PSR-4 与框架基类对齐 | BitgetAdapter 扩展现有占位骨架单文件单类；BitgetRequestSigner 放 `app/Domain/Exchange/Signing/`（spec 002 既定目录）；Redis facade 按 phpredis 签名（坑21/13） | ✓ PASS |

**Gate Result**: 全部 7 项 PASS，无违反，Complexity Tracking 为空。Phase 0 research 完成（R-001~R-011 全部 Resolved，候选待核项均已登记实现期验证指针），Phase 1 设计产物（data-model/contracts/quickstart）已生成，复检结论不变。

## Project Structure

### Documentation (this feature)

```text
specs/003-bitget-spot-adapter/
├── spec.md              # specify✓ + clarify✓（1 条 Clarifications：决定 A 无 testnet）
├── plan.md              # 本文件
├── research.md          # Phase 0 产出（Bitget V2 API 研究决策 R-001~R-011，证据分级）
├── data-model.md        # Phase 1 产出（DTO 字段映射与状态机）
├── quickstart.md        # Phase 1 产出（POC 验证运行指南，含主网小额补测路径）
├── contracts/
│   └── bitget-api-contract.md  # Phase 1 产出（Bitget REST/WS/签名契约）
├── checklists/
│   └── requirements.md  # specify 阶段产出
└── tasks.md             # tasks 阶段产出（非 plan 创建）
```

### Source Code (repository root)

```text
app/Domain/Exchange/
├── Adapters/
│   ├── AbstractExchangeAdapter.php   # 零改动（SC-006 架构验证点：第四家接入不触基类）
│   ├── BinanceSpotAdapter.php        # 零改动（前三家一律不触碰）
│   ├── BinanceFuturesAdapter.php     # 零改动
│   ├── OkxAdapter.php                # 零改动
│   ├── BybitAdapter.php              # 零改动
│   └── BitgetAdapter.php             # 扩展占位骨架，实现现货全接口
├── Signing/
│   ├── RequestSigner.php             # 零改动（spec 002 契约复用）
│   ├── SignedRequest.php             # 零改动
│   ├── BinanceRequestSigner.php      # 零改动
│   ├── OkxRequestSigner.php          # 零改动
│   ├── BybitRequestSigner.php        # 零改动
│   └── BitgetRequestSigner.php       # 新增：prehash=ts+METHOD+path(+query)+body base64 + ACCESS-* 四件套
└── Contracts/                        # 现有 DTO，不改动

config/
└── exchange.php                      # bitget 块：端点 env 化 + 补 rate_limits（public 20/1s、trade/account 10/1s 结构镜像 bybit 段）；不引入 testnet 键（FR-013）

app/Console/Commands/
└── PocVerifyBitgetFeed.php           # FR-012：REST ticker/kline + WS 单 bar 回调 POC（含原始排序方向与 bar 切换证据打印）

tests/Domain/Exchange/
├── BitgetAdapterTest.php             # 离线断言（mock REST/WS 响应映射），镜像前三家 AdapterTest
├── Signing/
│   └── BitgetRequestSignerTest.php   # V2 prehash/base64/ACCESS-* 构造断言（黄金样本逐字节）
├── ExchangeManagerRouteTest.php      # 扩展：四家 spot 路由 + binance futures 分支 + 非法 code
├── ErrorCodeTranslationTest.php      # 扩展：四家错误码分类跨所一致性（数据驱动加 Bitget 数据集）
└── StrategyExchangeCompatibilityTest.php  # 扩展：RecordingAdapter 覆盖 bitget（C2 四家化）
```

**Structure Decision**: 单项目（Laravel monolith）。本 feature 纯增量：唯一新增源码文件为 `BitgetRequestSigner.php` 与 `PocVerifyBitgetFeed.php`，唯一扩展的既有源码文件为 `BitgetAdapter.php`（占位骨架）与 `config/exchange.php`（bitget 段补全）；横切测试三件套（Route/ErrorCode/StrategyCompatibility）只加数据集与路由用例，不改断言结构。ExchangeManager 路由已预留 `'bitget' => new BitgetAdapter(...)`（与 002 同构，路由代码零改动只补测试）。

## Complexity Tracking

> Constitution Check 全部 PASS，无违反需 justify，此表为空。
