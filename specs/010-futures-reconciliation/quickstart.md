# Quickstart: 合约原生账实对账（A5）端到端验证

**Date**: 2026-10-22 | **Plan**: [plan.md](plan.md) | **Contracts**: [contracts.md](contracts.md)

本指南验证特性交付后 SC-001~SC-005 全部达成。分两层：自动化测试（必过）+ 本地手动场景（可选）。

## 前置

- PHP 8.4 + bcmath/sodium；MySQL 8.4 库 `crypto_saas`（或测试用 SQLite）
- Redis 运行中（unknown streak / dedup 键）：WSL 内 `redis-server --daemonize yes --port 6379`
- 依赖已安装：`composer install`

## 一、自动化验证（主要判据）

```bash
# 新增 futures 对账集成测试（Redis 组）
php artisan test --group=redis --filter=FuturesReconciliationTest

# 现货回归 + 护栏反转（SC-004）
php artisan test --group=redis --filter=ReconciliationServiceTest

# 适配器契约（getPositions 默认实现 + BinanceFuturesAdapter 覆写）
php artisan test --filter=ExchangeAdapter
```

**期望**：全绿。覆盖矩阵（对应 contracts.md C3 与 spec 验收场景）：

| 场景 | 注入 | 期望 |
|------|------|------|
| ok（long） | getPositions=[long 0.5]，orders net=+0.5 | status=ok，不暂停 |
| ok（short） | getPositions=[short 0.5]，orders net=−0.5 | status=ok（SC-005） |
| drift | 交易所 +0.6 vs 账本 +0.5（超容差） | status=drift，不暂停 |
| critical（强平） | getPositions=[] vs 账本 +0.5 | status=critical，实例 paused_by_reconcile，risk_notifications 落 queued |
| critical（方向反） | 交易所 −0.5 vs 账本 +0.5 | diff=−1.0 → critical（SC-005） |
| hedge 合成 | legs=[long 0.5, short 0.2] vs 账本 +0.3 | status=ok（FR-008） |
| unknown | getPositions 抛异常 | status=unknown，不暂停；连续 3 次后告警 |
| ticker 降级 | ticker 抛异常 + 持仓正常 | 仅按数量容差分级，不阻断（FR-007） |
| breaker 正交 | 实例 paused_by_breaker + critical 场景 | 出报告但状态不被覆盖（FR-005） |
| 扫描范围 | spot+futures 实例并存 | reconcileableInstanceIds 含两者；stopped/error 排除（FR-001） |
| 幂等 | 同 period 跑两次 | 报告一行，告警不重复（FR-009） |

## 二、本地手动场景（可选，testnet）

> 国内开发机 Binance 主网不稳定；用 testnet 凭据。无 testnet 条件时跳过，以自动化层为准。

1. 造一个 market=futures 的 exchange_account（testnet 加密凭据入库）+ running futures_grid 实例，DB 手工插入若干 `filled` strategy_orders 使 net=+0.02。
2. testnet 实际持有 0.02 多仓 → `php artisan reconcile:run --instance=<id>` → 报告 ok。
3. testnet 手动平掉一半 → 再跑 → diff 超容差且为负方向 → critical，实例暂停，日志告警。
4. 掐断网络/改错凭据 → 连跑 3 次 → 第 3 次起收到 unknown_streak 告警；恢复后再跑 → streak 清零。
5. 恢复运行须人工把实例状态改回 running（铁律：系统绝不自动恢复）。

## 三、回归确认清单

- [ ] `ReconciliationServiceTest` 既有用例全绿（现货口径零变化；diff_json 仅多 `market` 字段，若全等断言红则更新预期属测试维护）
- [ ] spec 005 仓位风控相关测试全绿（`getPosition` 语义未动）
- [ ] `reconcile:run` 在现货+futures 混合实例下一次调度全部出报告（SC-001）
- [ ] README 技术债表 A5 行状态更新（implement 完成后）
