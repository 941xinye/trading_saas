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

## 环境类备忘

- PowerShell 不支持 heredoc（`<<'EOF'`），git commit 多行信息用 `@'...'@` here-string。
- Windows/WSL 双 clone 同步：Windows 提交后 push 失败时，用 tar 打包 `app/ config/ database/` 解到 WSL clone（脚本模式避免 wsl 嵌套引号）；脚本文件先 `tr -d '\r'` 去掉 CRLF。
