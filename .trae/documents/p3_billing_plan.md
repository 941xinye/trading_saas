# P3 多租户计费 POC 实施计划

## Repository Research（研究结论）

### 已存在的骨架（表/模型/配置齐备，计费域零实现）

- **表**（均已 migrate）：
  - `tenants`：`plan_code` 默认 free、`status`(active/suspended/canceled)、`trial_ends_at`
  - `subscriptions`：`tenant_id/plan_code/status`(trialing/active/past_due/canceled)/`started_at/ends_at`，索引 (tenant_id,status)
  - `meter_events`：`tenant_id/event_type`(ai_call|order_placed|signal_sent)/`quantity/frozen_amount/settled_amount/status`(frozen|settled|reversed)/`ref_id/occurred_at`，索引 (tenant_id,status,occurred_at)、ref_id
- **模型**：`Tenant`（平台级，不带 scope）、`Subscription` / `MeterEvent`（已挂 `BelongsToTenant`）
- **配置**：`config/plans.php` 完整定义 free/pro/enterprise：
  - 资源数：max_exchange_accounts / max_strategy_instances / max_signal_subscriptions
  - 日配额：ai_calls_per_day（10/500/10000）、orders_per_day（100/2000/50000）
  - 特性：channels、strategies
  - `config/tenancy.php`：trial_days=14、状态机、disable_cross_tenant_aggregation=true
- **多租户设施**：`TenantContext`（Laravel Context API，请求/Job 级隔离）+ `BelongsToTenant`（creating 自动填 tenant_id，显式传值优先）+ `TenantScope` + `BootstrapTenancy` 中间件
- **缺口**：`app/Domain/Billing/` 目录不存在；全仓 grep 无 PlanService/TwoPhaseMeter/配额检查任何引用

### 配额插入点（P1/P2 已实跑代码）

| 配额 | 插入点 | 运行进程 |
|---|---|---|
| ai_calls_per_day | `AbstractAIPrompter::analyze()` HTTP 调用前后（freeze/settle/reverse） | queue worker（普通 PHP，阻塞安全） |
| orders_per_day | `OrderExecutor::place()` 入口冻结，交易所接受后 settle、前置失败 reverse | market:feed 协程（Redis 阻塞调用，P1 已摘 hook，串行可接受） |
| max_signal_subscriptions + channels | `PocSignalSetupCommand` upsert 前 | artisan |
| max_exchange_accounts / max_strategy_instances + strategies | `PocTestnetSetupCommand` 新建前（已存在则 upsert 不拦） | artisan |
| 订阅/租户状态 | 上述所有入口统一过 `QuotaGuard::assertEntitled()` | 全部 |

### 关键架构约束

1. **并发竞争是 P3 头号风险**：多个 ai worker / 多协程可能同时打配额。用 **Redis Lua 脚本原子 check+INCR**（key `tenant:{tid}:meter:{event_type}:{UTC Ymd}`，超限返回拒绝；EXPIRE 2 天），绝不能「先 GET 比较再 INCR」。meter_events 行做持久审计，Redis 计数为热闸门；提供从 meter_events 重建计数的 reconcile（Redis 丢失可自愈）。
2. **两阶段语义**：freeze（原子占额+frozen 行）→ 业务动作 → settle（占额保留，行 settled）；动作未真正发生则 reverse（DECR/Lua 还额 + 行 reversed）。ref_id 幂等（AI=subscription_id+bar_open_time；订单=client_order_id），Job 重试不双扣。
3. **不在 market:feed 协程内引入 TenantContext**：Swoole 下 Laravel Context 协程隔离未经验证；P1/P2 该进程全部用 DB::table + 显式 tenant_id（隔离 by construction）。TenantContext 只在边界明确的 **queue Job（AiAnalyzeJob）** 补 initialize/clear。
4. **不做支付网关**：订阅状态机由命令/服务显式驱动（trialing→active→past_due→canceled），续费收款留正式期；POC 用 `poc:verify-billing` 模拟到期/逾期/恢复。
5. **DEK 轮换**（路线图点名风险）：新增 `TenantKeyResolver::rotateDek()`：Redis 分布式锁 + DB 事务内「生成新 DEK(KEK 加密) → 逐行重加密 exchange_accounts → 更新 tenants.exchange_dek → 抽验新旧解密」。POC 期约定轮换前暂停该租户实例/排空 orders 队列（长驻协程内存里持有解密凭据，重启后拿新 DEK）；正式期再做版本化 DEK 热轮换。
6. 配额冻结失败**不抛断链路**：AI 超配额 → 记日志走 RuleEngineFallback（rationale 标注 `ai_quota_exhausted`，source 仍 rule_fallback）；订单超配额 → OrderExecutor 返回 `failedOrder('daily_order_quota_exceeded')`（沿用 P1「不打断调度循环」语义）。

