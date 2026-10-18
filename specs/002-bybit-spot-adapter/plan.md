# Implementation Plan: Bybit 现货交易所适配器

**Branch**: `002-bybit-spot-adapter` | **Date**: 2026-10-18 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/002-bybit-spot-adapter/spec.md)

**Input**: Feature specification from `/specs/002-bybit-spot-adapter/spec.md`

## Summary

接入 Bybit 作为平台第三个交易所现货适配器，接口面与 BinanceSpotAdapter/OkxAdapter 完全对齐（getTicker/getKline/getBalance/placeOrder/cancelOrder/getOrder/getOrderByClientOrderId + WS kline stream）。与 001 不同的是本 feature 同步落地两个横切抽象：**完整请求签名策略**（clarify Q1：策略输入 method/path/query/body/凭据/时间戳，输出全部签名 headers+payload，doRequest 收敛为三家完全共用）与**错误码翻译契约**（FR-009：各家原始错误码→clientError/serverError 二分类，重试语义收敛）。Bybit 技术差异：v5 签名 prehash=timestamp+api_key+recv_window+payload（hex）、testnet 独立域名（api-testnet.bybit.com / stream-testnet.bybit.com）、两段凭据无 passphrase、symbol 即平台格式 BTCUSDT 无需归一化。另收尾 001 analyze 的 C1（ExchangeManager 三家路由显式断言）与 C2（策略零改动离线断言）。

## Technical Context

**Language/Version**: PHP 8.4（项目已确立，WSL Ubuntu-22.04 开发机 + ServBay MySQL 8.4 生产）

**Primary Dependencies**: Laravel 13.x + Swoole 6.2.3 协程 + Redis（phpredis connector）+ MySQL 8.4。Bybit 适配器复用现有 `AbstractExchangeAdapter` 基类与 `ExchangeAdapter` 契约，无新 Composer 依赖。

**Storage**: MySQL 8.4（strategy_orders / kline_bars / exchange_accounts / balance_snapshots / exchange_health）+ Redis（限流令牌桶、symbol filters 缓存 `market:symbols:bybit:{symbol}:filters`、崩溃恢复 state）。Bybit 适配器不新增表。

**Testing**: PHPUnit 12（`#[DataProvider]` 属性，坑27）。BybitAdapterTest 镜像 OkxAdapterTest 离线断言；C1 路由断言 + C2 策略零改动离线断言新增；真实流验收由 artisan 命令 `poc:verify-bybit-feed` 承载（clarify Q3），待网络/key 到位后补跑。

**Target Platform**: Linux server（market:feed 单实例协程化长驻；queue:work 独立 worker）。

**Project Type**: web-service（Laravel SaaS monolith，多租户）。

**Performance Goals**: WS bar close → onBar 回调延迟 ≤1s；REST ticker/kline p95 <200ms；WS 订阅不阻塞 market:feed 主循环（协程化）。

**Constraints**:
- 协程隔离（constitution III）：WS 重连循环各分支显式 `Coroutine::isCanceled()` 检查并 return（cancel 非 sticky，坑2）；REST 走 Http facade（CURL hook 由入口控制）。
- 金融精度（constitution II）：bcmath 字符串计算，UTC 毫秒时间戳，clientOrderId 模板 `{tenant_id}_{instance_id}_{timestamp}_{random}`。
- 崩溃恢复幂等（constitution IV）：getOrderByClientOrderId 必须实现（Bybit 需双端点查询：realtime 活跃单 → history 历史单，research R-005）。
- 抽象回归门槛（FR-008/FR-009）：签名策略与错误码翻译迁移后 Binance/OKX 既有测试 100% 绿，不引入任何行为变化。
- recv_window 超窗错误（retCode 10002）归类 clientError 硬失败+时钟校准提示（clarify Q2）。
- Bybit WS 公共 spot 路径：生产 `wss://stream.bybit.com/v5/public/spot`，testnet `wss://stream-testnet.bybit.com/v5/public/spot`（research R-001；config 现有 ws_url 错指 linear 需修正）。

