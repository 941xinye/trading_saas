# 踩坑记录（P1 POC 实测）

> 2026-10-13 在 WSL2 + Ubuntu 22.04（PHP 8.4.26 + Swoole 6.2.3）环境实测沉淀。
> 每条记录：现象 → 根因 → 处理 → 代码位置。后续阶段（P2+）踩坑持续追加到本文件。

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

## 环境类备忘

- PowerShell 不支持 heredoc（`<<'EOF'`），git commit 多行信息用 `@'...'@` here-string。
- Windows/WSL 双 clone 同步：Windows 提交后 push 失败时，用 tar 打包 `app/ config/ database/` 解到 WSL clone（脚本模式避免 wsl 嵌套引号）；脚本文件先 `tr -d '\r'` 去掉 CRLF。
- `wsl -- bash -c "..."` 嵌套两层坑（P2 实测）：① `$(...)` 与内层双引号会被 PowerShell 吞/改写，`php -r '...'` 直接语法错误——临时 PHP 一律用 Write 工具写 Windows 文件再 `cp` 进 WSL 执行（坑 6 同源）；② `pkill -f 'market:feed'` 会匹配到**承载这条命令的 bash -c 自身命令行**（里面就含字面 `market:feed`）而自杀，退出码 15；排查进程用 `pgrep -af '[m]arket:f[e]ed'`（正则不匹配自身字面量），杀进程拆成独立命令、且命令行中不出现未加括号的目标串。
