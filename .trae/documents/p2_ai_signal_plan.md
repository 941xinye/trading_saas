# P2 AI 信号闭环 POC 实施计划

## Repository Research（研究结论）

### 已存在的骨架（契约/表/配置齐备，实现全是 throw 桩）

* **Domain 层**（`app/Domain/Signal/`）：

  * `Contracts/Signal.php`：信号只读 DTO（symbol/interval/direction/entry/exit/stop/confidence/rationale/source/rawPayload）

  * `Contracts/AIPrompter.php` + `Contracts/AiUnavailableException.php` + `Contracts/SignalDispatcher.php`

  * `BarAggregator.php`、`PromptBuilder.php`、`RuleEngineFallback.php`、`SignalDispatcher.php`：全部 `throw Not implemented`

  * `Prompters/AbstractAIPrompter.php`（analyze 是桩）、`DeepSeekAdapter.php`（仅 name/model）

* **Jobs**：`CloseBarJob`(queue=aggregation)、`AiAnalyzeJob`(queue=ai)、`DispatchNotificationJob`(queue=notifications)，handle 全是桩

* **Models**：`Signal`、`SignalSubscription`、`SignalNotification`、`KlineBar` 已存在

* **迁移**：`signal_subscriptions` / `signals` / `signal_notifications` / `kline_bars` 四张表已建好（已 migrate）

* **配置**：

  * `config/ai.php` 完整：deepseek provider（api\_base/model/timeout 30s/context 32k）、限流 30/min/tenant、fallback 指标参数（MA 7/25/99、RSI14、MACD 12/26/9、BOLL 20/2、ATR14）、`signal_price_guard` 1%

  * `config/notifications.php` 完整：telegram/wechat\_work/mail/websocket 开关 + Redis 去重冷却 15min

* **环境现状**：

  * `.env`：`QUEUE_CONNECTION=redis`（队列基础设施就绪）、`BROADCAST_CONNECTION=log`；**无** `DEEPSEEK_API_KEY`（用户已有，稍后提供）

  * WSL PHP 8.4 + Swoole 6.2.3；P1 已摘掉 `SWOOLE_HOOK_CURL/TCP/UNIX/UDG`：进程 B 内所有 curl/PDO/Redis 都是**阻塞调用**

  * `market:feed` 当前只承载策略实例 WS（StrategyRunner），1m bar 在 WS 协程回调内串行驱动 onBar 决策链

* **架构方案**：§4.4 信号三表、§5.3 AIPrompter、§5.5 SignalDispatcher、§9.3「AI 调用延迟和失败」（独立 queue=ai、30s 超时、失败走 RuleEngineFallback、source 标记审计、限流/截断）

### 关键架构约束（决定 POC 形态）

1. **AI 一次调用 5\~30s，绝不能放进 market:feed 进程内执行**。P1 摘掉 CURL hook 后 curl 是真阻塞，会卡住整个 Swoole 事件循环（所有 WS 的心跳/收包）。因此 AI 分析必须放到**独立的 queue worker 进程**（普通 PHP 进程，阻塞 curl 天经地义），market:feed 只做毫秒级的入队操作。这与骨架 Job 设计（queue=ai）完全吻合。
2. **指标预热**：MA99 需要 99 根 bar。启动/Job 执行时先用交易所 REST `getKline(symbol,'5m',120)` 预拉历史，不指望靠实时流攒数据。
3. **testnet 行情可用**：testnet.binance.vision 的公开 K 线镜像真实市场，AI 信号分析走公开行情接口，不需要下单权限。
4. **金额 bcmath / 指标 float 分离**：指标计算（RSI/MACD/ATR 含开方、指数）允许用 float，不涉及资金；信号产出的价格在持久化前格式化为定长字符串。
5. 进程 B 崩溃恢复经验（P1 坑 9）：恢复守卫用独立标志、状态先 Redis 后 DB——BarAggregator 的未闭 bar 状态按同一模式做。

## 已确认的 POC 范围

