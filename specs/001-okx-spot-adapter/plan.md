# Implementation Plan: OKX 现货交易所适配器

**Branch**: `001-okx-spot-adapter` | **Date**: 2026-10-16 | **Spec**: [spec.md](file:///e:/Work/数字货币交易Saas平台/specs/001-okx-spot-adapter/spec.md)

**Input**: Feature specification from `/specs/001-okx-spot-adapter/spec.md`

## Summary

接入 OKX 作为平台第二个交易所现货适配器，接口面与 BinanceSpotAdapter 完全对齐（getTicker/getKline/getBalance/placeOrder/cancelOrder/getOrder/getOrderByClientOrderId + WS kline stream），复用 AbstractExchangeAdapter 基类的限流/健康埋点/异常分类能力。关键技术差异：OKX 三段凭据（key/secret/passphrase）、base64 HMAC-SHA256 签名（prehash=timestamp+method+requestPath+body）、instId 含连字符（BTC-USDT 需归一化为平台 BTCUSDT）、模拟盘用 header `x-simulated-trading: 1` 切换而非独立域名。POC 验收先用公共行情（无需凭据），真实下单待 demo key 后补。

## Technical Context

**Language/Version**: PHP 8.4（项目已确立，WSL Ubuntu-22.04 开发机 + ServBay MySQL 8.4 生产）

**Primary Dependencies**: Laravel 12 + Swoole 6.2.3 协程 + Filament v5 + Reverb + Redis（phpredis connector）+ MySQL 8.4。OKX 适配器复用现有 `AbstractExchangeAdapter` 基类与 `ExchangeAdapter` 契约，无新依赖。

**Storage**: MySQL 8.4（strategy_orders / kline_bars / exchange_accounts / balance_snapshots / exchange_health）+ Redis（限流令牌桶 key `market:ratelimit`、symbol filters 缓存 `market:symbols:okx:{symbol}:filters`、崩溃恢复 state）。OKX 适配器不新增表，复用现有 schema。

**Testing**: PHPUnit 12（P5.5 已迁入 150+ 断言，`#[DataProvider]` 属性）。OKX 适配器测试用离线断言（mock HTTP/WS 响应映射 DTO）覆盖，真实流验收待网络/key 到位后补（与 P5/P5.5 一致）。

**Target Platform**: Linux server（market:feed 单实例协程化长驻；queue:work ai/notifications/orders/aggregation 独立 worker）。

**Project Type**: web-service（Laravel SaaS monolith，多租户）。

**Performance Goals**: WS bar close → onBar 回调延迟 ≤1s；REST ticker/kline p95 <200ms；单实例并发订阅 OKX 公共流不阻塞 market:feed 主循环（协程化）。

**Constraints**: 
- 协程隔离（constitution III）：`Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL & ~SWOOLE_HOOK_TCP & ~SWOOLE_HOOK_UNIX & ~SWOOLE_HOOK_UDG)`，REST 走阻塞 curl，WS 走 Swoole 原生 socket 协程。
- 金融精度（constitution II）：bcmath 字符串计算，UTC 时间戳，clientOrderId 模板 `{tenant_id}_{instance_id}_{timestamp}_{random}`。
- 崩溃恢复幂等（constitution IV）：getOrderByClientOrderId 必须实现，pending 单崩溃恢复核实。
- OKX 模拟盘 WS 路径：[NEEDS CLARIFICATION: OKX demo trading WS endpoint path — 生产用 wss://ws.okx.com:8443/ws/v5/public，模拟盘路径待 research 确认]
- OKX 限流权重：[NEEDS CLARIFICATION: OKX V5 限流维度与权重参数，与 Binance 1200/min 不同，待 research 确认按 endpoint 类别的实际限制]

**Scale/Scope**: 单实例多租户，OKX 作为第二交易所接入；现有 Binance 适配器零改动；现有 spot_grid/spot_dca 策略代码零改动（满足接口契约即满足）。

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| # | Principle | Plan Compliance | Status |
|---|-----------|------------------|--------|
| I | SDD 优先 | 本 feature 走完整 SDD 五阶段（specify✓ → plan当前 → tasks → analyze → implement） | ✓ PASS |
| II | 金融精度与时间基准 | OKX 金额/数量用 bcmath；instId 归一化在适配器边界；clientOrderId 模板 `{tenant_id}_{instance_id}_{timestamp}_{random}`；时间戳 UTC 毫秒 | ✓ PASS |
| III | 协程隔离与连接边界 | OKX WS 重连循环各分支显式 `Coroutine::isCanceled()` 检查并 return（cancel 非 sticky，坑2）；REST 走阻塞 curl（CURL hook 关闭，坑1）；慢调用不进协程（OKX 适配器本身无慢调用，AI/计费在上层） | ✓ PASS |
| IV | 状态持久化与崩溃恢复幂等 | getOrderByClientOrderId 实现（pending 核实用独立标志不复用业务字段，坑9）；Redis 每 bar 写 state + DB 快照双写由上层 StrategyRunner 负责，适配器只提供查询能力 | ✓ PASS |
| V | 计费语义与风控边界 | OKX placeOrder 由上层 OrderExecutor 包裹 QuotaGuard（freeze/settle/reverse），适配器只负责交易所受理；未知 plan_code 抛异常不降级；信号只通知不下单（适配器不触发信号） | ✓ PASS |
| VI | 测试隔离与完整路径 | OKX 适配器测试用离线断言（mock 响应）+ `#[DataProvider]`；测试间共享 TTL 键（限流桶）在 setUp/tearDown 精确清理；真实流验收可后补不阻塞 | ✓ PASS |
| VII | PSR-4 与框架基类对齐 | OkxAdapter 单文件单类；覆写 `signQuery`/`httpHeaders`/`wsBaseUrl`/`buildQuery` 前核对父类声明形态；readonly trait 不引入；Redis facade 按 phpredis 签名（zAdd/eval） | ✓ PASS |

**Gate Result**: 全部 7 项 PASS，无违反，Complexity Tracking 为空。可进入 Phase 0 research。

## Project Structure

### Documentation (this feature)

```text
specs/001-okx-spot-adapter/
├── spec.md              # specify 阶段产出（已完成）
├── plan.md              # 本文件（plan 阶段产出）
├── research.md          # Phase 0 产出（OKX API 技术研究）
├── data-model.md        # Phase 1 产出（DTO 字段映射与状态机）
├── quickstart.md        # Phase 1 产出（POC 验证运行指南）
├── contracts/
│   └── okx-api-contract.md  # Phase 1 产出（OKX REST/WS 接口契约）
├── checklists/
│   └── requirements.md  # specify 阶段产出（已完成）
└── tasks.md             # tasks 阶段产出（非 plan 创建）
```

### Source Code (repository root)

```text
app/Domain/Exchange/
├── Adapters/
│   ├── AbstractExchangeAdapter.php   # 现有基类，不改动
│   ├── BinanceSpotAdapter.php        # 现有现货参考实现，不改动
│   ├── BinanceFuturesAdapter.php     # 现有合约实现，不改动
│   └── OkxAdapter.php                # 扩展现有占位骨架，实现现货全接口
└── Contracts/                          # 现有 DTO，不改动
    ├── ExchangeAdapter.php
    ├── ExchangeOrder.php
    ├── ExchangeKline.php
    ├── ExchangeTicker.php
    ├── ExchangeBalance.php
    └── ExchangePosition.php

config/
└── exchange.php                        # 补全 okx testnet 开关与模拟盘端点配置

tests/Domain/Exchange/
└── OkxAdapterTest.php                  # 离线断言（mock REST/WS 响应映射）

# POC 验证命令（参考 Binance 的 poc:verify-feed 范式）
app/Console/Commands/
└── PocVerifyOkxFeed.php               # 可选，POC 阶段公共行情验证脚本
```

**Structure Decision**: 单项目（Laravel monolith）。OKX 适配器在现有 `app/Domain/Exchange/Adapters/` 下扩展占位骨架 `OkxAdapter.php`，复用现有 `Contracts/` DTO，不新增领域目录。测试放 `tests/Domain/Exchange/`，与现有 Adapter 测试同级。config/exchange.php 补全 okx 配置项。与 Binance 适配器结构对称，ExchangeManager 路由已预留（`'okx' => new OkxAdapter(...)`）。

## Complexity Tracking

> Constitution Check 全部 PASS，无违反需 justify，此表为空。
