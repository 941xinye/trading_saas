# 踩坑记录（P1–spec 008 实测，共 47 条，按领域分组）

> 共 47 条，按领域分组。原始编号保留在标题中便于交叉引用。

---

## 环境/协程/Swoole

### 1. Swoole curl hook 与 Guzzle 7.9 不兼容（#1，P1）

- **现象**：REST 请求实际成功但整体抛 ErrorException；`market:feed` 启动即报 warning。
- **根因**：Guzzle 7.9 在 handle release 时调 `curl_setopt(CURLOPT_PREREQFUNCTION, null)`，Swoole hook 的 `swoole_curl_setopt` 不支持该 option（ERR 20312），warning 被 Laravel 转为 ErrorException。
- **规避**：
  - `Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL)`，REST 走阻塞 curl（单实例场景每次 <1s 可接受），WS 走 Swoole 原生 socket 协程不受影响
  - 位置：`app/Console/Commands/MarketFeedCommand.php`、`app/Console/Commands/PocVerifyFeedCommand.php`

### 2. Coroutine::cancel 不是 sticky 的（#2，P1）

- **现象**：WS 断开重连循环 `catch(Throwable)` 吞掉取消异常后继续重连，协程永不退出。
- **根因**：`Coroutine::cancel()` 通过异常中断阻塞调用，但若异常被捕获且未退出，协程会继续跑。
- **规避**：
  - 重连循环的各分支显式 `Coroutine::isCanceled()` 检查并 `return`（配合 `unsubscribeStream`）
  - 位置：`app/Domain/Exchange/Adapters/BinanceSpotAdapter.php`

### 6. Swoole 协程化 PDO/Redis 连接被多协程并发复用（#6，P1）

- **现象**：`market:feed` 收到若干 bar 后 fatal `Socket#N has already been bound to another coroutine`（PDO->prepare 处），事件循环终结，命令以 exit 0 退出。
- **根因**：`SWOOLE_HOOK_ALL` 把 PDO/phpredis 的 TCP 连接协程化；实例保活协程每 5s 查 DB，WS 协程 onBar 内也访问 DB/Redis，Swoole 不允许两个协程同时读写同一条 socket。
- **规避**：
  - `Runtime::enableCoroutine(SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_CURL & ~SWOOLE_HOOK_TCP & ~SWOOLE_HOOK_UNIX & ~SWOOLE_HOOK_UDG)`，PDO/phpredis 退化为阻塞 IO，天然串行
  - 多实例必须上连接池（每协程独占连接）
  - 位置：`app/Console/Commands/MarketFeedCommand.php`

### 16. 一条 `bash -c` 里用 `&& ... &` 连启两个长驻进程：第二个在错误 cwd 启动即死（#16，P2）

- **现象**：`cd app && nohup market:feed > log & nohup queue:work > log &` 连启后几秒两个 pgrep 都查到，几分钟后 worker 已死、日志文件没生成，Job 静默堆积。
- **根因**：bash 中 `&&` 优先级高于 `&`，第一条被整体后台化在子 shell 里执行，其中的 `cd` 不影响父 shell；第二条 nohup 在 `$HOME` 启动，相对重定向路径不存在、`artisan` 不在 cwd，进程秒退。
- **规避**：
  - 每个长驻进程用独立命令启动（各自完整 `cd ~/trading_saas && nohup ... &`），启动后立刻 `pgrep` + `tail` 日志双确认
  - 位置：运维流程坑，无代码变更

### 22. BinanceFuturesAdapter 必须覆写 `wsBaseUrl()` 强制主网（#22，P5）

- **现象**：`poc:liquidation-demo` 启动即 `WS upgrade failed: stream.testnet.binance.vision:9443/ws/!forceOrder@arr err=104`。
- **根因**：父类 `wsBaseUrl()` 检测 testnet 开关返回现货 testnet URL，但 `!forceOrder@arr` 是合约强平流，testnet 现货 WS 根本没有这个 stream。
- **规避**：
  - BinanceFuturesAdapter 覆写 `wsBaseUrl()`，强制返回 `wss://fstream.binance.com/ws`（读 `config('exchange.exchanges.binance.futures_ws_url')`，缺省回退硬编码主网）
  - 位置：`app/Domain/Exchange/Adapters/BinanceFuturesAdapter.php`

### 23. WSL 出口网络对 Binance 主网端点不可达（testnet 可达）（#23，P5）

- **现象**：覆写 `wsBaseUrl()` 用主网后，`fstream.binance.com:443` / `api.binance.com:443` 均 ECONNREFUSED；但 `stream.testnet.binance.vision:9443` / `github.com:443` 均可通。
- **根因**：出口公网 IP（动态宽带）可能被 Binance 主网地理封禁，或 ISP 层封禁 Binance 主网 IP 段。
- **规避**：
  - P5 接受离线 POC 断言 PASS 作为验证通过，跳过真实 fstream 实跑；`poc:liquidation-demo` 命令保留，网络恢复后随时补测
  - 正式部署需配置代理或部署在境外节点
  - 位置：环境坑，无代码变更

---

## PSR-4 / PHP 语言 / 基类对齐

### 3. 同文件多类/接口声明破坏 PSR-4（#3，P1）

- **现象**：非 `composer dump-autoload -o` 环境下报 "Interface not found"。
- **根因**：KeyResolver 接口曾放在 AesGcmCrypt.php 文件底部，PSR-4 按文件名映射找不到接口。
- **规避**：
  - 所有类/接口必须独立成文件，一文件一类
  - 位置：`app/Domain/Tenancy/TenantKeyResolver.php`（独立文件）

