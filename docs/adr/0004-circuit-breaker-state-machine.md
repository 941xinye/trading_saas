# ADR-0004: 熔断状态机 — 策略级四维度保护

- **Status**: Accepted
- **Date**: 2026-10-13

## Context

交易策略在极端行情下可能连续亏损甚至爆仓。需要熔断机制在触发阈值时自动停止策略的开新仓动作，避免损失扩大。

纯 API 限流（Token Bucket）只解决交易所调用频率问题，不解决交易亏损问题。

## Decision

策略级 Circuit Breaker 状态机，四维度 Rule 编排：

### 状态机

```
        单笔/日内/回撤/连亏触发
   CLOSED ─────────────────────► OPEN
     ▲                              │
     │ 自动恢复窗口到期              │
     │ (HALF_OPEN 探测)              │
     └── HALF_OPEN ◄────────────────┘
            │ 探测单盈利 → CLOSED
            │ 探测单亏损 → OPEN（重置计时）
```

状态存储：Redis Hash `tenant:{tid}:breaker:{iid}`

### 四维度 Rule

| Rule | 触发条件 | 默认阈值 |
|---|---|---|
| 单笔最大亏损 | 单笔成交已实现亏损 ≥ 阈值 | 2% of allocated_capital |
| 日内最大亏损 | 当日累计已实现亏损 ≥ 阈值 | 5% |
| 最大回撤 | 历史峰值 → 当前净值回撤 ≥ 阈值 | 10% |
| 连续亏损次数 | 连续亏损订单数 ≥ 阈值 | 5 次 |

### 触发后动作

1. `RiskGuard.check` 在 `Strategy.shouldOpen` 与 `ExchangeAdapter.placeOrder` 之间拦截
2. 状态转 OPEN → 策略协程暂停（status=paused_by_breaker）
3. 写 `circuit_breaker_events` 审计记录
4. 分发通知（Telegram + 企微 + 邮件 + WS，带冷却去重）
5. 等待恢复窗口（cooldown_minutes，可配）→ 转 HALF_OPEN
6. HALF_OPEN 放行一笔探测单：盈利 → CLOSED；亏损 → OPEN 重置计时
7. 用户可后台手动解锁（强制 CLOSED）

### 关键约束

- 熔断只暂停"开新仓"，不平掉已有持仓（避免雪上加雪，已有持仓交给策略自身止盈止损）
- 仅业务亏损触发熔断，API 异常不触发（避免交易所故障导致策略停摆）
- 阈值可在 `risk_rules` 表按租户/实例自定义，覆盖 config 默认值

## Consequences

**正面**：
- 四维度覆盖不同风险场景（单笔大亏损、日内集中亏损、长期回撤、连续小亏）
- HALF_OPEN 探测机制避免过早恢复
- 状态存储在 Redis，进程崩溃不丢状态
- 不自动平仓避免"止损在最低点"

**负面**：
- HALF_OPEN 探测单是简化机制（只放行一笔），复杂场景可能需要更精细的 probe 逻辑
- 用户可能忘记手动解锁（可加 auto_recover=true 自动进入 HALF_OPEN）

## Alternatives Considered

### 全局熔断（非策略级）
- 被否决：不同策略风险独立，单策略熔断不应影响其他策略

### 自动平仓
- 被否决：在极端行情下自动平仓可能滑点巨大；且"亏损策略"在趋势反转时会错过机会。交给策略自身止盈止损更灵活