* AI Provider：**DeepSeek**（deepseek-chat，OpenAI 兼容协议，用户自备 key）

* 通知渠道：**日志 +** **`signal_notifications`** **落库**先行；Telegram 渠道代码一并实现但有 token 才启用（用户后续填 token 零改动生效）；Reverb WebSocket 本期不做端到端

* 信号周期：**5m**（由 1m WS bar 经 BarAggregator 聚合）

* POC 延续 P1 风格：先 `poc:verify-ai` 离线命令验证核心链路，再接 `market:feed` 长驻实跑

## Files and Modules

### 新增

| 文件                                                      | 职责                                                                                                                                                                        | <br />   | <br />                           |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | :------- | :------------------------------- |
| `app/Domain/Signal/IndicatorCalculator.php`             | 纯函数指标计算：SMA(7/25/99)、EMA、RSI14、MACD(12/26/9)、BOLL(20,2)、ATR14；输入 `ExchangeKline[]`，输出指标数组；内部 float，输出统一 round                                                             | <br />   | <br />                           |
| `app/Domain/Signal/SignalPipeline.php`                  | 信号编排核心（POC 命令与 AiAnalyzeJob 共用）：历史 bar 准备 → 指标 → prompt → AI analyze → 失败/限流/解析失败走 RuleEngineFallback → 价差校验 → signals 落库（仅 long/short，neutral 只记日志）→ SignalDispatcher 分发 | <br />   | <br />                           |
| `app/Domain/Signal/SignalFeedRunner.php`                | 进程 B 内信号订阅协程编排：启动时加载 active 订阅 → 按 (symbol) 订阅公开 1m WS → BarAggregator 聚合 5m → closed bar 时 upsert `kline_bars` + 按订阅匹配 `AiAnalyzeJob::dispatch`                          | <br />   | <br />                           |
| `app/Domain/Notification/DedupCooldown.php`             | Redis SET NX EX 冷却去重，key=\`tenant:{tid}:dedup:signal:{sha1(symbol                                                                                                         | interval | direction)}\`，TTL 取 config 15min |
| `app/Domain/Notification/Channels/LogChannel.php`       | 日志渠道：结构化写 Laravel log（POC 主验证渠道，channels JSON 里用 `log`）                                                                                                                   | <br />   | <br />                           |
| `app/Domain/Notification/Channels/TelegramChannel.php`  | Telegram sendMessage；无 bot token 时抛渠道未启用（Dispatcher 记 failed/skipped，不影响其他渠道）                                                                                             | <br />   | <br />                           |
| `app/Domain/Notification/Contracts/NotifierChannel.php` | 渠道接口 `send(Signal $signal, int $tenantId): ?string`（返回 provider message id）                                                                                               | <br />   | <br />                           |
| `app/Console/Commands/PocVerifyAiCommand.php`           | `poc:verify-ai`：一键离线验证全链路（见 Validation）                                                                                                                                   | <br />   | <br />                           |
| `app/Console/Commands/PocSignalSetupCommand.php`        | `poc:signal-setup`：为 tenant#1 创建/更新一条 active 订阅（BTCUSDT、5m、deepseek、channels=\["log"]），并探测 DeepSeek 连通性                                                                   | <br />   | <br />                           |

### 修改

| 文件                                                   | 改动                                                                                                                                                                                                                                              |
| ---------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Domain/Signal/BarAggregator.php`                | 实现 `aggregate()`：按交易所 openTime 边界分桶聚合 1m→目标周期；未闭 bar 存 Redis Hash（崩溃可恢复）；跨桶返回 closed `ExchangeKline`；interval=1m 直通                                                                                                                             |
| `app/Domain/Signal/PromptBuilder.php`                | 实现 system+user prompt：交易对/周期、最近 \~60 根 OHLCV 精简序列、指标快照、仓位上下文位；强制 JSON Schema 输出；按 context\_limit 60% 裁剪历史                                                                                                                                       |
| `app/Domain/Signal/Prompters/AbstractAIPrompter.php` | 实现 `analyze()`：Redis 固定窗口限流（读 `config/ai.rate_limiter`，超限抛 AiUnavailableException）→ prompt → OpenAI 兼容 HTTP POST（30s，Bearer）→ JSON 提取（去 code fence/`json_decode`/枚举与数值校验/1 次纠错重试）→ Signal DTO；任何 HTTP/超时/解析异常 report 后包成 AiUnavailableException |
| `app/Domain/Signal/Prompters/DeepSeekAdapter.php`    | 提供 endpoint/key/model 配置读取（通用 OpenAI 兼容 HTTP 逻辑放 Abstract）                                                                                                                                                                                      |
| `app/Domain/Signal/RuleEngineFallback.php`           | 确定性规则：MA7/25/99 排列 + RSI 超买超卖 + MACD 柱反转给方向；ATR 算止盈止损；confidence 固定 60；`source=rule_fallback`                                                                                                                                                   |
| `app/Domain/Signal/SignalDispatcher.php`             | 实现：去重冷却 → 每渠道写 `signal_notifications`(queued) → `DispatchNotificationJob::dispatch`；命中冷却写 deduped                                                                                                                                               |
| `app/Jobs/AiAnalyzeJob.php`                          | handle：恢复上下文 → 构造公开行情适配器 → REST 拉 120 根 5m → SignalPipeline；tries=2（AI 失败已在 pipeline 内吞掉走兜底，Job 仅兜底兜底失败）                                                                                                                                        |
| `app/Jobs/DispatchNotificationJob.php`               | handle：按 channel 解析渠道实现 → send → 更新 signal\_notifications 为 sent/failed                                                                                                                                                                         |
| `app/Console/Commands/MarketFeedCommand.php`         | 在 StrategyRunner 之外并行启动 SignalFeedRunner（独立协程组，信号 WS 与策略 WS 互不影响）                                                                                                                                                                               |
| `.env`（Windows+同步 WSL）                               | 新增 `DEEPSEEK_API_KEY=`（用户提供）；其余配置已就绪                                                                                                                                                                                                            |
| `README.md` / `docs/pitfalls.md`                     | 实跑后沉淀 P2 用法与新坑                                                                                                                                                                                                                                  |