### 4. `php -l` 只查语法，查不出 use 导入缺失（#4，P1）

- **现象**：`php -l` 全绿，运行时报 class not found（如 ExchangeManager 未 use）。
- **根因**：静态语法检查不覆盖符号解析，`use` 导入缺失只有 autoload/运行时才报错。
- **规避**：
  - 最小验证闭环 = WSL 内 `php artisan` 实跑目标命令；不要依赖静态语法检查
  - 位置：开发流程坑，无代码变更

### 19. readonly class 不能 use 含非只读属性的 trait（#19，P4）

- **现象**：`Error: Readonly class BreakerAlert cannot use trait InteractsWithSockets`（trait 有非只读属性 `$socket`）。
- **根因**：PHP 8.3 readonly class 禁止引入可变属性，Laravel `InteractsWithSockets` trait 声明 `public $socket = null`（非只读），与 readonly 语义冲突。
- **规避**：
  - 从 BreakerAlert 移除 `InteractsWithSockets` trait，仅保留 `use Dispatchable;`，broadcastOn() 直接返回 PrivateChannel
  - 位置：`app/Events/BreakerAlert.php`

### 36. AbstractStrategy 用 self::SCHEMA_VERSION 导致子类多态失效（#36，spec 005 WS4）

- **现象**：FuturesGridStrategy::getState() 返回 schemaVersion=1，而非定义的 2。
- **根因**：AbstractStrategy 中 `self::SCHEMA_VERSION` 是编译期绑定，始终取基类的 1；子类 const 2 被忽略。
- **规避**：
  - 全部改为 `static::SCHEMA_VERSION`（后期静态绑定）
  - 位置：`app/Domain/Strategy/Base/AbstractStrategy.php`

### 38. 实现类与接口同名导致 PHP 类声明冲突（#38，spec 005 WS6）

- **现象**：`php artisan poc:verify-futures` 报 `Cannot redeclare class PositionRiskMonitor`。
- **根因**：实现类 `App\Domain\Risk\Position\PositionRiskMonitor` 与接口 `App\Domain\Risk\Contracts\PositionRiskMonitor` 同名，use 引入后类声明 `implements PositionRiskMonitor` 产生命名冲突。
- **规避**：
  - use 时加别名 `use ...PositionRiskMonitor as PositionRiskMonitorContract`，类声明 `implements PositionRiskMonitorContract`
  - 位置：`app/Domain/Risk/Position/PositionRiskMonitor.php`

### 42. PHP 8.5 起 `ReflectionProperty::setAccessible()` 已弃用（no-op）（#42，spec 006 WS6）

- **现象**：DslEquivalenceTest 用反射读私有属性时 PHPUnit 报 3 条 deprecation。
- **根因**：PHP 8.1+ 反射访问私有/保护属性不再需要 `setAccessible(true)`，该调用在 8.5 标记为 deprecated。
- **规避**：
  - 删除所有 `$prop->setAccessible(true)`，直接 `$prop->getValue($instance)`
  - 位置：测试代码（反射处）

---

## Laravel Redis 签名 / 连接层

### 13. Laravel Redis 连接层 `eval()` 签名不是 predis 原生形态（#13，P3）

- **现象**：TwoPhaseMeter 首跑 `eval($script, [$key, ...$args], 1)` 直接 fatal：`Argument #3 ($num_keys) must be of type int, array given`。
- **根因**：Laravel 的 `PhpRedisConnection::eval($script, $numberOfKeys, ...$arguments)` 把 numkeys 放第 2 参、keys/argv 展平为可变参；predis 形态 `eval($script, $keysAndArgsArray, $numkeys)` 在 Laravel 连接层不成立。
- **规避**：
  - 统一写 `eval($script, 1, $key, (string) $limit, (string) $ttl)`（phpredis/predis 两种 connector 通用），数值参数字符串化交给 Lua `tonumber`
  - 位置：`app/Domain/Billing/Metering/TwoPhaseMeter.php`、`app/Domain/Tenancy/TenantKeyResolver.php`

### 21. phpredis `zAdd` 签名是 `($key, $score, $member)`，不是 predis 的关联数组形态（#21，P5）

- **现象**：`Redis::zAdd() expects at least 3 arguments, 2 given`。
- **根因**：Laravel Redis facade 默认走 phpredis connector，`zAdd($key, $score, $member, ...)` 是可变参展平形态；predis 风格 `zAdd($key, [$member => $score])` 被 phpredis 当作单个 array 参数，参数数=2 不满足 ≥3 要求。
- **规避**：
  - 统一用 `Redis::zadd($zsetKey, (float) $e->tsMs, $eid)`（phpredis 形态）；批量用 pipeline 逐条或展平 `zadd($key, $s1, $m1, $s2, $m2, ...)`
  - 写 Redis 调用前确认 `.env` 的 `REDIS_CLIENT`
  - 位置：`app/Domain/Liquidation/LiquidationClusterer.php`

### 26. phpredis 全局前缀下，`keys()` 返回的是带前缀全名，再 `del()` 会拼出双重前缀（#26，P5.5）

- **现象**：迁移测试用 `Redis::keys('tenant:x:*')` 拿 key 再逐个 `del`，首跑后 dedup 键残留，第二次运行 0 行通知。
- **根因**：`config/database.php` 的 phpredis 配了全局前缀，`keys()` 返回的完整名再传给 `del()`，客户端又拼一次前缀 → 删的是不存在的键。
- **规避**：
  - 测试清理一律按已知逻辑名精确 `del('tenant:...')`（前缀由客户端拼一次）；模式扫描时对返回值先 strip `Redis::getOption(Redis::OPT_PREFIX)` 再删
  - 位置：测试清理代码

