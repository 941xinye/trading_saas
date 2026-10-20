# Quickstart: 合约网格策略 + 仓位级风控

> spec 005 验证指南。分两层：离线断言（无外部依赖）+ testnet 真实链路（需 Binance Futures testnet key）。

## 前置条件

- PHP 8.4+ / Composer
- Redis 7+（仓位风控单飞锁 + 去重）
- MySQL 8.4（position_risk_events 表）
- Binance Futures testnet API key（https://testnet.binancefuture.com，仅真实链路用）

## 1. 离线断言（无外部依赖）

```bash
# 强平价公式 + 保证金率 + 阈值判定（纯函数，断网可跑）
php artisan test tests/Unit/Risk/LiquidationPriceCalculatorTest

# 合约网格决策（开多/开空/平多/平空/加多/加空）+ state round-trip + 崩溃恢复
php artisan test tests/Unit/Strategy/FuturesGridStrategyTest
```

预期：全部 PASS，无 skipped。

## 2. 集成测试（需 Redis）

```bash
# 仓位风控三档动作 + 单飞锁 + 15min 去重
php artisan test --group=redis tests/Integration/Risk/PositionRiskMonitorTest

# 合约下单 reduceOnly/leverage 透传 + 落库
php artisan test tests/Integration/Strategy/FuturesOrderExecutorTest

# 熔断语义（OPEN 下平仓放行、开仓拦截、仓位风控不受熔断影响）
php artisan test --group=redis tests/Integration/Risk/FuturesBreakerSemanticsTest
```

预期：Redis 在线全绿；离线时 redis 组 skipped，其余 PASS。

## 3. 售卖门控验证

```bash
# 白名单结构性断言（任意套餐 strategies ⊆ implemented）
php artisan test tests/Feature/StrategyWhitelistIntegrityTest

# pro 可创建 futures_grid、free 被拒
php artisan test tests/Feature/FuturesGatewayTest
```

## 4. POC 离线全链路断言

```bash
# 一键运行：强平价 + 决策 + 风控 + 门控 + 计费，模拟双租户
php artisan poc:verify-futures
```

预期：`ALL CHECKS PASSED`，断言数 ≥ 25。

## 5. Testnet 真实链路（需 key）

### 5.1 配置 .env

```env
EXCHANGE_BINANCE_TESTNET=true
EXCHANGE_TESTNET_KEY=你的_futures_testnet_key
EXCHANGE_TESTNET_SECRET=你的_futures_testnet_secret
```

> 注意：Binance Futures testnet 与现货 testnet 是独立系统，API key 需单独申请。

### 5.2 装配合约网格实例

```bash
# 建一个 BTCUSDT 合约网格实例（10x 杠杆，±2% 10 格，逐仓）
php artisan poc:futures-testnet-setup --tenant=1 --symbol=BTCUSDT --leverage=10
```

预期输出：实例创建成功，status=running，杠杆已设置为 10x，保证金模式 ISOLATED。

### 5.3 启动行情调度

```bash
php artisan market:feed
```

观察日志：
- `getPosition` 初始返回 null（无持仓）
- 价格穿越网格线时触发开仓单（BUY/SELL）
- `position_snapshot` 更新含 side/size/entry_price/leverage/liquidation_price

### 5.4 验证全周期

```bash
# 查看实例状态与持仓快照
php artisan tinker
>>> App\Models\StrategyInstance::where('strategy_id', App\Models\Strategy::where('code','futures_grid')->value('id'))->first(['position_snapshot','status','realized_pnl']);
```

预期：position_snapshot JSON 含 position.side/size/entry_price/leverage/liquidation_price；崩溃重启后从 Redis 恢复一致。

### 5.5 仓位风控触发（需构造不利行情）

testnet 行情可能不足以触发风控，用命令注入测试仓位：

```bash
# 注入一个高杠杆模拟仓位并触发风控评估（仅测试环境）
php artisan poc:futures-risk-simulate --instance=1 --leverage=20 --side=long --entry=61000 --mark=56000
```

预期：`position_risk_events` 表产生 emergency_close 行，实例 status → `paused_by_position_risk`，通知渠道收到告警。

## 6. 崩溃恢复验证

```bash
# 启动实例后强杀
php artisan market:feed &
kill -9 %1

# 重启
php artisan market:feed
```

预期日志：
- `Pending order recovery finished` 含 recovered/cleared/blocked 计数
- 持仓方向/数量/杠杆从 position_snapshot 正确重放
- 无重复下单（clientOrderId 幂等）

## 7. 验收清单（对照 SC-001~007）

| SC | 验证方式 | 状态 |
|---|---|---|
| SC-001 全周期实跑 | §5.3-5.4 | ⬜ |
| SC-002 崩溃恢复 | §6 | ⬜ |
| SC-003 预警及时性 | §5.5 | ⬜ |
| SC-004 熔断语义 | `FuturesBreakerSemanticsTest` | ⬜ |
| SC-005 计费语义 | `FuturesOrderExecutorTest` | ⬜ |
| SC-006 门控回归 | `StrategyWhitelistIntegrityTest` | ⬜ |
| SC-007 精度回归 | `LiquidationPriceCalculatorTest` 边界用例 | ⬜ |

## 8. 已知边界

- 强平价为平台侧保守估算，以 `positionRisk.liquidationPrice` 为准
- 首期仅单向持仓 + 逐仓模式；双向/全仓不在范围
- 其余三个合约策略骨架（futures_dca / smart_martingale / trend_rolling）仍不在白名单
- `getPosition` 为阻塞 REST，30s 节流；极端行情下风控动作可能滞后一个 bar
