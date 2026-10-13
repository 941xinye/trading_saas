# ADR-0001: 双进程模型 — Laravel Octane + 独立 Swoole MarketFeedWorker

- **Status**: Accepted
- **Date**: 2026-10-13
- **Deciders**: 架构设计

## Context

项目需要同时承担两类负载：

1. **HTTP 请求**：REST API、Filament 后台、Reverb WebSocket 用户推送（低频短连接）
2. **行情订阅 + 策略调度**：交易所 WebSocket 长连接（高频）、K线聚合（bar close）、策略 onTick/onBar/onFill 回调（实时）

Octane（Swoole）采用 prefork 模型，每个 worker 内同时处理 HTTP 请求。如果在 worker 内发起 WebSocket 长连接，会阻塞该 worker 处理后续 HTTP 请求，导致 HTTP 响应延迟或超时。

## Decision

拆为两个独立进程，通过 Redis + Laravel Queue 通信：

```
进程 A：Laravel Octane (Swoole HTTP)
├── REST API (Sanctum)
├── Filament 后台
├── Queue Worker (异步下单/AI/通知)
└── Reverb WebSocket Server（用户推送）

进程 B：MarketFeedWorker (artisan market:feed)
├── 协程 1：交易所 WS 订阅（多租户去重）
├── 协程 2：K线聚合（bar close → event → Queue）
├── 协程 3：大户爆仓流订阅
└── 协程 4：策略调度器（onTick/onBar 派发到协程池）
```

跨进程通信：
- Redis：共享行情快照 / 策略状态 / 熔断状态机 / 分布式锁 / 令牌桶
- Laravel Queue：跨进程任务投递（下单、AI 分析、通知分发、bar close 处理）

## Consequences

**正面**：
- HTTP 请求与行情订阅互不阻塞
- 进程崩溃不互相影响（Octane crash 不丢行情订阅，MarketFeed crash 不影响 HTTP）
- 进程 B 可独立扩缩容（多台机器跑多个 MarketFeedWorker，Redis 订阅去重）
- Octane worker 保持纯粹的请求处理，不持有长连接状态

**负面**：
- 两进程需要分别部署和监控
- 跨进程状态同步需要 Redis（带来 Redis 可用性依赖）
- 开发调试需要同时启动两个进程（`php artisan octane:start` + `php artisan market:feed`）

## Alternatives Considered

### 单进程：Octane 内用 Swoole 协程做行情订阅
- 被否决：Swoole 协程在 worker 内运行时，HTTP 请求和协程共享同一 worker 资源。高频 tick 回调可能抢占 HTTP 响应时间。Octane 官方也不建议在 worker 内做长连接。

### Hyperf 代替 Octane
- 被否决：Hyperf 自建生态，用户已在 CRM/AI-API 项目积累 Laravel SaaS 约定（BelongsToTenant/Sanctum/Filament/TenantScope），迁移成本高。Octane 已足够覆盖 HTTP 加速需求。