### 43. Laravel Redis `set()` 的 NX/EX 参数位写反导致单飞锁与去重静默失效（#43，spec 005）

- **现象**：PositionRiskMonitorTest 长期 4 红——单飞锁竞争用例期望 skipped 实得 reduce；15min 去重用例期望派发 1 次实得 2 次。
- **根因**：`PhpRedisConnection::set($key, $value, $expireResolution, $expireTTL, $flag)` 把选项重排成具名参数；原实现 `set($key, '1', 'NX', 'EX', $ttl)` 让 `'NX'` 落在 `expireResolution` 位、`$ttl` 落在 `flag` 位 → NX 标志被吞，SET 退化为无条件覆盖。
- **规避**：
  - 改为 `set($key, $value, 'EX', $ttl, 'NX')`（flag 永远放最后），与 `TenantKeyResolver` / `DedupCooldown` 写法对齐
  - 负向并发测试（先抢占、再断言被拒）才能发现这类"吞标志"的静默 bug
  - 位置：`app/Domain/Risk/Position/PositionRiskMonitor.php`

---

## Redis / DB 测试隔离

### 24. 测试 setUp 里 skip 后 tearDown 的外部清理抛异常会污染 RefreshDatabase 事务（#24，P5.5）

- **现象**：本机 Redis 未启动跑全套，首个 `@group redis` 测试已正确 skip，但后续同文件其余 6 个测试以及另一个测试类的 3 个 mysql 组测试全部连锁报 `SQLSTATE[HY000]: cannot start a transaction within a transaction`，10 个测试级联失败。
- **根因**：tearDown 无条件执行 Redis flush（外部 IO），Redis 不可用再抛异常——skip 发生在 `parent::setUp()`（RefreshDatabase 已 BEGIN 事务）之后，tearDown 异常导致框架没走到事务回滚；SQLite 内存连接事务一直开着，后续每个测试 BEGIN 都失败。
- **规避**：
  - 外部服务清理在 tearDown 中必须满足三条：a) 用 `$redisAvailable` 标志位包住；b) 再套 try/catch 兜底；c) 清理放在 `parent::tearDown()` 之前
  - 服务可用性判定统一收敛到 `Tests\Concerns\ChecksRedisAvailability`
  - 位置：测试基类 / 清理代码

### 25. 带 TTL 的业务去重键会让"全套连跑"与"单跑"结果不同（#25，P5.5）

- **现象**：`ReconciliationServiceTest` 单文件全绿，并入全套后 `critical_does_not_override_breaker_paused` 稳定失败——期望 ≥2 行 risk_notifications，实际 0 行。
- **根因**：对账告警走 `DedupCooldown`（TTL 15 分钟），同文件前一个用例用同一租户/实例/同一 critical 指纹先占了槽位；后一个用例的告警被判 deduped 不再落库。单跑（或 Redis 刚 flush）时键不存在所以绿，Redis 有持久化残留时连跑必挂。
- **规避**：
  - 测试 setUp 和 tearDown 都按已知逻辑名精确清理本组用到的全部 dedup 键 + unknown 计数键（清理仍套 #24 的标志位 + try/catch）
  - 凡是测试间共享、生产语义带 TTL 的键都照此办理，不能依赖"测试数据的 ID 不一样"
  - 位置：`tests/` 下相关 Test 文件

---

## Binance 签名 / 网络

### 7. 交易所错误处理写库失败会掩盖原始 REST 错误（#7，P1）

- **现象**：下单返回 401/403，但日志只剩 PDO fatal，看不到交易所错误码。
- **根因**：REST 失败 → `ExchangeHealthChecker::record()` 写库 → 写库本身又因坑 6 抛异常，原始 `ExchangeApiException` 被覆盖。
- **规避**：
  - 脱离框架用最小 curl 直连交易所接口（testnet 签名 GET /api/v3/account），区分「key 本身无效（-2015）」与「签名错误（-1022）」
  - Binance Spot Testnet 与 Futures Testnet 的 key 不通用；testnet 账号会定期重置，key 失效后需重新生成
  - 位置：异常处理链路排查

### 8. 签名串与发送串不一致导致 Binance -1022（#8，P1）

- **现象**：新 testnet key 已通过 GET /api/v3/account 验证，但策略 POST /api/v3/order 一律返回 `400 -1022 Signature for this request is not valid`。
- **根因**：签名时 `buildQuery()` 先 `ksort()` 再 `http_build_query()`，而发送时 Guzzle `form_params` 按数组插入顺序编码 body。Binance 用实际收到的 raw query/body（去掉 signature）验签，签名串与发送串参数顺序不同 → HMAC 不匹配。GET 参数少且手工 curl 构造时串一致，掩盖了该问题。
- **规避**：
  - 签名请求统一生成确定性 payload 字符串（`ksort` 后序列化 → HMAC → 追加 `&signature=`），GET 拼到 URL、POST/DELETE 作为 `withBody($payload, application/x-www-form-urlencoded)` 原始 body，保证「参与签名的串」与「实际发送的串」逐字节一致
  - `-2015`（key 识别层）通过后才会到 `-1022`（签名层）
  - 位置：`app/Domain/Exchange/Adapters/AbstractExchangeAdapter.php`

---

