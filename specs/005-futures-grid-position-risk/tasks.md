# Tasks: 合约网格策略 + 仓位级风控

> spec 005 任务拆解。T001–T050，按 6 个工作流组织。每个任务含验收标准。

## WS1: 数据层 + 配置

### T001 — strategy_instances status 枚举扩展
- **文件**: `database/migrations/*_add_paused_by_position_risk_to_strategy_instances.php`
- **做**: status 枚举追加 `paused_by_position_risk`
- **验收**: `php artisan migrate` 成功；`SHOW COLUMNS FROM strategy_instances LIKE 'status'` 含新值

### T002 — strategy_orders 新增 leverage / reduce_only
- **文件**: `database/migrations/*_add_leverage_and_reduce_only_to_strategy_orders.php`
- **做**: 新增 `leverage INT NULL`、`reduce_only TINYINT(1) DEFAULT 0`；`type` 枚举扩展为 `market|limit`
- **验收**: migrate 成功；两列存在且默认值正确

### T003 — position_risk_events 表
- **文件**: `database/migrations/*_create_position_risk_events_table.php`
- **做**: 按 data-model.md §3 建表，含 tenant_id 外键级联 + 复合索引
- **验收**: migrate 成功；表结构与 data-model 一致

### T004 — config/risk.php position 块
- **文件**: `config/risk.php`
- **做**: 新增 `position` 配置块（warning/action/emergency 阈值、reduce_ratio、lock_ttl、dedup_cooldown、default_mmr）
- **验收**: `config('risk.position.warning_margin_ratio')` 可读且为 0.50

### T005 — config/exchange.php futures 块
- **文件**: `config/exchange.php`
- **做**: binance 下新增 `futures` 子块（rest_url/ws_url/testnet_url/testnet_ws_url）
- **验收**: `config('exchange.exchanges.binance.futures.rest_url')` 可读

### T006 — .env.example 新增合约相关变量
- **文件**: `.env.example`
- **做**: 新增 `EXCHANGE_BINANCE_FUTURES_URL` / `_WS_URL` / `_TESTNET_URL` / `_TESTNET_WS_URL`、`RISK_POSITION_*` 系列
- **验收**: 变量与 config 中 env() 调用一一对应

## WS2: BinanceFuturesAdapter 交易接口补全

### T007 — subscribeKlineStream
- **文件**: `app/Domain/Exchange/Adapters/BinanceFuturesAdapter.php`
- **做**: 复用现货 WS 循环范式，URL 切到 `futures_ws_url` / testnet，kline 频道 confirm 过滤
- **验收**: `BinanceFuturesAdapterTest::subscribeKlineStream` 模拟帧验证 onBar 回调

### T008 — getTicker
- **做**: `GET /fapi/v1/ticker/price`，映射 `ExchangeTicker`
- **验收**: 测试用 HTTP fake 验证请求路径与 DTO 映射

### T009 — getKline
- **做**: `GET /fapi/v1/klines`，响应结构与现货一致，升序重排
- **验收**: 测试验证返回 ExchangeKline[] 升序

### T010 — getBalance
- **做**: `GET /fapi/v2/account`，映射 totalWalletBalance / availableBalance 到 `ExchangeBalance`
- **验收**: 测试验证 DTO 字段映射

### T011 — getPosition
- **做**: `GET /fapi/v2/positionRisk`，映射 `ExchangePosition`（entryPrice/markPrice/liquidationPrice/leverage/marginType）；无持仓返回 null
- **验收**: 测试验证有持仓/无持仓两种响应

### T012 — placeOrder（合约）
- **做**: `POST /fapi/v1/order`，支持 `leverage` / `reduceOnly` / `newClientOrderId`；映射 `ExchangeOrder`
- **验收**: 测试验证参数透传（leverage/reduceOnly）与响应映射

### T013 — cancelOrder
- **做**: `DELETE /fapi/v1/order`，支持 orderId / origClientOrderId
- **验收**: 测试验证成功与 -2011 未知单场景

### T014 — getOrder / getOrderByClientOrderId
- **做**: `GET /fapi/v1/order`（orderId 或 origClientOrderId）
- **验收**: 测试验证两种查询方式 + 查无返回 null

### T015 — symbolFilters
- **做**: `GET /fapi/v1/exchangeInfo`，提取 LOT_SIZE.stepSize / PRICE_FILTER.tickSize / MIN_NOTIONAL.minNotional，Redis 缓存 24h
- **验收**: 测试验证缓存命中与 miss 两种路径

### T016 — setLeverage / setMarginType / getLeverageBracket（私有）
- **做**: 三个私有方法；setMarginType 固定 ISOLATED；getLeverageBracket 缓存 MMR 分档
- **验收**: 通过 placeOrder 间接验证杠杆设置；getLeverageBracket 单测

