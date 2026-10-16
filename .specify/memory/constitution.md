# 数字货币交易 SaaS 平台 Constitution

## Core Principles

### I. Spec-Driven Development（SDD 优先，不可协商）

- 所有新功能 MUST 走 spec-kit 五阶段：specify → clarify → plan → tasks → analyze → implement → converge；先写 spec 再写实现代码。
- constitution 约束后续所有 plan/analyze 阶段；不为 P1–P5.5 现有代码补 retroactive spec（现有系统是 implementation context，不是回溯规范对象）。
- 理由：替换 P1–P5.5 的 POC→提交→记忆节奏，确保意图与证据先于实现。

### II. 金融精度与时间基准

- 所有金额/数量计算 MUST 用 bcmath 字符串（bcadd/bcmul/bcdiv/bccomp），禁止浮点直接参与交易计算。
- 时间统一 UTC；clientOrderId 幂等模板 `{tenant_id}_{instance_id}_{timestamp}_{random}`。
- 理由：浮点精度在交易系统致命；UTC 保证跨租户一致；幂等防重复下单。

### III. 协程隔离与连接边界

- market:feed MUST 用 `Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL & ~SWOOLE_HOOK_TCP & ~SWOOLE_HOOK_UNIX & ~SWOOLE_HOOK_UDG)`。
- 慢调用（AI HTTP 30s 级）MUST 走独立 queue worker（ai/notifications 队列），绝不进入行情协程进程。
- WS 重连循环各分支 MUST 显式 `Coroutine::isCanceled()` 检查并 return（Coroutine::cancel 非 sticky，异常被吞后协程不退出）。
- 理由：坑 1/2/6——socket 跨协程 fatal、curl hook 与 Guzzle 7.9 不兼容、cancel 非 sticky。

### IV. 状态持久化与崩溃恢复幂等

- 恢复逻辑的幂等守卫 MUST 用独立一次性标志，不得复用会被正常流程改写的业务字段。
- 崩溃恢复 MUST 用 SIGKILL + 状态注入做端到端验证（空状态自然重启无法暴露顺序 bug）。
- Redis 每 bar 写状态 + DB position_snapshot 每 60s 双写。
- 理由：坑 9——hydrate 顺序错误导致重启丢持仓/网格状态。

### V. 计费语义与风控边界（不可降级）

- 计费：AI HTTP 前 freeze；已应答（含 4xx/5xx/解析失败）settle；ConnectionException 未达 reverse；前置失败（无 key/限流/配额尽）不占额。
- 熔断只停开新仓：`check('close')` 永远放行。
- 铁律：信号只通知不下单；neutral 信号不落库。
- 未知 plan_code MUST 抛异常，不得降级（防提权）；log 系统渠道恒放行。
- 理由：计费准确性、防提权、风控边界不可越界。

### VI. 测试隔离与完整路径

- 测试间共享且带 TTL 的键（dedup/限流桶/日计数/unknown streak）MUST 在 setUp 与 tearDown 都按已知逻辑名精确清理。
- 测试持久化字段 MUST 走完整 Domain 层公开方法，不得绕过中间层直接操作内部状态对象。
- PHPUnit 12 MUST 用 `#[DataProvider]` 属性，不认 `@dataProvider` 注解。
- tearDown 的外部 IO 清理 MUST 用可用性标志 + try/catch 包住，且先于 `parent::tearDown()`。
- 理由：坑 24/25/18/27——级联失败、假阳性通过、测试顺序依赖。

### VII. PSR-4 与框架基类对齐

- 一文件一类/接口，不得同文件多类声明（破坏 PSR-4 autoload）。
- 覆写框架基类属性前 MUST 核对父类声明形态（static/非 static、可见性），子类严格对齐。
- readonly class 不得引入含非只读属性的 trait。
- Redis facade 调用 MUST 按 `REDIS_CLIENT` 签名：phpredis `zAdd($key,$score,$member)`、`eval($script,$numkeys,...$args)`，不用 predis 关联数组形态。
- 理由：坑 3/13/19/20/21。

## Technology Stack & Runtime Constraints

- 技术栈：Laravel + Swoole 协程 + Filament v5 + Reverb + Redis（phpredis connector）+ MySQL 8.4。
- AES-256-GCM 加密依赖 sodium 扩展（PHP 8.4 自带）；DEK 轮换为非热操作（停实例后做），KEK = EXCHANGE_MASTER_KEY 64hex 不入库。
- 国内开发机访问 Binance 主网端点不稳定（testnet 可达，主网常被封）；依赖主网公共流的 POC MUST 准备离线断言 + 真实流两条验证路径。
- Windows PowerShell 不支持 heredoc（`<<'EOF'`），git commit 多行信息用 `@'...'@` here-string。
- Laravel Redis facade 同时支持 phpredis/predis，Redis 调用前确认 `.env` 的 `REDIS_CLIENT`。

## Development Workflow

- SDD 五阶段：specify → clarify → plan → tasks → analyze → implement → converge。
- Superpowers 横切 skills（systematic-debugging / tdd / verification-before-completion / git-commit / ponytail-review）按需调用；writing-plans / executing-plans 让位 spec-kit，避免双流程。
- 长驻进程（market:feed / queue:work）用独立 setsid nohup 命令启动；改 .env/config 后 MUST 重启 worker（启动时缓存配置）。
- 正式期 worker MUST 用 supervisor autorestart 配置保活（坑 12：启动期 Redis 拒绝连接无自愈）。

## Governance

- 本 constitution 优于其他实践；修订需文档化、批准、迁移计划。
- 运行时开发指引用 AGENTS.md；运行时踩坑记录用 docs/pitfalls.md。
- 所有 PR/review MUST 验证 constitution 合规；复杂度须有正当理由。
- 版本遵循语义化：MAJOR=原则移除/重定义，MINOR=新增原则/实质扩展，PATCH=澄清/笔误。

**Version**: 1.0.0 | **Ratified**: 2026-10-16 | **Last Amended**: 2026-10-16