## Job / 队列

### 10. Laravel 13 Job 重复声明 `$queue` 属性 fatal（#10，P2）

- **现象**：骨架期桩文件从未被加载没暴露；P2 真正 `dispatch()` 时报 fatal：`TypeError/define the same property ... definition differs incompatible`。
- **根因**：Laravel `Queueable` trait 内部已声明 `$queue`（默认 null），子类用固定默认值再声明同名属性与 trait 定义不兼容（PHP 8.3+ 对 trait/类同名属性检查更严）。
- **规避**：
  - Job 类内不再声明属性，改为构造函数中 `$this->onQueue('ai'|'notifications'|'aggregation')`；需要延迟/中间件同理用链式方法，不碰属性
  - 位置：`app/Jobs/AiAnalyzeJob.php`、`app/Jobs/DispatchNotificationJob.php`

### 12. `queue:work` 启动期 Redis 瞬时拒绝连接即退出，不做自愈（#12，P2）

- **现象**：与 `market:feed` 用 `&` 同 shell 连启 worker，日志报 `RedisException Connection refused` 后进程直接死亡；几秒后 Redis 实际可通，但 worker 不会自己回来，队列积压。
- **根因**：queue worker 启动时建连失败按致命错误退出（与运行期命令重试不同）；POC 手工 nohup 启动没有任何保活。
- **规避**：
  - 正式期必须配 supervisor/systemd 的 `startretries` + `autorestart=true`（部署清单）
  - 慢调用（AI 30s）与通知严格分队列 `ai`/`notifications`，worker 独立于行情协程进程
  - 位置：运维部署配置

---

## 聚合 / 数据完整性

### 11. 进程中途启动产生"残次聚合桶"：缺 ticks 计数的 5m bar 也会闭合投递（#11，P2）

- **现象**：`market:feed` 在 17:14 才连上 WS，17:16 闭合的 17:10 桶只聚到 1 根 1m bar，照样写了 `kline_bars`（trades=36，正常 200+）并触发 AI 分析。
- **根因**：原 BarAggregator 只做"openTime 取整 + OHLCV 累加 + Redis 恢复"，不区分一个桶收齐了几根 1m；半道 hydrate 出来的状态天然缺腿，却与完整桶走同一条闭合路径。
- **规避**：
  - 桶状态增加 `ticks` 计数与 `EXPECTED_TICKS`（5m=5/15m=15/1h=60/4h=240/1d=1440）
  - Runner 闭合时 `lastClosedTicks() < expected` 只记 WARNING 跳过（**不写 kline_bars、不投 AiAnalyzeJob**），宁可漏一轮也不分析残桶
  - 崩溃恢复只解决状态不丢，不解决状态本身不完整；任何基于周期闭合的聚合都必须同时校验周期内样本数
  - 位置：`app/Domain/Signal/BarAggregator.php`、`app/Domain/Signal/SignalFeedRunner.php`

---

## 风控 / 熔断

### 17. RiskGuard.check(OPEN) 未调 attemptProbe：OPEN→HALF_OPEN 永不转换（#17，P4）

- **现象**：`poc:verify-risk` trip 后 state=OPEN ✓，冷却到期后 check('open') 期望转 HALF_OPEN 但 state 仍=OPEN，后续 recover/事件计数/命令幂等全连锁失败。
- **根因**：RiskGuard.check() 原逻辑 `if (state === OPEN) return decide(false, 'breaker_open')` 直接拒绝，不调用 `attemptProbe()`。而 `CircuitBreaker.attemptProbe()` 本身已处理 OPEN 状态下冷却到期→HALF_OPEN 的转换，形成死锁。
- **规避**：
  - OPEN 分支改为 `return breaker.attemptProbe() ? decide(true, 'probe_allowed') : decide(false, 'breaker_open')`——冷却到期则自动转 HALF_OPEN 并放行探测单
  - 三态机中"冷却到期自动降级"的转换点必须在每次 check 时主动尝试，不能假设外部触发
  - 位置：`app/Domain/Risk/RiskGuard.php`

### 18. 测试绕过 RiskGuard.recordTrade 直接调 metrics()：Redis last_trade_date 未更新（#18，P4）

- **现象**：断言 10（崩溃恢复 lastTradeDate 注入）失败——跨日后 Redis `last_trade_date` 未更新为今日。
- **根因**：测试代码直接操作 `BreakerMetrics` 内存对象，绕过了 `RiskGuard.recordTrade()` 内 Redis 写入链路。metrics 内存状态已更新但 Redis Hash 未同步。
- **规避**：
  - 测试改为走完整写入路径：`$guard->recordTrade(instanceId, makeOrder('sell', '1', '98.5'))`
  - 测试持久化字段时必须走 Domain 层公开方法，不能绕过中间层直接操作内部状态对象
  - 位置：`app/Console/Commands/PocVerifyRiskCommand.php`

### 34. 保证金率公式与阈值语义不一致导致所有仓位直接 emergency_close（#34，spec 005 WS3）

- **现象**：PositionRiskMonitor 对任何有持仓的仓位都返回 emergency_close，BTC 10x 多头入场即触发全平。
- **根因**：原 marginRatio 公式 `MMR × entryPrice / markPrice` 实际是"维持保证金占当前市值比例"，与"距强平价剩余缓冲比例"语义不符；入场时 ratio=0.004，远低于 emergency 阈值 0.10。
- **规避**：
  - 改为 `(markPrice - liqPrice) / (entryPrice - liqPrice)` [long]，入场=1.0，强平=0.0，与三档阈值（warning 0.50/action 0.20/emergency 0.10）对齐
  - 风控阈值的语义必须与计算公式的语义严格对齐
  - 位置：`app/Domain/Risk/Breaker/IsolatedLiquidationPriceCalculator.php`