### T017 — 错误码翻译扩展
- **做**: translateErrorCode 覆盖 -2026/-2027/-4028/-4067/-4046
- **验收**: `BinanceFuturesAdapterTest::errorCodeTranslation` 5 个用例

### T018 — BinanceFuturesAdapter 全量回归
- **做**: 运行 `tests/Domain/Exchange` 全量，确保现货/OKX/Bybit/Bitget 零回归
- **验收**: 既有 147 passed 零变化 + 新增 futures 测试通过

## WS3: 强平价计算器 + 仓位风控监控器

### T019 — LiquidationPriceCalculator 接口
- **文件**: `app/Domain/Risk/Contracts/LiquidationPriceCalculator.php`
- **做**: 定义 estimate() / marginRatio() 方法签名
- **验收**: 接口文件存在，类型签名与 contracts.md 一致

### T020 — IsolatedLiquidationPriceCalculator 实现
- **文件**: `app/Domain/Risk/Breaker/IsolatedLiquidationPriceCalculator.php`
- **做**: 逐仓模式强平价公式（多/空）+ 保证金率计算，bcmath
- **验收**: `LiquidationPriceCalculatorTest` 覆盖 1x/5x/10x/20x 杠杆、多/空、各 MMR、边界（leverage=1）

### T021 — PositionRiskDecision DTO
- **文件**: `app/Domain/Risk/Contracts/PositionRiskDecision.php`
- **做**: readonly class，字段 actionType/reason/marginRatio/orderQty/reduceOnly
- **验收**: 类可实例化，字段只读

### T022 — PositionRiskEventRepository
- **文件**: `app/Domain/Risk/Position/PositionRiskEventRepository.php`
- **做**: position_risk_events 表 CRUD，含 created 审计行
- **验收**: 测试验证写入与查询

### T023 — PositionRiskMonitor 接口
- **文件**: `app/Domain/Risk/Contracts/PositionRiskMonitor.php`
- **做**: 定义 evaluate() 方法签名
- **验收**: 接口文件存在

### T024 — PositionRiskMonitor 实现
- **文件**: `app/Domain/Risk/Position/PositionRiskMonitor.php`
- **做**: 三档阈值判定 + 单飞锁 + 15min 去重 + 写审计；返回 PositionRiskDecision
- **验收**: `PositionRiskMonitorTest`(@redis) 覆盖 warning/reduce/emergency_close/skipped 四种决策

### T025 — PositionRiskAlert 事件
- **文件**: `app/Events/PositionRiskAlert.php`
- **做**: readonly event，字段 tenantId/instanceId/symbol/actionType/marginRatio/markPrice/liquidationPrice
- **验收**: 事件可 dispatch

## WS4: FuturesGridStrategy 实现

### T026 — FuturesGridStrategy 骨架
- **文件**: `app/Domain/Strategy/Futures/FuturesGridStrategy.php`
- **做**: 继承 AbstractStrategy，实现接口方法骨架（抛未实现），注册到 StrategyRegistry
- **验收**: `StrategyRegistry::make('futures_grid')` 返回实例

### T027 — onBar 决策逻辑
- **做**: 6 种开平仓决策（开多/开空/平多/平空/加多/加空），生成 pendingSignal 含 reduceOnly
- **验收**: `FuturesGridStrategyTest` 6 个决策用例 + 边界（区间外无信号）

### T028 — onFill 入账
- **做**: 根据 order.side + reduceOnly 更新 position 快照（side/size/entry_price/leverage/liquidation_price）
- **验收**: 测试验证开仓/平仓/加仓/减仓后 position 快照正确

### T029 — shouldOpen / shouldClose
- **做**: 消费 pendingSignal，开仓走 shouldOpen，平仓走 shouldClose
- **验收**: 测试验证信号消费与 reduceOnly 透传

### T030 — state() / hydrate()（schema_version=2）
- **做**: positionSnapshot 序列化/反序列化，含 position 子对象
- **验收**: `FuturesGridStateHydrationTest` 往返一致 + schema_version 守卫

### T031 — 崩溃恢复 getPosition 核对
- **做**: hydrate 后调 adapter.getPosition 核对，不一致记 warning（不自动改账）
- **验收**: 测试模拟交易所持仓与快照不一致场景

### T032 — RecoverablePendingOrders 实现
- **做**: pendingClientOrderIds() / applyRecoveredOrder()，复用现货范式
- **验收**: 测试 pending 单 filled/canceled/not_found 三种恢复路径

## WS5: StrategyRunner / OrderExecutor 接线

### T033 — OrderExecutor 透传 reduceOnly/leverage
- **文件**: `app/Domain/Strategy/OrderExecutor.php`
- **做**: place() 从 opts 读取 reduce_only/leverage 透传 adapter + 落库 strategy_orders
- **验收**: `FuturesOrderExecutorTest` 验证 reduceOnly/leverage 透传与落库