## Implementation Steps（依赖序）

1. **环境与连通性**：用户填 `DEEPSEEK_API_KEY` → 同步 WSL → curl/PHP 最小脚本验证 `api.deepseek.com` 连通 + key 有效（chat/completions 打一句 ping），先排网络/鉴权坑。
2. **IndicatorCalculator + BarAggregator**：两个纯逻辑组件先完成，不依赖外部服务；用合成数据自测（6 根合成 1m bar 验证 5m 边界闭合、OHLCV 聚合；120 根 REST bar 验证 MA/RSI/MACD/BOLL/ATR 数值合理性）。
3. **PromptBuilder**：模板 + JSON Schema 约束 + 历史裁剪。
4. **AbstractAIPrompter + DeepSeekAdapter**：限流 → HTTP → JSON 容错解析（含 `response_format`/code fence 剥离/1 次重试）。
5. **RuleEngineFallback**：确定性信号。
6. **SignalPipeline**：把 2\~5 串起来，加 1% 价差守卫与 signals 落库。
7. **通知侧**：DedupCooldown + LogChannel + TelegramChannel + SignalDispatcher + 两个 Job 落地。
8. **`poc:verify-ai`**：离线端到端验证命令（验证点见下），要求 exit=0。
9. **`poc:signal-setup`**：种子订阅 + 连通性探测。
10. **SignalFeedRunner + market:feed 接线**：公开 1m WS → 聚合 5m → upsert kline\_bars → 按订阅投 AiAnalyzeJob。
11. **长驻实跑**：WSL 内 `market:feed` + `queue:work --queue=ai,notifications`（setsid nohup，POC 不上 supervisor），等真实 5m closed bar，观察 signals/signal\_notifications/去重/日志。
12. **沉淀**：README 标记 P2 POC 完成项与边界、pitfalls 追加新坑、提交推送、WSL 同步、更新项目记忆。