### 35. 1x 杠杆强平价期望值易算错（#35，spec 005 WS3）

- **现象**：1x 多头强平价测试期望值写成 100.5，实际为 0.5。
- **根因**：1x 杠杆下 `1/leverage = 1`，多头公式 `entry × (1 - 1 + MMR) = entry × MMR = 100 × 0.005 = 0.5`，不是 `entry × (1 + MMR)`。
- **规避**：
  - 1x 多头 = entry × MMR；1x 空头 = entry × (2 - MMR)
  - 杠杆=1 时公式退化为边界情况，手算期望值容易符号搞反，必须用 bcmath 实际复算后再写断言
  - 位置：强平价计算器测试

---

## 计费

### 14. 并发配额测试要从"干净桶"开始（#14，P3）

- **现象**：`poc:verify-billing` 第 3 段"10 进程抢 3 配额"首次只放行 2 个，Lua 逻辑本身经审查无竞争漏洞。
- **根因**：日配额桶按 `tenant+event+UTC 日` 复用，第 2 段两阶段断言结束时桶内已有 1（settled 占额不归还）；第 3 段从计数 1 起跑，limit=3 自然只有 2 个 INCR 名额。不是并发安全问题，是夹具隔离问题。
- **规避**：
  - 每段断言只对自己的 ref 前缀（如 `c3-*`）做断言，并在段首 `flushDayCounter()` 明确桶的起点
  - 共享 Redis 计数的测试，"前置状态"与"被测行为"同等重要；DB 行可以靠 ref 隔离，热计数桶必须显式重置
  - 位置：`app/Console/Commands/PocVerifyBillingCommand.php`

### 15. 离线验证不能伪造 `signals.subscription_id`：FK 约束只认真实行（#15，P3）

- **现象**：验证 pipeline 兜底分支时用 stdClass 伪造订阅（id=900001），指标/兜底全对，落库瞬间被外键约束打回。
- **根因**：`signals.subscription_id` 引用 `signal_subscriptions.id`，伪造 stdClass 不满足 DB FK。
- **规避**：
  - 插入真实的 `active=0` 脚手架订阅行，pipeline 用其真实 id；active=0 不被 SignalFeedRunner 加载、不计入资源数，用完随租户级联清理
  - 位置：`app/Console/Commands/PocVerifyBillingCommand.php`

---

## 崩溃恢复

### 9. hydrate 顺序错误导致崩溃恢复丢失持仓/网格状态（#9，P1）

- **现象**：`kill -9` 强杀 `market:feed` 后重启，SpotGridStrategy 的持仓 `grids`、pending 格、`last_index` 全部丢失，重启后第一根 bar 就用空内存覆盖了 Redis state。
- **根因**：`onBar()` 先调 `isConfigured()`，首次执行会把 `$gridStep` 从初始 `'0'` 算成实际步长；随后 `hydrateFromSnapshot()` 的守卫是 `positionSnapshot===null || gridStep!=='0' → return`，此刻 `gridStep` 已非 `'0'`，恢复快照被永久跳过——用业务字段兼任"是否已初始化"标志位导致的顺序耦合 bug。
- **规避**：
  - 新增独立的 `$hydrated` 一次性标志位取代借用 `gridStep` 判断；把 `hydrateFromSnapshot()` 移到 `isConfigured()` 之前，直接从快照恢复 `gridStep/lastIndex/grids`
  - 崩溃恢复必须用 `SIGKILL`（不给 flush/信号清理机会）+ 状态注入做端到端验证
  - 恢复逻辑的幂等守卫要用独立标志，不要复用会在正常流程中被改写的业务字段
  - 位置：`app/Domain/Strategy/Spot/SpotGridStrategy.php`

---

## Filament

### 20. Filament Widget `$view` 父类声明非 static，子类不能改 static（#20，P5）

- **现象**：`Error: Cannot redeclare non static Filament\Widgets\Widget::$view as static ... LiquidationMapWidget::$view`。
- **根因**：Filament v5 父类 `Widget` 声明 `protected string $view`（实例属性），子类声明成 `protected static string $view` 与父类定义冲突。
- **规避**：
  - 子类改为 `protected string $view = 'filament.platform.widgets.liquidation-map'`（非 static），与父类声明形态一致
  - 覆写框架基类属性时先看父类声明形态（static/非 static、可见性），子类必须严格对齐
  - 位置：`app/Filament/Platform/Widgets/LiquidationMapWidget.php`

### 41. Filament v5 无 `Filament\Tables\Actions\Action`，统一用 `Filament\Actions\Action`（#41，spec 006 WS5）

- **现象**：StrategyInstanceResource 原 `use Filament\Tables\Actions\Action;` 在 v5 下类不存在（`vendor/filament/tables/src/Actions/` 仅 `HeaderActionsPosition.php`），页面渲染时类找不到。
- **根因**：Filament v5 将 Action 统一收口到 `Filament\Actions\Action`，`Tables\Actions\Action` 别名已移除。
- **规避**：
  - 表单组件与表格行 action 统一 `use Filament\Actions\Action;`，`Action::make(...)` 同时适用于 `Schema::components([...])` 与 `Table::actions([...])`
  - Filament 大版本升级时，`Tables\Actions\*` / `Forms\Components\Actions\*` 的命名空间变动是高频断点，升级前先核对 vendor 目录实际存在的类
  - 位置：`app/Filament/` 下相关 Resource/Widget