**Scale/Scope**: 单实例多租户，Bybit 作为第三交易所接入；Binance/OKX 适配器仅做抽象迁移、行为零变化；现有 spot_grid/spot_dca 策略代码零改动。

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | Plan Compliance | Status |
|---|-----------|------------------|--------|
| I | SDD 优先 | 本 feature 走完整 SDD 五阶段（specify✓ → clarify✓(4问） → plan当前 → tasks → analyze → implement） | ✓ PASS |
| II | 金融精度与时间基准 | Bybit 金额/数量用 bcmath；symbol 即平台格式无转换（FR-012）；clientOrderId 模板 `{tenant_id}_{instance_id}_{timestamp}_{random}`；时间戳 UTC 毫秒 | ✓ PASS |
| III | 协程隔离与连接边界 | WS 重连循环各分支显式 `Coroutine::isCanceled()` 检查并 return；REST 走 Http facade；适配器无慢调用 | ✓ PASS |
| IV | 状态持久化与崩溃恢复幂等 | getOrderByClientOrderId 实现（双端点 realtime→history，research R-005）；cancelOrder 对 order-not-exist 返回 false 不抛异常（FR-006/US3） | ✓ PASS |
| V | 计费语义与风控边界 | placeOrder 由上层 OrderExecutor 包裹 QuotaGuard，适配器只负责交易所受理；信号只通知不下单 | ✓ PASS |
| VI | 测试隔离与完整路径 | BybitAdapterTest 离线断言 + `#[DataProvider]`；测试间共享 TTL 键 setUp/tearDown 精确清理（坑24/25）；C2 离线断言无网络（clarify Q4）；真实流验收 poc 命令后补不阻塞 | ✓ PASS |
| VII | PSR-4 与框架基类对齐 | BybitAdapter 扩展现有占位骨架单文件单类；签名策略放 `app/Domain/Exchange/Signing/`；Redis facade 按 phpredis 签名（坑21/13） | ✓ PASS |

**Gate Result**: 全部 7 项 PASS，无违反，Complexity Tracking 为空。可进入 Phase 0 research。

## Project Structure

### Documentation (this feature)

```text
specs/002-bybit-spot-adapter/
├── spec.md              # specify✓ + clarify✓（4 条 Clarifications）
├── plan.md              # 本文件
├── research.md          # Phase 0 产出（Bybit v5 API 研究决策）
├── data-model.md        # Phase 1 产出（DTO 字段映射与状态机）
├── quickstart.md        # Phase 1 产出（POC 验证运行指南）
├── contracts/
│   └── bybit-api-contract.md  # Phase 1 产出（Bybit REST/WS/签名契约）
├── checklists/
│   └── requirements.md  # specify 阶段产出（16/16 通过）
└── tasks.md             # tasks 阶段产出（非 plan 创建）
```

### Source Code (repository root)

```text
app/Domain/Exchange/
├── Adapters/
│   ├── AbstractExchangeAdapter.php   # 重构：doRequest 收敛为三家共用（消费 RequestSigner），
│   │                                 #       新增 translateErrorCode/extractErrorCode 抽象点
│   ├── BinanceSpotAdapter.php        # 迁移：签名逻辑迁入 BinanceRequestSigner，行为零变化
│   ├── BinanceFuturesAdapter.php     # 迁移：同上（跟随 Spot 签名路径）
│   ├── OkxAdapter.php                # 迁移：删除 doRequest/signQuery override，迁入 OkxRequestSigner
│   └── BybitAdapter.php              # 扩展占位骨架，实现现货全接口
├── Signing/                          # 新增目录：完整请求签名策略（clarify Q1）
│   ├── RequestSigner.php             # 契约接口（SignedRequest value object）
│   ├── SignedRequest.php             # {headers, query, body, contentType}
│   ├── BinanceRequestSigner.php      # queryString+HMAC hex + X-MBX-APIKEY
│   ├── OkxRequestSigner.php          # prehash+base64 + OK-ACCESS-* + passphrase + simulated header
│   └── BybitRequestSigner.php        # timestamp+api_key+recv_window+payload hex + X-BAPI-*
└── Contracts/                        # 现有 DTO，不改动

config/
└── exchange.php                      # bybit 块：修 ws_url→/v5/public/spot，补 testnet 双端点 + rate_limits

app/Console/Commands/
└── PocVerifyBybitFeed.php            # clarify Q3：REST ticker/kline + WS 单 bar 回调 POC

tests/Domain/Exchange/
├── BybitAdapterTest.php              # 离线断言（mock REST/WS 响应映射），镜像 OkxAdapterTest
├── Signing/
│   ├── BinanceRequestSignerTest.php  # 签名回归：与迁移前 Binance 签名输出逐字节一致
│   ├── OkxRequestSignerTest.php      # 同上（OKX prehash/base64/四件套）
│   └── BybitRequestSignerTest.php    # v5 prehash/hex/X-BAPI-* 构造断言
├── ExchangeManagerRouteTest.php      # C1：binance/okx/bybit 三家 spot 路由显式断言
├── ErrorCodeTranslationTest.php      # T039：三家错误码分类跨所一致性断言（FR-009）
└── StrategyExchangeCompatibilityTest.php  # C2：策略零改动离线断言（clarify Q4）
```

**Structure Decision**: 单项目（Laravel monolith）。签名策略独立 `Signing/` 子目录（领域服务，非 Adapter 内部类）；BybitAdapter 扩展 `Adapters/BybitAdapter.php` 占位骨架；测试与现有 Adapter 测试同级。ExchangeManager 路由已预留 `'bybit' => new BybitAdapter(...)`（C1 只需补测试，零路由代码改动）。

## Complexity Tracking

> Constitution Check 全部 PASS，无违反需 justify，此表为空。