## Validation

### `poc:verify-ai` 断言项（任何一项失败即 exit=1）

1. BarAggregator：合成 1m 序列在 5m 边界正确产出闭合 bar，OHLCV/trade 聚合值正确，Redis 未闭 bar 状态可恢复
2. IndicatorCalculator：对真实 REST 120 根 5m bar 产出全部指标且数值有限（MA 多头/空头排列可识别、RSI∈\[0,100]、BOLL 上轨≥中轨≥下轨）
3. **真实 DeepSeek 调用**：返回合法 Signal（direction 枚举、价格为正、confidence 0\~100、rationale 非空），signals 表有 source=ai 记录（long/short 才入库）
4. **兜底链路**：用错误 key/endpoint 强制 AiUnavailableException → RuleEngineFallback 产出 source=rule\_fallback 信号并落库
5. **价差守卫**：把 entry 改为偏离现价 >1% → 信号被丢弃（不入库不分发），日志可见
6. **去重**：同方向同 symbol 连续分发两次 → 第二条 signal\_notifications 状态 deduped
7. 输出 signals / signal\_notifications 汇总表

### 长驻实跑验证

* 5m 整点 closed bar 后：`kline_bars` upsert 一行、`signals` 出现 source=ai（或限流/失败时 rule\_fallback）记录、`signal_notifications` 每渠道一行（log 渠道 sent）

* market:feed 进程在 AI 30s 调用期间**不受影响**（策略 WS bar 照常推进）——这是队列隔离的核心证据

* AI 30s 超时/429 场景在实跑中若出现，确认自动降级且 worker 不挂

## Risks

| 风险                                          | 处理                                                                                                               |
| ------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| DeepSeek 返回非严格 JSON（解释性文字/代码块包裹/字段缺失）       | `response_format=json_object` + code fence 剥离 + 字段校验 + 1 次带纠错提示的重试；仍失败走 rule\_fallback（架构 §9.3 硬约束：AI 失败永不阻塞主链路） |
| AI 30s 延迟拖垮行情进程                             | AI 仅在独立 queue worker 执行；进程 B 入队是毫秒级 Redis/DB 操作。worker POC 用 nohup 手工常驻，正式期交 supervisor                          |
| WSL 到 api.deepseek.com 网络问题（参考之前 GitHub 被墙） | Step 1 先做最小连通性验证；DeepSeek 国内可直连，若仍不通再评估代理（队列 worker 进程支持 HTTP\_PROXY 环境变量）                                       |
| 5m bar 边界与交易所不一致                            | 严格用交易所返回的 1m openTime 对 5min(300000ms) 取整分桶，不用本地时钟（架构 §9.6）                                                      |
| 进程 B 崩溃丢失未闭聚合 bar                           | 未闭 bar 状态写 Redis Hash，启动时 hydrate（沿用 P1 双写与独立恢复标志的教训）                                                            |
| Token 成本/刷屏                                 | 单订阅 5m 即 288 次/天（deepseek-chat 单次极低成本）；限流 30/min/tenant 硬顶；同方向 15min 去重；neutral 不入库不分发                           |
| queue worker 失败任务静默                         | POC 直接观察 worker 日志 + signals 表 source 分布；AiAnalyzeJob tries=2；正式期接 failed\_jobs/告警（P3+）                          |
| 信号与自动交易耦合风险                                 | P2 信号链路**只分析与通知，不触发下单**；信号→交易联动属后续阶段                                                                             |

## 本期明确不做（边界）

* Reverb WebSocket 端到端（代码不接，`BROADCAST_CONNECTION=log` 保持）、企微/邮件渠道

* CloseBarJob 的 aggregation 队列拆分（POC 由进程 B 内联 upsert + 直接投 ai 队列，少一个常驻队列）

* AI 信号驱动自动下单、信号历史回测、指标面板

* supervisor/systemd 进程守护、多租户多订阅的大规模协程编排（POC 仅 tenant#1 单订阅）