---

## Broadcasting / B7 前端

### 29. 骨架缺 config/broadcasting.php 时 broadcast() 静默失败（#29，B7）

- **现象**：`BroadcastBreakerAlert` 已 `broadcast(new BreakerAlert(...))`，事件/频道授权齐备，但前端从未收到任何推送。
- **根因**：`composer create-project` 骨架不自带 `config/broadcasting.php`（需 `install:broadcasting` 生成）。缺文件时 `broadcasting.connections.reverb` 为 null，`broadcast()` 直接抛「connection not defined」；而 Listener 按设计 try/catch 只记 warning，异常被完全吞掉。
- **规避**：
  - 补标准 `config/broadcasting.php`（reverb/log/null 三连接）；`/broadcasting/auth` 路由无需手挂——`bootstrap/app.php` 的 `withRouting(channels:)` 会自动 `Broadcast::routes()`
  - 「广播不生效」先查 config 是否存在、`BROADCAST_CONNECTION` 是否指向已配置连接；容错的 try/catch 会把配置缺失伪装成「静默无事件」
  - 位置：`config/broadcasting.php`

### 30. Blade `@json` 指令解析不了数组内的嵌套函数调用括号（#30，B7）

- **现象**：视图里写 `@json(['key' => (string) config('broadcasting...', ''), ...])`，运行时抛 `Unclosed '[' ... does not match ')'`；`php artisan view:cache` 却编译成功。
- **根因**：Blade 指令的正则只支持有限层级的嵌套括号匹配，`config()` 的闭合 `)` 被当成 `@json` 自身的结尾，数组从中间截断。view:cache 的批量编译与运行时编译路径不同，因此「编译通过」不等于「运行时可用」。
- **规避**：
  - 复杂数组一律在 `@php` 块预计算成变量，再用 `{{ \Illuminate\Support\Js::from($var) }}` 输出（Js::from 产出 `JSON.parse('{...}')` 且实现 Htmlable 不被二次转义）
  - Blade 指令参数里只放「变量/字面量」级表达式；凡含函数调用的复合结构都先 @php 落地为变量
  - 位置：前端注入视图文件

---

## ClickHouse 归档

### 31. 行哈希选型：MD5 前 16 hex 同构方案胜出（#31，P8）

- **现象**：归档三级 verify 的 L2 需要「主库侧逐行哈希累加」与「CH 侧 SQL 聚合」结果严格一致（bcmath 字符串精度）。初选 cityHash64 因 PHP 侧不可复算被否；MD5 全 128bit 转十进制 bcmath 可算但 CH 侧 sum 溢出 UInt128 语义差异大。
- **根因**：跨系统一致性校验的哈希函数，选型第一约束是「两侧可同构复算」，其次才是性能；cityHash64 需 PHP 扩展或 FFI，破坏零依赖原则。
- **规避**：
  - 采用 MD5 hex 前 16 位（64bit）——PHP `RowNormalizer`（hexdec 大数 → bcadd 累加 → bcmod 2^64）与 CH 侧 `sumWithOverflow(reinterpretAsUInt64(reverse(substring(MD5(concat(...)),1,8))))` 严格同构
  - CH 真机四连炸修订：`toUInt64OrZero` 无 base 参数；CH 26 `MD5()` 返回 16 字节二进制而非 hex；`sum()` 整数溢出饱和须用 `sumWithOverflow`；Decimal 列须 `toDecimalString()` 保标度
  - 位置：`app/Domain/Archive/Stores/RowNormalizer.php`、`ClickHouseStore::hashSum`

### 32. ClickHouse lightweight delete 即时可见性（#32，P8）

- **现象**：copy 幂等锚是「DELETE FROM ... WHERE batch_id = ?（lightweight delete，CH 22.8+）清半途行 → 立即重插」。若删除对后续 SELECT 非即时可见，重跑后 `countByBatch` 会翻倍。
- **根因**：需验证 lightweight delete 的即时可见性假设——跨存储的「先删后插」幂等模式，删除可见性是核心假设。
- **规避**：
  - `ClickHouseStoreIntegrationTest::test_kline_roundtrip_and_lightweight_delete_visibility` 真机 PASS（CH 26.11.1.155），deleteByBatch 后同进程 countByBatch 立即返回 0；备选轮询方案弃用
  - 把该假设写成显式测试断言，而不是埋在设计文档里
  - 位置：归档集成测试

### 33. passed 批次被复检降级 failed 后自动窗口够不到（#33，P8）

- **现象**：`archive:verify` 把某 passed 批次降级为 failed 后（演练中人为删 CH 一行），直接 `archive:run kline_bar` 报 0 批处理——该 failed 批次时间片位于 `copied_watermark` 之前，自动窗口从水位起向前扫永远够不到，`--apply` 也因 verify_status=failed 拒删，批次卡死。
- **根因**：设计内行为而非缺陷——failed 不推进水位是防数据丢失的正确语义，窗口只向前扫是增量归档的前提；定点恢复通道就是 `--from`。
- **规避**：
  - `php artisan archive:run kline_bar --from="<批次 time_from>"` 复位重跑（failed→pending→重 copy→重 verify），passed 后再 `--apply`
  - 三级校验分层：rows 级只是「账目对平」；内容级真校验在 hash_sum 与抽样逐位
  - 位置：`app/Console/Commands/` 归档命令

