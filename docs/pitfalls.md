# 踩坑记录（P1–P5.5 实测，共 28 条）

> 2026-10-13 起在 WSL2 + Ubuntu 22.04（PHP 8.4.26 + Swoole 6.2.3）环境实测沉淀，随各阶段持续追加（#1–9 P1 / #10–12 P2 / #13–16 P3 / #17–19 P4 / #20–23 P5 / #24–28 P5.5 测试与 CI）。
> 每条记录：现象 → 根因 → 处理 → 代码位置。后续阶段（P6+）踩坑继续追加到本文件。

## 1. Swoole curl hook 与 Guzzle 7.9 不兼容

- **现象**：REST 请求实际成功，但整体抛 ErrorException；`market:feed` 启动即报 warning。
- **根因**：Guzzle 7.9 在 handle release 时执行 `curl_setopt(CURLOPT_PREREQFUNCTION, null)`，而 Swoole hook 的 `swoole_curl_setopt` 不支持该 option（20312），warning 被 Laravel 转为 ErrorException。
- **处理**：`Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL)`，REST 走阻塞 curl（单实例场景每次 <1s 可接受），WS 走 Swoole 原生 socket 协程不受影响。
- **位置**：[MarketFeedCommand.php](../app/Console/Commands/MarketFeedCommand.php)、[PocVerifyFeedCommand.php](../app/Console/Commands/PocVerifyFeedCommand.php)
- **遗留**：P2 计划将 REST 换成协程 HTTP client，届时可恢复 CURL hook。

## 2. Coroutine::cancel 不是 sticky 的