## 已确认的 POC 范围（待用户批准）

- 计划：free/pro/enterprise 三档读 `config/plans.php`，**tenant.plan_code 为生效计划**（subscriptions.plan_code 仅记录计费事实）
- 计量：Redis 原子闸门 + meter_events 审计 + reconcile；event_type 本期接 `ai_call`、`order_placed` 两类（signal_sent 预留不接）
- 验证形态延续 P1/P2：`poc:verify-billing` 离线断言（含多进程并发打配额）→ 接 `market:feed` 双租户实跑观察
- 双租户实跑走**信号链路**（同一 WS 下两个租户各自收 AiAnalyzeJob、计量严格分租户、free 配额耗尽自动兜底）；策略侧隔离用离线断言验证（避免第二实例用同一 testnet key 真下单造成交易所侧状态互扰；第二网格实例实跑列为可选 stretch）
- 不做：支付、Filament 计费页面、Invoice、版本化 DEK 热轮换

## Files and Modules

### 新增

- `app/Domain/Billing/Plans/PlanService.php` — effectivePlan(tenant)、limit(tenant,key)、feature(tenant,key)、assertFeature（strategies/channels 白名单）；未知 plan_code 兜底抛异常（不静默降级 free，防提权）
- `app/Domain/Billing/Metering/TwoPhaseMeter.php` — `freeze()/settle()/reverse()/reconcile()/usedToday()`；Lua 原子占额（返回 [allowed, current]）；key 与时区全部 UTC；meter_events 落库走显式 tenant_id（不依赖 context）
- `app/Domain/Billing/Metering/QuotaExceededException.php` — 携带 tenant/eventType/limit/used
- `app/Domain/Billing/QuotaGuard.php` — 统一入口：`assertEntitled(tenantId)`（status=active 且最新 subscription ∈ trialing/active 且未过期）、`assertResourceCount(tenant,type,count)`、`assertCanCreateSignalSubscription/...` 薄封装
- `app/Domain/Billing/SubscriptionService.php` — `provision(tenant,plan,trialDays)`、`cancel()`、`refreshStatus()`（试用到期无 active → past_due；past_due 宽限 N 天 → tenant suspended；重新 provision → active 恢复）、`latestFor()`
- `app/Console/Commands/PocVerifyBillingCommand.php` — 离线断言（见 Validation）
- `app/Console/Commands/PocBillingDemoCommand.php` — 建第二个租户 `poc-billing`（free）+ 其 BTCUSDT/5m/log 信号订阅，供双租户主跑观察；--cleanup 反向清理

### 修改

- `app/Domain/Signal/Prompters/AbstractAIPrompter.php` — analyze 内：assertConfigured 后 freeze(`ai_call`, ref=`{subId}:{barOpenTime}`)；HTTP 拿到响应（含 4xx/5xx，调用已发生）→ settle；抛在请求发出前（配置/限流）→ reverse；AI 不可用兜底语义不变。**配额耗尽 QuotaExceededException → 由 pipeline 捕获走 fallback**（在 SignalPipeline 内把它等同 AiUnavailableException，日志标配额）
- `app/Domain/Signal/SignalPipeline.php` — 调 prompter 前经 `QuotaGuard::assertEntitled()`（suspended/past_due → 直接 fallback 或跳过，POC 选 fallback+日志）；捕获 QuotaExceededException 走 fallback
- `app/Domain/Strategy/OrderExecutor.php` — `place()` 开头：assertEntitled + freeze(`order_placed`, ref=clientOrderId)；配额/权益拒绝 → failedOrder 并 reverse（或根本不插 frozen：Lua 拒绝时不落行）；交易所返回 new/partially_filled/filled → settle；返回 failed/异常 → reverse
- `app/Jobs/AiAnalyzeJob.php` — handle 入口 `TenantContext::initialize($tenantId)`，finally clear（让 MeterEvent Eloquent 落库/后续作用域规范）
- `app/Console/Commands/PocSignalSetupCommand.php` — upsert 前：assertEntitled + max_signal_subscriptions 计数 + channels∈plan.channels（POC 的 log 渠道：plan 白名单只含 websocket —— **将 log 视为系统内部渠道始终放行**，telegram 才校验白名单）
- `app/Domain/Tenancy/TenantKeyResolver.php` — 增加 `rotateDek(int $tenantId): string`（锁+事务+重加密+抽验，返回新指纹）
- `app/Jobs/PlaceOrderJob.php` — 顺手修坑 #10 同款 `$queue` 重复声明（改 onQueue），仍保持桩（防止未来加载即 fatal）
- `config/plans.php` — 追加 `past_due_grace_days=3`（仅配置项，不动套餐结构）

### 不新增迁移

现有三表够用；`(tenant_id,event_type,occurred_at)` 索引仅 reconcile 用，POC 数据量小，正式期补迁移。

