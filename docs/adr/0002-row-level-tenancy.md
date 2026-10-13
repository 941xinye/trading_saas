# ADR-0002: 多租户隔离 — 行级 tenant_id 隔离

- **Status**: Accepted
- **Date**: 2026-10-13

## Context

SaaS 平台需要每个用户（租户）独立隔离数据。两种主流方案：

1. **Schema 级隔离**：每个租户一个独立数据库 schema（或库）
2. **行级隔离**：单库，所有业务表带 tenant_id 字段，查询自动加 WHERE tenant_id=?

## Decision

采用行级隔离，配合 BelongsToTenant Trait + TenantScope + TenantContext：

- 所有业务表加 `tenant_id` 字段 + 复合索引 `(tenant_id, ...)`
- 所有业务 Model 挂 `BelongsToTenant` trait → 自动挂载 `TenantScope`（全局 WHERE tenant_id=?）
- `TenantContext` 单例持有当前 tenant_id，供 TenantScope 自动注入
- 平台级表（tenants/exchanges/strategies/kline_bars/exchange_health_checks）不加 tenant_id，无 trait

## Consequences

**正面**：
- 开发简单：CRUD 不需要手动加 tenant 过滤，Trait 自动生效
- 跨租户聚合走 `TenantContext::runWithoutTenant()`（仅限平台超管后台）
- 备份/迁移/统计统一在一个库操作
- 新增租户零开销（不需要新建 schema）
- 沿用用户在 CRM/AI-API 项目已验证的约定

**负面**：
- 必须严格防止 `withoutTenant()` 被滥用（已在 TenantScope::extend 标注限制）
- 复合索引 `(tenant_id, ...)` 是必须，否则全表扫描性能差
- 跨租户数据迁移需要写脚本（但实际极少见）

## Hard Rules

- 服务层禁止 `static` 方法内缓存 tenant_id（Octane 长驻下脏数据）
- 禁止业务接口调用 `withoutTenant()`（仅平台超管后台可通过 `runWithoutTenant()` 一次性绕过）
- `TenantContext` 基于 Laravel Context API（Octane 在 requestReceived/JobReceived 事件中自动重置）

## Alternatives Considered

### Schema 级隔离（一租户一库）
- 被否决：数百租户时迁移/备份/计费聚合成本爆炸；连接池压力大；ORM 行为不一致（跨 schema JOIN）

### 应用层过滤（手动加 tenant_id）
- 被否决：容易遗漏（漏加 where），代码审查成本高，新人容易出错