---

## DSL 引擎

### 37. shouldOpen 消费信号后 pendingSignal=null 导致 attachClientOrderId 提前返回（#37，spec 005 WS4）

- **现象**：shouldOpen 返回信号后调用 attachClientOrderId，但 pendingFills 始终为空，onFill 无法匹配。
- **根因**：shouldOpen 内部调用 consumeSignal 将 pendingSignal 置 null 并返回信号；attachClientOrderId 原本读 pendingSignal 取 reduce_only，此时已为 null。
- **规避**：
  - 参考 SpotGridStrategy 的 lastSignalMeta 模式——consumeSignal 时保存元数据到 lastSignalMeta，attachClientOrderId 从 lastSignalMeta 读取
  - 信号"消费"与"下单元数据"是两个步骤，消费后必须保留元数据供后续 attach 使用
  - 位置：`app/Domain/Strategy/Futures/FuturesGridStrategy.php`

### 39. DSL 等价测试中 DCA 的 filledAt 必须沿 fixture 时间线（#39，spec 006 WS6）

- **现象**：DslEquivalenceTest 中 DCA 首单成交后，后续所有 bar 不再产出信号（`shouldOpen` 恒 null），与 DSL 序列不匹配。
- **根因**：SpotDcaStrategy 用 `filledAt ?? intdiv(lastBarTime, 1000)` 推进 `lastOrderAt`，再以 `closeSec - lastOrderAt >= intervalSeconds` 判断；测试 fixture 的 bar 时间戳是小整数（如 100000ms → closeSec=100），而 `filledAt=time()` 是真实 Unix 秒（~1.7e9），导致 `closeSec - lastOrderAt` 恒为负。
- **规避**：
  - 模拟成交时 `filledAt = intdiv($bar->closeTime, 1000)`，与 fixture 时间线一致
  - 时间驱动策略的测试，成交回报的时间戳必须与 bar 时间戳同一坐标系
  - 位置：DSL 等价性测试

### 40. DSL 四段（filter/entry/exit/risk）全部必需，不用的段也要写永不触发的占位条件（#40，spec 006 WS6）

- **现象**：为 DCA 等价性写的 DSL 脚本只含 filter/entry/risk（DCA 不需要平仓），`DslParser::parse()` 抛「缺少 exit 段」。
- **根因**：DslCompiler 强制四段齐全（FR-002，禁止静默降级），即使某段语义上不用也必须声明。
- **规避**：
  - 补 exit 段，用永不成立的条件占位（如 `op: gt, left: const 0, right: const 1`），保证编译通过但运行期不产出平仓信号
  - DSL 的"四段必需"是编译期硬约束，写最小可编译脚本时不能省略任何段
  - 位置：`app/Domain/Strategy/DSL/`

---

## 回测引擎

### 44. Laravel 命令自动发现只扫 `app/Console/Commands`（#44，spec 008 WS7）

- **现象**：若把命令放在 `app/Domain/Backtest/Console/`，`php artisan poc:verify-backtest` 不会出现在命令列表，`Artisan::call()` 同样找不到。
- **根因**：Laravel 11/13 的默认命令发现路径由 `bootstrap/app.php` 的 `withCommands()` 决定，只扫描 `app/Console/Commands`；领域目录不会自动注册。
- **规避**：
  - 落到 `app/Console/Commands/PocVerifyBacktestCommand.php`，与既有 POC 命令同目录；领域命令如确需留在领域目录，必须在 `bootstrap/app.php` 显式 `withCommands([...])` 注册
  - tasks.md 的路径是设计建议，落地前先确认框架的发现机制
  - 位置：`bootstrap/app.php`（如需显式注册）

### 45. bcmath 截断与契约表展示值不一致：win_rate 2/3 是 66.66666666 不是 66.66666667（#45，spec 008 WS2/WS7）

- **现象**：POC C 组按契约表断言 `winRatePct = 66.66666667`，实际得到 `66.66666666`。
- **根因**：实现走 `bcmul(bcdiv($wins, $count, 12), '100', 8)`——bcmath 全链路截断（非四舍五入）；契约表里的 `66.66666667` 是人读的四舍五入展示值。
- **规避**：
  - 断言与落库统一以截断口径 `66.66666666` 为准，在绩效契约注明「展示值四舍五入、存储值截断」
  - constitution II 的 bcmath 约定意味着所有百分比都是截断值；凡文档写「黄金向量」时，必须显式区分展示口径与落账口径
  - 位置：`app/Domain/Backtest/Services/PerformanceCalculator.php`

### 46. 回测净值未扣手续费：费率翻倍收益不降（#46，spec 008 US4）

- **现象**：`BacktestAssumptionsTest::test_higher_fee_rate_increases_total_fee_and_lowers_return` 红——`total_fee` 随费率上升，但 `final_equity` / 收益率纹丝不动。
- **根因**：EquityCurveBuilder 只聚合 realizedPnl + unrealized，MatchingEngine 算出的 fee 仅写进明细与报告汇总，未参与净值计算。
- **规避**：
  - EquityCurveBuilder 增加 `$cumulativeFee` + `addFee()`，`mark()` 中 `bcsub($equity, $cumulativeFee, 12)`；BacktestRunner 每笔 fill 后注入 fee，reset() 清零
  - 费率/滑点必须端到端穿透到净值；用「费率翻倍 → 收益必须下降」这类单调性断言才能抓住"统计有、净值无"的半接线状态
  - 位置：`app/Domain/Backtest/Services/EquityCurveBuilder.php`