## Implementation Steps

1. **PlanService + QuotaGuard（权益/计划解析）**：单测级先行，verify check1
2. **TwoPhaseMeter（Lua 原子闸门 + 两阶段 + reconcile）**：verify check2/3（含多进程并发）
3. **SubscriptionService 状态机**：verify check4（trial→past_due→suspended→恢复）
4. **AiAnalyzeJob 租户上下文 + AI 配额接线 + pipeline 配额兜底**：verify check5（耗尽→fallback 标注）
5. **OrderExecutor 订单配额接线**：verify check6（用桩 adapter/反射或直接调 gate + freeze 分支，不依赖真实交易所）
6. **setup 命令资源数/渠道门 + PlaceOrderJob 修 $queue**：verify check7
7. **DEK 轮换 rotateDek**：verify check8（轮换后两租户既有账户均可正常解密、新 DEK 落库、旧密文全消失）
8. **隔离断言**：verify check9（两租户 Eloquent scoped 查询互不可见；无 context 下显式跨租户拒绝路径；runWithoutTenant 平台级计数=两家之和）
9. **poc:billing-demo 双租户实跑**：market:feed 同一 WS 双订阅 → 两租户各自计量落库；Redis/DB 验证互不串；文档化观察命令
10. **Step12 沉淀**：README P3 区块 + pitfalls 追加 + 提交推送 + 项目记忆更新

## Validation（`poc:verify-billing` 断言清单）

1. PlanService：free/pro 限额正确；未知 plan_code 抛异常（不降级 free）
2. 两阶段：freeze→used+1、frozen 行；settle→行 settled 计数不变；reverse→行 reversed 计数回退；ref_id 重复 freeze 幂等返回同一行不双扣
3. **并发原子性**：free 档 ai 配额临时设 3，shell 并行起 10 个 `poc:meter-freeze` 一次性进程 → 恰好 3 行 frozen、其余被拒；随后 reconcile（删 Redis key 从 meter_events 重建）计数一致
4. 状态机：provision(trial 14d) → 人为回拨 ends_at → refreshStatus=past_due → 再过 grace → tenant suspended → 重新 provision=active，权益恢复
5. AI 配额耗尽：mock/小配额下 SignalPipeline 产出 source=rule_fallback 且日志含 ai_quota_exhausted，不投 HTTP；配额内正常 source=ai（P2 链路不回归）
6. 订单配额：OrderExecutor 在配额满/租户 suspended 时返回 failedOrder 且**不发起交易所调用**（计数桩验证 adapter->placeOrder 零调用）；失败路径 reverse 还额
7. 资源门：free 租户建第 2 个 signal_subscription 被拒；非白名单 channel（mail）被拒、log 放行；testnet-setup 超 max_strategy_instances 被拒
8. DEK 轮换：轮换后 tenant#1/tenant#2 的 exchange_accounts 均能用新 DEK 解密出原 key；tenants.exchange_dek 已更新；库中不存在旧密文
9. 隔离：A 租户上下文查 MeterEvent/Subscription/SignalSubscription 只见 A；B 同理；平台级 runWithoutTenant 计数为 A+B
10. 收尾输出 42/42 风格的 RESULT 汇总

## Risks

| 风险 | 处理 |
|---|---|
| Redis 计数与 meter_events 漂移（丢 key/手动清库） | reconcile() 从 settled+frozen 行按 UTC 日重建；verify 实测；正式期加每日对账命令 |
| Lua 脚本与 phpredis/predis 客户端差异 | P2 DedupCooldown 已踩过两客户端返回值差异，eval 返回数组统一按 int 归一；两客户端各跑一次 |
| AiAnalyzeJob tries=2 重试双扣 | ref_id 幂等（subId+barOpenTime）；且 reverse 在 HTTP 未发出时执行，重试只在未占额时再占 |
| market:feed 协程内 Redis 阻塞 | P1 已摘 hook，配额 INCR 是亚毫秒串行调用；Lua 单脚本一次 RTT，不新增协程安全面（不引 TenantContext） |
| DEK 轮换中途崩溃 | Redis 锁防并发 + DB 事务；行级重加密中途失败整体回滚（旧 DEK 仍可用）；POC 轮换前停实例，文档注明 |
| 双网格实例同 testnet key 互扰 | 双租户实跑默认只走信号链路；策略实例实跑为可选 stretch，需用户确认 |
| past_due/suspended 正在跑的协程当根 bar | 门只拦新动作（新 AI 分析/新订单），当前 bar 已在途操作不回滚——与「熔断只停开新仓」同语义 |
| 配额按 UTC 日切，跨日边界在途 freeze | key 带 UTC Ymd，freeze 时 EXPIRE；跨日即新桶，旧桶 TTL 自动回收，无需翻桶逻辑 |
