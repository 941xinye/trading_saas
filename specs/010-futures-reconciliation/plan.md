# Implementation Plan: 合约原生账实对账（销技术债 A5）

**Branch**: `010-futures-reconciliation` | **Date**: 2026-10-22 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/010-futures-reconciliation/spec.md`

## Summary

为 futures 策略实例补齐原生账实对账：`reconcile:run` 恢复扫描全部市场实例，`ReconciliationService` 按 `exchange_accounts.market` 分流——现货路径零改动，合约新路径以「适配器多腿签名持仓合成净量」比对「strategy_orders 成交签名净额（buy=+ / sell=−）」，单维度复用 `classifyDimension` 四级分级、critical 暂停+告警、unknown 连续计数、周期桶幂等落报告。为此在 `ExchangeAdapter` 契约新增 `getPositions(symbol): ExchangePosition[]`（多腿），默认实现下沉 `AbstractExchangeAdapter`（包装既有 `getPosition` 单腿结果），`BinanceFuturesAdapter` 覆写返回全部非零腿；既有 `getPosition` 语义不动，spec 005 仓位风控调用方零影响。交付后删除 b1edb62 现货护栏（`where('ea.market','spot')`）。

## Technical Context

**Language/Version**: PHP 8.4（bcmath 字符串金额，sodium/AES-256-GCM）

**Primary Dependencies**: Laravel 12 + Swoole 协程、`ExchangeManager`/适配器体系（spec 001/002/003/005）、P4 通知链（`risk_notifications` + `RiskNotificationJob` + `DedupCooldown`）、既有 `ReconciliationService`（P5.5）

**Storage**: MySQL 8.4（`strategy_orders` / `strategy_instances` / `reconciliation_reports` / `balance_snapshots`，无新表无迁移）；Redis phpredis（unknown streak / dedup，复用既有键）

**Testing**: PHPUnit 12（`#[DataProvider]` 属性），SQLite + `RefreshDatabase`；Redis 依赖用 `#[Group('redis')]` + `ChecksRedisAvailability`；tearDown 外部 IO 清理先于 `parent::tearDown()`

**Target Platform**: `reconcile:run` 独立普通 PHP 进程（调度每 5 分钟），绝不进 `market:feed` 协程（REST 阻塞 curl，坑 #1）

**Project Type**: web-service（Laravel 后端领域服务扩展）

**Performance Goals**: 单实例对账 ≤ 2 次签名 REST 调用（positionRisk + ticker）；全量实例 5 分钟周期内完成（现现货同量级，实例数为几十级）

**Constraints**: 铁律——只报告+暂停（`paused_by_reconcile`），绝不自动改账/补单/平仓；对账暂停与熔断暂停正交（`paused_by_breaker` 不被覆盖）；现货行为零回归（FR-010）

**Scale/Scope**: 改动 1 个领域服务 + 1 个适配器契约/基类 + 1 个 futures 适配器 + 配置/测试；首期仅 Binance U 本位永续有 getPosition 实现，其余合约适配器上线即自动获得能力

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| 原则 | 判定 | 说明 |
|------|------|------|
| I. SDD 优先 | PASS | 本特性走 specify→clarify→plan→tasks 全流程 |
| II. 金融精度/UTC | PASS | 签名合成/差值/等值换算全 bcadd/bcsub/bcmul/bccomp；period_key 与 checked_at 均 UTC |
| III. 协程隔离 | PASS | 对账在 reconcile:run 普通进程执行，不触碰 market:feed 协程与 WS 重连逻辑 |
| IV. 崩溃恢复幂等 | PASS | 复用 upsertReport（tenant+instance+period_key 幂等覆盖）；无新增恢复逻辑 |
| V. 计费/风控边界 | PASS | 不涉计费；critical 仅 paused_by_reconcile 且不覆盖 paused_by_breaker；不自动下单/平仓 |
| VI. 测试隔离 | PASS | unknown streak / dedup 键沿用既有按逻辑名精确清理模式；新增测试走 Domain 公开方法注入状态 |
| VII. PSR-4/基类对齐 | PASS | 契约新方法经 Abstract 基类默认实现下发，不破坏既有 5 个适配器；无新文件多类 |