### T034 — StrategyRunner 接入 PositionRiskMonitor
- **文件**: `app/Domain/Strategy/StrategyRunner.php`
- **做**: onBar 回调末尾调用 evaluate（30s 节流 + 仅持仓时调 getPosition）；减仓/全平决策走 executeSignal(reduceOnly=true)
- **验收**: `FuturesRiskEndToEndTest` 验证端到端触发

### T035 — paused_by_position_risk 状态处理
- **做**: 保活循环检测该状态时 shouldOpen 返回 null；shouldClose 仍可触发
- **验收**: 测试验证紧急状态下开仓被拒、平仓放行

### T036 — 熔断语义合约场景测试
- **文件**: `tests/Integration/Risk/FuturesBreakerSemanticsTest.php`
- **做**: OPEN 状态下平仓放行、开仓拦截；仓位紧急状态独立于账户熔断
- **验收**: 4 个断言 PASS

### T037 — 合约下单计费接线测试
- **文件**: `tests/Integration/Strategy/FuturesOrderExecutorTest.php`
- **做**: 配额耗尽拦截 + 受理结算 + 拒绝冲正 + 交易所零调用
- **验收**: 复用现货计费测试范式，4 个断言 PASS

## WS6: 售卖门控回归 + 事件通知 + 冒烟

### T038 — NotifyPositionRiskAlert Listener
- **文件**: `app/Listeners/NotifyPositionRiskAlert.php`
- **做**: ShouldQueue，复用 RiskNotificationJob 渠道分发；15min 去重（sha1(instanceId|actionType)）
- **验收**: 测试验证通知落库 + 去重

### T039 — 售卖门控回归
- **文件**: `config/strategy.php` + `config/plans.php`
- **做**: implemented 加入 futures_grid；pro/enterprise strategies 加入 futures_grid；free 不含
- **验收**: `PlanStrategyGateTest` 扩展验证

### T040 — 白名单结构性断言
- **文件**: `tests/Feature/StrategyWhitelistIntegrityTest.php`
- **做**: 断言任意套餐 strategies ⊆ config('strategy.implemented')
- **验收**: 测试 PASS（防再次超卖）

### T041 — FuturesGatewayTest
- **文件**: `tests/Feature/FuturesGatewayTest.php`
- **做**: pro 租户创建 futures_grid 成功；free 被拒
- **验收**: 2 个断言 PASS

### T042 — poc:verify-futures 命令
- **文件**: `app/Console/Commands/VerifyFutures.php`
- **做**: 离线断言：强平价公式 + 决策矩阵 + 风控三档 + 门控 + 计费，≥25 项
- **验收**: 命令输出 `ALL CHECKS PASSED`

### T043 — poc:futures-testnet-setup 命令
- **文件**: `app/Console/Commands/FuturesTestnetSetup.php`
- **做**: 建合约网格实例 + 设置杠杆 + 设置逐仓 + 输出实例 ID
- **验收**: testnet 环境下实例创建成功

### T044 — poc:futures-risk-simulate 命令
- **文件**: `app/Console/Commands/FuturesRiskSimulate.php`
- **做**: 注入模拟仓位触发风控评估，验证三档动作
- **验收**: position_risk_events 产生对应行

### T045 — 全量测试回归
- **做**: `php artisan test` 全绿（default/redis/mysql 组）
- **验收**: 326+ 既有测试零回归 + 新增测试全绿

### T046 — README / docs 同步
- **文件**: `README.md` + `docs/pitfalls.md`
- **做**: 技术债 A4/B1/B4 销账；新增合约踩坑记录
- **验收**: README 技术债表中 A4/B1/B4 标记已完成

### T047 — testnet 真实链路验收（smoke）
- **做**: 按 quickstart §5 跑全周期，回填验收清单 SC-001~007
- **验收**: 7 项 SC 全部 PASS（待海外节点 + key）

### T048 — 文档计数核对
- **做**: 确认 spec/plan/tasks/research/data-model/contracts/quickstart 7 文件齐全
- **验收**: 文件存在且内容完整

## 任务统计

| WS | 任务数 | 依赖 |
|---|---|---|
| WS1 数据层+配置 | T001–T006 (6) | 无 |
| WS2 Adapter 补全 | T007–T018 (12) | WS1 |
| WS3 风控监控器 | T019–T025 (7) | WS1 |
| WS4 策略实现 | T026–T032 (7) | WS1, WS2 |
| WS5 接线 | T033–T037 (5) | WS2, WS3, WS4 |
| WS6 门控+冒烟 | T038–T048 (11) | WS1–WS5 |
| **合计** | **48** | — |