- **现象**：WS 断开重连循环 `catch(Throwable)` 吞掉取消异常后继续重连，协程永不退出。
- **根因**：`Coroutine::cancel()` 通过异常中断阻塞调用，但若异常被捕获且未退出，协程会继续跑。
- **处理**：重连循环的各分支显式 `Coroutine::isCanceled()` 检查并 `return`（配合 `unsubscribeStream`）。
- **位置**：[BinanceSpotAdapter.php](../app/Domain/Exchange/Adapters/BinanceSpotAdapter.php#L295-L324)

## 3. 同文件多类/接口声明破坏 PSR-4

- **现象**：非 `composer dump-autoload -o` 环境下报 "Interface not found"。
- **根因**：KeyResolver 接口曾放在 AesGcmCrypt.php 文件底部，PSR-4 按文件名映射找不到接口。
- **处理**：所有类/接口必须独立成文件，一文件一类。
- **位置**：[TenantKeyResolver.php](../app/Domain/Tenancy/TenantKeyResolver.php)（独立文件）

## 4. `php -l` 只查语法，查不出 use 导入缺失

- **现象**：`php -l` 全绿，运行时报 class not found（如 ExchangeManager 未 use）。
- **处理**：最小验证闭环 = WSL 内 `php artisan` 实跑目标命令；不要依赖静态语法检查。

## 5. AES-GCM 依赖 sodium 扩展

- **说明**：AES-256-GCM 加解密依赖 libsodium（PHP 8.4 自带）；Windows CLI 环境若无 sodium 会在加密账户时失败。WSL 侧已验证可用。
- **位置**：[AesGcmCrypt.php](../app/Support/AesGcmCrypt.php)、[ADR-0003](adr/0003-aes-256-gcm-api-key-encryption.md)

## 6. Swoole 协程化 PDO/Redis 连接被多协程并发复用

- **现象**：`market:feed` 收到若干 bar 后 `PHP Fatal error: Uncaught Swoole\Error: Socket#N has already been bound to another coroutine, reading of the same socket in coroutine at the same time is not allowed`（PDO->prepare 处），事件循环终结，命令以 exit 0 退出。
- **根因**：`SWOOLE_HOOK_ALL` 把 PDO/phpredis 的 TCP 连接协程化。实例保活协程每 5s 用同一 DB 连接查 status，WS 协程 onBar 内也访问 DB/Redis（下单/健康检查/状态持久化），Swoole 不允许两个协程同时读写同一条 socket。单 Laravel 连接 + 多协程 = 必现。
- **处理（P1）**：`Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL & ~SWOOLE_HOOK_TCP & ~SWOOLE_HOOK_UNIX & ~SWOOLE_HOOK_UDG)`，PDO/phpredis 退化为阻塞 IO——调用期间不发生协程切换，天然串行；WS 走 Swoole 原生协程客户端不受影响。
- **P2**：多实例必须上连接池（每协程独占连接）或显式连接绑定后再恢复 socket hook。
- **位置**：[MarketFeedCommand.php](../app/Console/Commands/MarketFeedCommand.php)

## 7. 交易所错误处理写库失败会掩盖原始 REST 错误

- **现象**：下单返回 401/403，但日志只剩 PDO fatal，看不到交易所错误码。
- **根因**：REST 失败 → `ExchangeHealthChecker::record()` 写库 → 写库本身又因坑 6 抛异常，原始 `ExchangeApiException` 被覆盖。
- **排查手段**：脱离框架用最小 curl 直连交易所接口（testnet 签名 GET /api/v3/account），区分「key 本身无效（-2015）」与「签名错误（-1022）」；签名错误在 key 通过识别之后才会出现。
- **testnet 坑**：Binance Spot Testnet（testnet.binance.vision）与 Futures Testnet 的 key 不通用；testnet 账号会定期重置，key 失效后需重新生成。

## 8. 签名串与发送串不一致导致 Binance -1022

- **现象**：新 testnet key 已通过 GET /api/v3/account 验证，但策略 POST /api/v3/order 一律返回 `400 -1022 Signature for this request is not valid`。
- **根因**：签名时 `buildQuery()` 先 `ksort()` 排序再 `http_build_query()`，而发送时 Guzzle `form_params` 按**数组插入顺序**编码 body。Binance 用实际收到的 raw query/body（去掉 signature）验签，签名串与发送串参数顺序不同 → HMAC 不匹配。GET 参数少且手工 curl 构造时串一致，掩盖了该问题。
- **处理**：签名请求统一生成确定性 payload 字符串（`ksort` 后序列化 → HMAC → 追加 `&signature=`），GET 拼到 URL、POST/DELETE 作为 `withBody($payload, application/x-www-form-urlencoded)` 原始 body，保证「参与签名的串」与「实际发送的串」逐字节一致；删除中间的 `applySignature()` 数组方案。
- **排查关键**：`-2015`（key 识别层）通过后才会到 `-1022`（签名层）；手工 curl 用「同串签名+发送」成功而框架失败，即可锁定为序列化不一致。
- **验证结果**：testnet 市价买单真实成交（orderId=2259141，filled 0.00011 BTC @ 83548.01）。
- **位置**：[AbstractExchangeAdapter.php](../app/Domain/Exchange/Adapters/AbstractExchangeAdapter.php)

## 9. hydrate 顺序错误导致崩溃恢复丢失持仓/网格状态

- **现象**：`kill -9` 强杀 `market:feed` 后重启，SpotGridStrategy 的持仓 `grids`、pending 格、`last_index` 全部丢失，重启后第一根 bar 就用空内存覆盖了 Redis state。
- **根因**：`onBar()` 先调 `isConfigured()`，而后者首次执行会把 `$gridStep` 从初始 `'0'` 算成实际步长；随后 `hydrateFromSnapshot()` 的守卫是 `positionSnapshot===null || gridStep!=='0' → return`，此刻 `gridStep` 已非 `'0'`，恢复快照被**永久跳过**——用业务字段兼任"是否已初始化"标志位导致的顺序耦合 bug。
- **处理**：新增独立的 `$hydrated` 一次性标志位取代借用 `gridStep` 判断；把 `hydrateFromSnapshot()` 移到 `isConfigured()` **之前**，直接从快照恢复 `gridStep/lastIndex/grids`（快照带 `grid_step` 时信任快照，`isConfigured` 不再重算）。
- **验证（崩溃恢复测试法）**：
  1. 往 Redis state 注入两个**远离现价**的格（cell1 持仓 / cell2 pending，1-2 根 1m bar 不可能触及，零交易副作用），并备份原 state；
  2. 注入与 `kill -9` **背靠背**执行——运行进程每 bar 会用内存快照覆盖 Redis，间隔久了注入值会被冲掉；
  3. 重启后等 2 根 bar：两格原样重放、`last_index` 连续、bar 正常推进、无重复下单；
  4. 连续第二次 `kill -9` → 重启，验证多轮恢复幂等；
  5. 顺带验证 Redis 每 bar + DB `position_snapshot` 每 60s 双写都落在注入状态；
  6. 测完先杀进程再写回干净 state/清 DB 快照并重启。
- **教训**：恢复逻辑的幂等守卫要用独立标志，不要复用会在正常流程中被改写的业务字段；崩溃恢复必须用 `SIGKILL`（不给 flush/信号清理机会）+ 状态注入做端到端验证，空状态自然重启无法暴露此类问题。
- **位置**：[SpotGridStrategy.php](../app/Domain/Strategy/Spot/SpotGridStrategy.php)

## 10. Laravel 13 Job 重复声明 `$queue` 属性 fatal（Queueable trait 已自带）

- **现象**：`AiAnalyzeJob` / `DispatchNotificationJob` 写 `public $queue = 'ai'` 后，骨架期桩文件从未被加载没暴露；P2 真正 `dispatch()` 时报 fatal：`TypeError/define the same property ... definition differs incompatible`。
- **根因**：Laravel 的 `Queueable` trait 内部已声明 `$queue`（默认 null），子类用固定默认值再声明同名属性与 trait 定义不兼容（PHP 8.3+ 对 trait/类同名属性检查更严）。
- **处理**：Job 类内不再声明属性，改为构造函数中 `$this->onQueue('ai'|'notifications'|'aggregation')`；需要延迟/中间件同理用链式方法，不碰属性。
- **位置**：[AiAnalyzeJob.php](../app/Jobs/AiAnalyzeJob.php)、[DispatchNotificationJob.php](../app/Jobs/DispatchNotificationJob.php)

## 11. 进程中途启动产生"残次聚合桶"：缺 ticks 计数的 5m bar 也会闭合投递

- **现象**：`market:feed` 在 17:14 才连上 WS，17:16 闭合的 17:10 桶只聚到 1 根 1m bar，照样写了 `kline_bars`（trades=36，正常 200+）并触发 AI 分析，脏数据 + 无效信号。
- **根因**：原 BarAggregator 只做"openTime 取整 + OHLCV 累加 + Redis 恢复"，不区分一个桶收齐了几根 1m；半道 hydrate 出来的状态天然缺腿，却与完整桶走同一条闭合路径。
- **处理**：桶状态增加 `ticks` 计数与 `EXPECTED_TICKS`（5m=5/15m=15/1h=60/4h=240/1d=1440）；Runner 闭合时 `lastClosedTicks() < expected` 只记 WARNING 跳过（**不写 kline_bars、不投 AiAnalyzeJob，宁可漏一轮也不分析残桶**）。
- **实战验证**：17:31 桶 hydrate 的是**旧版进程写入、无 ticks 字段**的状态（`?? 0` 后 ticks=1），被守卫拦截；17:36 由新进程独立收集满 5 ticks 的桶正常闭合落库（trades=213）并投递——证明守卫对"版本升级中途重启"同样成立。
- **教训**：任何基于"周期闭合"的聚合都必须同时校验"周期内样本数"；崩溃恢复只解决状态不丢，不解决状态本身不完整。
- **位置**：[BarAggregator.php](../app/Domain/Signal/BarAggregator.php)、[SignalFeedRunner.php](../app/Domain/Signal/SignalFeedRunner.php)

## 12. `queue:work` 启动期 Redis 瞬时拒绝连接即退出，不做自愈

- **现象**：与 `market:feed` 用 `&` 同 shell 连启 worker，日志报 `RedisException Connection refused` 后进程直接死亡；几秒后 Redis（WSL → Windows portproxy 16379）实际可通，但 worker 不会自己回来，队列积压 3 个 ai 任务。
- **根因**：queue worker 启动时建连失败按致命错误退出（与运行期命令重试不同）；POC 手工 nohup 启动没有任何保活。
- **处理（POC 期）**：worker 单独启动、死亡后手动重启，积压任务在 23ms~776ms 内排空，证明幂等。
- **正式期要求**：supervisor/systemd 必须配 `startretries` + `autorestart=true`（计划 P2 收尾进部署清单）；慢调用（AI 30s）与通知严格分队列 `ai`/`notifications`，worker 独立于行情协程进程。
- **位置**：[MarketFeedCommand.php](../app/Console/Commands/MarketFeedCommand.php)（仅启动行情进程，AI 分析走队列隔离）

## 13. Laravel Redis 连接层 `eval()` 签名不是 predis 原生形态（首跑即 TypeError）

- **现象**：TwoPhaseMeter 首跑 `Redis::connection('default')->eval($script, [$key, ...$args], 1)` 直接 fatal：`Redis::eval(): Argument #3 ($num_keys) must be of type int, array given`。
- **根因**：Laravel 的 `PhpRedisConnection::eval($script, $numberOfKeys, ...$arguments)` 把 numkeys 放第 2 参、keys/argv 展平为可变参（内部再 pack 成 phpredis 要的 `[$script, $args, $numkeys]`）；predis 形态 `eval($script, $keysAndArgsArray, $numkeys)` 在 Laravel 连接层不成立。
- **处理**：统一写 `eval($script, 1, $key, (string) $limit, (string) $ttl)`（phpredis/predis 两种 connector 通用）；数值参数字符串化交给 Lua `tonumber`，规避 pack 层对 int 的序列化歧义。
- **位置**：[TwoPhaseMeter.php](../app/Domain/Billing/Metering/TwoPhaseMeter.php)、[TenantKeyResolver.php](../app/Domain/Tenancy/TenantKeyResolver.php)

## 14. 并发配额测试要从"干净桶"开始：上一段断言遗留的计数会改变允许数

- **现象**：`poc:verify-billing` 第 3 段"10 进程抢 3 配额"首次只放行 2 个（frozen 行也只有 2），Lua 逻辑本身经审查无竞争漏洞。
- **根因**：日配额桶按 `tenant+event+UTC 日` 复用，第 2 段两阶段断言结束时桶内已有 1（settled 占额不归还）；第 3 段从计数 1 起跑，limit=3 自然只有 2 个 INCR 名额。不是并发安全问题，是夹具隔离问题。
- **处理**：每段断言只对自己的 ref 前缀（如 `c3-*`）做断言，并在段首 `flushDayCounter()` 明确桶的起点；并发安全由 Lua 单脚本 check+INCR 保证（Redis 单线程串行执行，10 进程结果严格 3 allowed / 7 denied）。
- **教训**：共享 Redis 计数的测试，"前置状态"与"被测行为"同等重要；DB 行可以靠 ref 隔离，热计数桶必须显式重置。
- **位置**：[PocVerifyBillingCommand.php](../app/Console/Commands/PocVerifyBillingCommand.php)

## 15. 离线验证不能伪造 `signals.subscription_id`：FK 约束只认真实行

- **现象**：验证 pipeline 兜底分支时用 stdClass 伪造订阅（id=900001），指标/兜底全对，落库瞬间被外键约束打回（`signals.subscription_id` 引用 `signal_subscriptions.id`）。
- **处理**：插入真实的 `active=0` 脚手架订阅行（参照 P2 `poc-verify-ai` 命名），pipeline 用其真实 id；active=0 不被 SignalFeedRunner 加载、不计入 `max_signal_subscriptions` 资源数，用完随租户级联清理。
- **位置**：[PocVerifyBillingCommand.php](../app/Console/Commands/PocVerifyBillingCommand.php)

## 16. 一条 `bash -c` 里用 `&& ... &` 连启两个长驻进程：第二个在错误 cwd 启动即死

- **现象**：`cd app && nohup market:feed > log & nohup queue:work > log &` 一条命令连启，5 秒后两个 pgrep 都能查到，几分钟后 worker 已死、日志文件根本没生成；期间投递的 2 个 Job 静默堆积。
- **根因**：bash 中 `&&` 优先级高于 `&`，第一条被整体后台化在**子 shell** 里执行，其中的 `cd` 不影响父 shell；第二条 nohup 在 `$HOME` 启动，相对重定向路径不存在、且 `artisan` 不在 cwd，进程秒退。pgrep 早查恰好看到了退出中的命令行。
- **处理**：每个长驻进程用**独立命令**启动（各自完整 `cd ~/trading_saas && nohup ... &`），启动后立刻 `pgrep` + `tail` 日志双确认；worker 晚起不丢任务——Redis 队列在其上线后把积压 Job 自动排空（本次 2 个任务各 1s 完成）。
- **位置**：本节为运维流程坑，无代码变更

## 17. RiskGuard.check(OPEN) 未调 attemptProbe：OPEN→HALF_OPEN 永不转换

- **现象**：`poc:verify-risk` 26/35 通过，核心失败链在 HALF_OPEN 探测：trip 后 state=OPEN ✓，冷却到期后 check('open') 期望转 HALF_OPEN 但 state 仍=OPEN，后续 recover/事件计数/命令幂等全连锁失败。
- **根因**：[RiskGuard.check()](../app/Domain/Risk/RiskGuard.php#L80) 原逻辑 `if (state === OPEN) return decide(false, 'breaker_open')` 直接拒绝，**不调用 attemptProbe()**。而 CircuitBreaker.attemptProbe() 本身已处理 OPEN 状态下冷却到期→HALF_OPEN 的转换，但 RiskGuard 只在 HALF_OPEN 分支调它，形成死锁：OPEN 永远不会变成 HALF_OPEN。
- **处理**：OPEN 分支改为 `return breaker.attemptProbe() ? decide(true, 'probe_allowed') : decide(false, 'breaker_open')`——冷却到期则自动转 HALF_OPEN 并放行探测单，否则继续拒绝。
- **位置**：[RiskGuard.php](../app/Domain/Risk/RiskGuard.php#L80-L85)
- **教训**：三态机中"冷却到期自动降级"的转换点必须在每次 check 时主动尝试，不能假设外部（定时器/cron）会触发；attemptProbe 的设计已内含此逻辑，调用方必须在 OPEN 状态也调它。

## 18. 测试绕过 RiskGuard.recordTrade 直接调 metrics()：Redis last_trade_date 未更新

- **现象**：断言 10（崩溃恢复 lastTradeDate 注入）失败——跨日后 Redis `last_trade_date` 未更新为今日。
- **根因**：测试代码 `$guard->metrics()->recordTradeWithDate(...)` 直接操作 BreakerMetrics 内存对象，绕过了 `RiskGuard.recordTrade()` 内的 `if (result['dayChanged']) breaker.setLastTradeDate(utcDate)` Redis 写入链路。metrics 内存状态已更新但 Redis Hash 未同步。
- **处理**：测试改为 `$guard->recordTrade(instanceId, makeOrder('sell', '1', '98.5'))`，sell 产生 pnl=-1.5，走 RiskGuard.recordTrade 完整链路（trackFill→recordTradeWithDate→dayChanged→setLastTradeDate→Redis hSet）。
- **位置**：[PocVerifyRiskCommand.php](../app/Console/Commands/PocVerifyRiskCommand.php#L566-L572)
- **教训**：测试持久化字段时必须走完整的写入路径（Domain 层公开方法），不能绕过中间层直接操作内部状态对象；否则"内存改了但没落库"的假阳性通过会掩盖真实 bug。

## 19. readonly class 不能 use 含非只读属性的 trait

- **现象**：`Error: Readonly class App\Events\BreakerAlert cannot use trait Illuminate\Broadcasting\InteractsWithSockets`（trait 有非只读属性 `$socket`）。
- **根因**：PHP 8.3 readonly class 禁止引入可变属性，Laravel 的 `InteractsWithSockets` trait 声明了 `public $socket = null`（非只读），与 readonly 语义冲突。
- **处理**：从 BreakerAlert 移除 `InteractsWithSockets` trait，仅保留 `use Dispatchable;`（P4 广播不需要 socket 排除功能）；broadcastOn() 直接返回 PrivateChannel。
- **位置**：[BreakerAlert.php](../app/Events/BreakerAlert.php)

## 20. Filament Widget `$view` 父类声明非 static，子类不能改 static

- **现象**：`Error: Cannot redeclare non static Filament\Widgets\Widget::$view as static App\Filament\Platform\Widgets\LiquidationMapWidget::$view`。
- **根因**：Filament v5 父类 `Widget` 声明 `protected string $view`（实例属性），子类声明成 `protected static string $view` 与父类定义冲突（PHP 不允许子类把实例属性改为 static）。
- **处理**：子类改为 `protected string $view = 'filament.platform.widgets.liquidation-map'`（非 static），与父类声明形态一致。
- **位置**：[LiquidationMapWidget.php](../app/Filament/Platform/Widgets/LiquidationMapWidget.php)
- **教训**：覆写框架基类属性时先看父类声明形态（static/非 static、可见性），子类必须严格对齐，否则 PHP 8.3+ 直接 fatal。

## 21. phpredis `zAdd` 签名是 `($key, $score, $member)`，不是 predis 的 `($key, [$member => $score])`

- **现象**：`Redis::zAdd() expects at least 3 arguments, 2 given`。
- **根因**：Laravel Redis facade 默认走 phpredis connector，`zAdd($key, $score, $member, ...)` 是可变参展平形态；predis 风格 `zAdd($key, [$member => $score])`（关联数组）在 phpredis 下被当作单个 array 参数，参数数=2 不满足 ≥3 的要求。坑 13 同源（Laravel 连接层签名不等于客户端原生签名）。
- **处理**：统一用 `Redis::zadd($zsetKey, (float) $e->tsMs, $eid)`（phpredis 形态）；涉及批量 zAdd 改用 pipeline 逐条 zAdd 或展平成 `zadd($key, $s1, $m1, $s2, $m2, ...)`。
- **位置**：[LiquidationClusterer.php](../app/Domain/Liquidation/LiquidationClusterer.php)
- **教训**：Laravel Redis facade 同时支持 phpredis/predis 两种 connector，写 Redis 调用前确认 `.env` 的 `REDIS_CLIENT`，按对应客户端签名写；关联数组形态只对 predis 成立。

## 22. BinanceFuturesAdapter 必须覆写 `wsBaseUrl()` 强制主网：testnet 现货 WS 不支持合约强平流

- **现象**：`poc:liquidation-demo` 启动即 `WS upgrade failed: stream.testnet.binance.vision:9443/ws/!forceOrder@arr err=104`。
- **根因**：父类 `AbstractExchangeAdapter::wsBaseUrl()` 检测 `config('exchange.exchanges.binance.testnet')=true` 时返回现货 testnet URL（`wss://stream.testnet.binance.vision/ws`）；但 `!forceOrder@arr` 是**合约**强平流，testnet 现货 WS 根本没有这个 stream，upgrade 被拒。testnet 开关是为现货 P1 下单闭环设的，不能让合约流订阅复用。
- **处理**：BinanceFuturesAdapter 覆写 `wsBaseUrl()`，强制返回主网 `wss://fstream.binance.com/ws`（读 `config('exchange.exchanges.binance.futures_ws_url')`，缺省回退硬编码主网）——合约公共流无需凭据，主网直连。
- **位置**：[BinanceFuturesAdapter.php](../app/Domain/Exchange/Adapters/BinanceFuturesAdapter.php)
- **教训**：同一交易所的现货/合约 endpoint 不同（binance: stream.binance.com vs fstream.binance.com），testnet 端点更不通用；Adapter 按市场分支时，每个市场的 WS/REST base URL 都要独立配置，不能让父类的 testnet 现货开关影响合约主网流。

## 23. WSL 出口网络对 Binance 主网端点不可达（testnet 可达）

- **现象**：`poc:liquidation-demo` 覆写 `wsBaseUrl()` 用主网后，`WS upgrade failed: fstream.binance.com:443/ws/!forceOrder@arr err=111`（ECONNREFUSED）；`api.binance.com:443` 同样 refused。但 `stream.testnet.binance.vision:9443` / `testnet.binance.vision:443` / `github.com:443` 均可通。
- **排查**：`nslookup fstream.binance.com → 199.59.148.8`，`nc 199.59.148.8 443/9443` 均 Connection refused，说明不是 DNS 污染而是 IP/端口层被拦。出口公网 IP 112.49.194.245（动态宽带）可能被 Binance 主网地理封禁，或 ISP 层封禁 Binance 主网 IP 段。
- **处理**：P5 接受离线 POC 15/15 PASS 作为验证通过，跳过真实 fstream 实跑；`poc:liquidation-demo` 命令保留，网络恢复后随时可补测。
- **教训**：国内开发机访问 Binance 主网端点不稳定（testnet 通常放行，主网常被封）；依赖主网公共流的 POC 要准备好离线断言 + 真实流两条验证路径，网络阻塞时离线断言仍能证明核心逻辑正确；正式部署需配置代理或部署在境外节点。

## 24. 测试 setUp 里 skip 后，tearDown 的外部清理抛异常会污染 RefreshDatabase 事务并级联拖垮全套测试（P5.5 步骤 0）

- **现象**：本机 Redis 未启动时跑全套，首个 `@group redis` 测试在 setUp 调 `requireRedis()` 已正确 `markTestSkipped`，但测试仍报 FAIL（RedisException），随后同文件其余 6 个测试以及另一个测试类的 3 个 mysql 组测试全部连锁报 `SQLSTATE[HY000]: cannot start a transaction within a transaction`，10 个测试级联失败。
- **根因**：①tearDown 无条件执行 `flushDayCounter()`（Redis 调用），Redis 不可用再抛异常——skip 发生在 `parent::setUp()`（RefreshDatabase 已 BEGIN 事务）之后，tearDown 的异常导致框架没走到事务回滚；②SQLite 内存连接的事务一直开着，后续每个测试 BEGIN 都失败，跨测试类也不恢复。
- **处理**：外部服务清理在 tearDown 中必须满足三条——a) 用 `$redisAvailable` 标志位（仅 setUp 中 ping 成功才置真）包住；b) 再套 try/catch 兜底；c) 清理放在 `parent::tearDown()` 之前。修复后 Redis 停机全套 = 5 passed + 10 skipped 全绿。
- **教训**：`markTestSkipped` 不是测试的终点，tearDown 照样执行；任何 tearDown 清理都不得引入未捕获异常，尤其是外部 IO（Redis/HTTP/文件锁）。服务可用性判定统一收敛到 `Tests\Concerns\ChecksRedisAvailability`，后续 mysql/其他外部服务照此模式扩展。

## 25. 带 TTL 的业务去重键会让"全套连跑"与"单跑"结果不同：测试必须自扫 dedup 槽（P5.5 步骤 1/3）

- **现象**：`ReconciliationServiceTest` 单文件全绿；并入全套后 `critical_does_not_override_breaker_paused` 稳定失败——期望 ≥2 行 risk_notifications，实际 0 行。
- **根因**：对账告警走 `DedupCooldown`（`tenant:{tid}:dedup:reconcile:{sha1(iid|kind)}`，TTL 15 分钟）。同文件前一个用例 `critical_when_exchange_missing_coins_pauses` 用的是同一租户/实例/同一 critical 指纹，先占了槽位；后一个用例的告警被判 deduped 不再落库。单跑（或 Redis 刚 flush）时键不存在所以绿，Redis 有持久化残留时连跑必挂。
- **处理**：测试 setUp **和** tearDown 都按已知逻辑名精确清理本组用到的全部 dedup 键 + unknown 计数键（清理仍套坑 #24 的标志位 + try/catch）。凡是测试间共享、且生产语义带 TTL 的键（dedup、限流桶、日计数、unknown streak）都照此办理，不能依赖"测试数据的 ID 不一样"——状态指纹可能与业务 ID 无关。
- **教训**：迁移 POC 断言进 PHPUnit 时，POC 命令"开头清一次、人手工隔很久才跑一次"的清理习惯不够；PHPUnit 一秒连跑多个用例，TTL 去重直接变成用例顺序依赖。

## 26. phpredis 全局前缀下，`keys()` 返回的是带前缀全名，再 `del()` 会拼出双重前缀删不掉（P5.5 步骤 3）

- **现象**：某迁移测试用 `Redis::keys('tenant:x:*')` 拿 key 再逐个 `del`，首跑后 dedup 键残留，第二次运行 0 行通知，排查时 redis-cli 能看到 key 还在。
- **根因**：[config/database.php](../config/database.php) 的 phpredis 配了全局前缀（APP_NAME slug + `-database-`）。所有命令参数会自动拼前缀，但 `keys('pattern')` 的**返回值**已经是带前缀的完整名；把完整名再传给 `del()`，客户端又拼一次前缀 → 删的是不存在的键。
- **处理**：测试清理一律按**已知逻辑名**精确 `del('tenant:...')`（前缀由客户端拼一次）；需要模式扫描时，先对返回值 strip 掉 `Redis::getOption(Redis::OPT_PREFIX)` 再删。旧 POC 命令里的 `keys()->del()` 同款写法在 RDB/AOF 持久化环境也有残留隐患。
- **教训**：带前缀的 Redis 客户端，"传参加前缀、回传不带处理"是不对称的；模式清理不能想当然。

## 27. PHPUnit 12 不认 `@dataProvider` 注解，必须用 `#[DataProvider]` 属性（P5.5 步骤 3）

- **现象**：数据提供方法存在，测试却报 `ArgumentCountError: 0 required, 1 passed`（或提供器永不执行）。
- **处理**：注解风格 `/** @dataProvider xProvider */` 在 PHPUnit 10+ 已弃用、12 不识别；改为方法上方 `#[DataProvider('xProvider')]`（`use PHPUnit\Framework\Attributes\DataProvider;`）。

## 28. phpunit.xml 引用空 testsuite 目录会让 CI PHPUnit 启动即 exit 2（P5.5 CI 首次接入，2026-10-18）

- **现象**：P5.5 步骤 4 推送 `.github/workflows/tests.yml` 起，6 次 CI run 全红。Setup PHP / Composer install / Prepare application（含 `php artisan key:generate`）全绿，仅 `Run tests` 步骤 1 秒 failure，annotations 仅一行 `Process completed with exit code 2`。**本地 `php artisan test` 全套 157 项却全绿**——症状与代码、断言、swoole 扩展、PHP 版本、composer 依赖均无关。
- **根因**：[phpunit.xml](../phpunit.xml) 定义 `<testsuite name="Feature"><directory>tests/Feature</directory></testsuite>`，但本地 `tests/Feature/` 目录**存在却为空**（Laravel 安装骨架自带）。Git 不跟踪空目录 → push 后 CI checkout 不存在该目录 → PHPUnit 12 启动时解析 testsuite 引用立即 fatal 退出，exit 2、0 测试执行。本地能跑纯粹因为本地目录在（PHPUnit 扫到 0 个 `*Test.php` 不报错），属于"环境差异伪装成代码问题"。
- **诊断路径**：①公共 API `/actions/runs` 看到所有 run 全 failure 且 ~35s 秒退；②`/actions/runs/{id}/jobs` steps 元数据显示只有 `Run tests` 1s failure，其余步骤全 success——锁定问题在 PHPUnit 启动而非应用 boot；③`/check-runs/{id}/annotations` 拿到 `exit code 2`（PHPUnit 文档：0=全过、1=有 failure/error、2=配置/启动错误）；④`git ls-files tests/` 对比 phpunit.xml 四个 testsuite 目录，发现 `tests/Feature` 在 git 中 0 个文件。日志 API `/actions/jobs/{id}/logs` 需 admin 权限拿不到时，steps + annotations 已足够定位。
- **处理**：`tests/Feature/.gitkeep` 占位让 git 跟踪空目录，文件内注释说明根因与"后续添加首个 Feature 测试时可删除"。修复后 CI 双 job（PHP 8.4 / SQLite + Redis、PHP 8.4 / MySQL 8.4 + Redis）`Run tests` 各 4-6s 全绿（Ubuntu + SQLite `:memory:` + RefreshDatabase migrate 一次仅几十毫秒，比 Windows 本地快得多，4-6s 跑完全套合理）。
- **教训**：①**空 testsuite 目录必须放 `.gitkeep`**——凡是 phpunit.xml、composer.json autoload、`.env.example` 等配置里引用的目录，要么确保有文件被 git 跟踪，要么从配置中移除；②CI 秒退 + exit 2 ≠ 测试失败，优先怀疑 phpunit.xml / bootstrap / 路径引用，而不是代码；③本地 vs CI 环境差异中，"Git 不跟踪空目录"是极易忽视的盲区——本地能跑不代表 CI 能跑；④GitHub Actions 公共 API 不需授权即可查 runs/jobs/steps/annotations，无 gh CLI 时可用 `Invoke-RestMethod` 直接诊断。

## 环境类备忘（P5.5 步骤 0 补充）

- phpunit.xml 的 XML 注释中**不能出现 `--`**（XML 规范）：在注释里写命令示例 `--group=redis` 会导致整套测试报 `Comment must not contain '--' (double-hyphen)`，注释里用「group=redis」等不含双连字符的写法。
- 本机 ServBay 面板未启动时可直接拉二进制做测试：`D:\ServBay\packages\redis\redis-server.exe --port 6379 --save "" --appendonly no`（注意 `D:\ServBay\bin\redis-server.cmd` 包装指向的 `packages\redis\current\` 目录不存在，直接调 exe）；MySQL 用 `mysqld.exe --defaults-file=D:\ServBay\etc\mysql\current\my.ini --console`（datadir 即 ServBay 正式数据，测试只动独立的 `crypto_saas_test` 库），停库用 mysqladmin 长选项（PowerShell 会把 `-h127.0.0.1` 错拆成 host `12``）。

## 环境类备忘

- PowerShell 不支持 heredoc（`<<'EOF'`），git commit 多行信息用 `@'...'@` here-string。
- Windows/WSL 双 clone 同步：Windows 提交后 push 失败时，用 tar 打包 `app/ config/ database/` 解到 WSL clone（脚本模式避免 wsl 嵌套引号）；脚本文件先 `tr -d '\r'` 去掉 CRLF。
- `wsl -- bash -c "..."` 嵌套两层坑（P2 实测）：① `$(...)` 与内层双引号会被 PowerShell 吞/改写，`php -r '...'` 直接语法错误——临时 PHP 一律用 Write 工具写 Windows 文件再 `cp` 进 WSL 执行（坑 6 同源）；② `pkill -f 'market:feed'` 会匹配到**承载这条命令的 bash -c 自身命令行**（里面就含字面 `market:feed`）而自杀，退出码 15；排查进程用 `pgrep -af '[m]arket:f[e]ed'`（正则不匹配自身字面量），杀进程拆成独立命令、且命令行中不出现未加括号的目标串。

## 29. 骨架缺 config/broadcasting.php 时 broadcast() 静默失败，被 Listener try/catch 吞掉（B7 前端订阅接入，2026-10-18）

- **现象**：`BroadcastBreakerAlert` 早就 `broadcast(new BreakerAlert(...))`，事件/频道授权齐备，但前端从未收到任何推送。
- **根因**：`composer create-project` 骨架不自带 `config/broadcasting.php`（需 `install:broadcasting` 生成）。缺文件时 `broadcasting.connections.reverb` 为 null，`broadcast()` 直接抛「connection not defined」；而 Listener 按设计 try/catch 只记 warning（不影响状态迁移），异常被完全吞掉，表现为「什么都不发生」。
- **处理**：补标准 `config/broadcasting.php`（reverb/log/null 三连接）。`/broadcasting/auth` 路由无需手挂——`bootstrap/app.php` 的 `withRouting(channels:)` 会自动 `Broadcast::routes()`。
- **教训**：「广播不生效」先查 `config/broadcasting.php` 是否存在、`BROADCAST_CONNECTION` 是否指向已配置连接；容错的 try/catch 会把配置缺失伪装成「静默无事件」，验收时必须看 Reverb 侧连接日志或前端实际收帧。

## 30. Blade `@json` 指令解析不了数组内的嵌套函数调用括号，截断后 PHP ParseError（B7 前端注入，2026-10-18）

- **现象**：视图里写 `window.__tenantAlerts = @json(['key' => (string) config('broadcasting...', ''), ...])`，运行时抛 `Unclosed '[' ... does not match ')'`；`php artisan view:cache` 却编译成功。
- **根因**：Blade 指令的正则只支持有限层级的嵌套括号匹配，`@json([... config('...', '') ...])` 中 `config()` 的闭合 `)` 被当成 `@json` 自身的结尾，数组从中间截断。view:cache 的批量编译与运行时编译路径不同（运行时经 Livewire ExtendBlade 处理），因此「编译通过」不等于「运行时可用」。
- **处理**：复杂数组一律在 `@php` 块预计算成变量，再用 `{{ \Illuminate\Support\Js::from($var) }}` 输出（Js::from 产出 `JSON.parse('{...}')` 且实现 Htmlable 不被二次转义）。断言其输出时注意引号被转成 `\u0022`。
- **教训**：Blade 指令参数里只放「变量/字面量」级表达式；凡含函数调用的复合结构都先 @php 落地为变量。