**Gate 结论**：无违规，无需 Complexity Tracking。

## Project Structure

### Documentation (this feature)

```text
specs/010-futures-reconciliation/
├── plan.md              # 本文件
├── research.md          # Phase 0：关键决策与备选
├── data-model.md        # Phase 1：报告 diff_json 扩展与数据流
├── contracts.md         # Phase 1：适配器契约 + 命令行为契约（单文件，同 005 惯例）
├── quickstart.md        # Phase 1：端到端验证指南
├── checklists/
│   └── requirements.md  # specify 阶段产物（已全绿）
└── tasks.md             # /speckit-tasks 产出（本命令不生成）
```

### Source Code (repository root)

```text
app/
├── Domain/
│   ├── Exchange/
│   │   ├── Contracts/
│   │   │   ├── ExchangeAdapter.php        # +getPositions(string): ExchangePosition[]
│   │   │   └── ExchangePosition.php       # 不动（side/size 语义复用）
│   │   └── Adapters/
│   │       ├── AbstractExchangeAdapter.php # +getPositions 默认实现（包 getPosition）
│   │       └── BinanceFuturesAdapter.php   # 覆写 getPositions：positionRisk 全部非零腿
│   └── Reconciliation/
│       └── ReconciliationService.php       # market 分流 + futures 私有路径 + 护栏移除
├── Console/Commands/
│   └── ReconcileRunCommand.php             # 不动（扫描逻辑在 Service 内）
config/
│   └── reconcile.php                       # 不动（容差/阈值/告警配置复用）
tests/
├── Unit/Reconciliation/                    # 签名合成纯函数测试（如抽取）
└── Integration/
    ├── Reconciliation/
    │   └── ReconciliationServiceTest.php   # 护栏测试反转 + 现货回归（既有）
    └── Reconciliation/
        └── FuturesReconciliationTest.php   # 新增：ok/drift/critical/unknown/hedge/short
```

**Structure Decision**: 单 Laravel 项目既有分层；futures 对账为 `ReconciliationService` 内私有分支而非新 Service 类——比对核心（分级/报告/暂停/告警/unknown）100% 复用，仅「取数口径」分叉，拆类反而制造重复。

## 设计要点（Phase 1 摘要）

1. **market 分流点**：`runForInstance` 在装配适配器后读取 `exchange_accounts.market`；`futures` → `reconcileFuturesPosition()` 私有方法，`spot` → 既有路径原样。buildAdapter 已查询 account，顺带返回 market 避免二次查询。
2. **futures 取数**：`getPositions(symbol)` 多腿 → `signedNet = Σ(side==='short' ? -size : +size)`（bcadd 12 位）；账本侧复用 `readOrderTotals()['net_qty']`；`diff = exchangeNet − ordersNet` 交 `classifyDimension`（方向语义天然兼容：diff<0=交易所少于账本=critical，diff>0=交易所多仓=drift）。
3. **契约扩展**：`ExchangeAdapter::getPositions(string $symbol): ExchangePosition[]`；`AbstractExchangeAdapter` 默认实现为 `getPosition()` 非 null 则包一元素数组否则空数组——5 个既有适配器零改动获得兼容；`BinanceFuturesAdapter` 覆写返回 positionRisk 全部非零腿（hedge mode）。
4. **报告**：`reconciliation_reports` 无迁移；futures 的 `diff_json` 新增 `market='futures'`、`position.legs[]`、`position.exchange_signed_qty`、`position.orders_net_qty` 等明细；现货 diff 结构不变（仅补 `market='spot'` 标识，implement 时核对既有测试断言方式）。
5. **护栏移除**：`reconcileableInstanceIds()` 删除 `->where('ea.market','spot')`；既有护栏测试反转为「futures 实例被纳入、stopped/error/starting 仍排除」。

## Phase 0/1 产物

- [research.md](research.md)：5 项关键决策（分流点、契约扩展形态、单维度口径、方向语义复用、护栏移除方式）
- [data-model.md](data-model.md)：diff_json futures 形态 + 数据流图
- [contracts.md](contracts.md)：getPositions 契约、reconcile:run 行为契约、diff_json schema
- [quickstart.md](quickstart.md)：本地端到端验证（SQLite 测试 + 手动注入场景）