### 47. 构造零方差 Sharpe 向量不能用递增净值（#47，spec 008 WS7）

- **现象**：POC C 组零方差向量 `[1000, 1100, 1210]` 期望 `sharpeRatio = null`，实得非 null。
- **根因**：日收益序列以 `initialCapital` 为 prev 计算首点，`[1000, 1100, 1210]` 产出 `0%, 10%, 10%`——首日 0 收益让 float 方差非零，`stddev > 0` 故 Sharpe 有值。
- **规避**：
  - 零方差向量改为恒定净值 `[1000, 1000, 1000]`（三个收益样本全 0），与 `PerformanceCalculatorTest::test_golden_vector_zero_variance_sharpe_null` 对齐
  - 净值曲线的首个数据点若等于初始资金，必然引入一个 0 收益样本；构造边界向量时先手算收益序列
  - 位置：`app/Domain/Backtest/Services/PerformanceCalculator.php`

---

## 环境备忘（无代码变更）

### 5. AES-GCM 依赖 sodium 扩展（#5，P1）

- **现象**：Windows CLI 环境若无 sodium 会在加密账户时失败；WSL 侧已验证可用。
- **根因**：AES-256-GCM 加解密依赖 libsodium（PHP 8.4 自带），但 Windows CLI 可能缺少该扩展。
- **规避**：
  - 确保 PHP 环境启用 sodium 扩展（PHP 8.4 默认自带）
  - 位置：`app/Support/AesGcmCrypt.php`

### 27. PHPUnit 12 不认 `@dataProvider` 注解，必须用 `#[DataProvider]` 属性（#27，P5.5）

- **现象**：数据提供方法存在，测试却报 `ArgumentCountError: 0 required, 1 passed`（或提供器永不执行）。
- **根因**：注解风格 `/** @dataProvider xProvider */` 在 PHPUnit 10+ 已弃用、12 不识别。
- **规避**：
  - 改为方法上方 `#[DataProvider('xProvider')]`（`use PHPUnit\Framework\Attributes\DataProvider;`）
  - 位置：PHPUnit 测试文件

### 28. phpunit.xml 引用空 testsuite 目录会让 CI PHPUnit 启动即 exit 2（#28，P5.5 CI）

- **现象**：推送 `.github/workflows/tests.yml` 起，6 次 CI run 全红；`Run tests` 步骤 1 秒 failure，annotations 仅一行 `Process completed with exit code 2`。本地 `php artisan test` 全套 157 项却全绿。
- **根因**：`phpunit.xml` 定义 `<testsuite name="Feature"><directory>tests/Feature</directory></testsuite>`，但本地 `tests/Feature/` 为空目录（Laravel 骨架自带）。Git 不跟踪空目录 → push 后 CI checkout 不存在该目录 → PHPUnit 12 启动时解析 testsuite 引用立即 fatal 退出。本地能跑纯粹因为本地目录在，属于"环境差异伪装成代码问题"。
- **规避**：
  - `tests/Feature/.gitkeep` 占位让 git 跟踪空目录，文件内注释说明根因与"后续添加首个 Feature 测试时可删除"
  - **凡是 phpunit.xml、composer.json autoload 等配置里引用的目录，要么确保有文件被 git 跟踪，要么从配置中移除**
  - CI 秒退 + exit 2 ≠ 测试失败，优先怀疑 phpunit.xml / bootstrap / 路径引用
  - GitHub Actions 公共 API 不需授权即可查 runs/jobs/steps/annotations，无 gh CLI 时可用 `Invoke-RestMethod` 直接诊断
  - 位置：`phpunit.xml`、`tests/Feature/.gitkeep`

---

## 杂项环境备忘

- **phpunit.xml 的 XML 注释不能出现 `--`**：XML 规范禁止注释中含双连字符；在注释里写 `--group=redis` 会导致整套测试报 `Comment must not contain '--'`，改用「group=redis」等不含双连字符的写法。
- **本机 ServBay 面板未启动时的服务拉起方式**：直接 `D:\ServBay\packages\redis\redis-server.exe --port 6379 --save "" --appendonly no`（注意 `D:\ServBay\bin\redis-server.cmd` 包装指向的 `packages\redis\current\` 目录不存在，直接调 exe）；MySQL 用 `mysqld.exe --defaults-file=D:\ServBay\etc\mysql\current\my.ini --console`（datadir 即 ServBay 正式数据，测试只动独立的 `crypto_saas_test` 库）；停库用 mysqladmin 长选项（PowerShell 会把 `-h127.0.0.1` 错拆成 host `12`）。
- **PowerShell 不支持 heredoc**：git commit 多行信息用 `@'...'@` here-string。
- **Windows/WSL 双 clone 同步**：Windows 提交后 push 失败时，用 tar 打包 `app/ config/ database/` 解到 WSL clone（脚本模式避免 wsl 嵌套引号）；脚本文件先 `tr -d '\r'` 去掉 CRLF。
- **`wsl -- bash -c "..."` 嵌套两层坑**：① `$(...)` 与内层双引号会被 PowerShell 吞/改写，临时 PHP 一律用 Write 工具写 Windows 文件再 `cp` 进 WSL 执行；② `pkill -f 'market:feed'` 会匹配到承载这条命令的 bash -c 自身命令行（里面就含字面 `market:feed`）而自杀，退出码 15；排查进程用 `pgrep -af '[m]arket:f[e]ed'`（正则不匹配自身字面量），杀进程拆成独立命令、且命令行中不出现未加括号的目标串。
