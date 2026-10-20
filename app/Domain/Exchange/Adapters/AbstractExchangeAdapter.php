<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Exchange\Exceptions\ExchangeApiException;
use App\Domain\Exchange\ExchangeHealthChecker;
use App\Domain\Exchange\RateLimiter;
use App\Domain\Exchange\Signing\RequestSigner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 交易所适配器抽象基类。
 *
 * 子类（BinanceSpotAdapter / OkxAdapter / BybitAdapter 等）通过两个抽象点接入差异：
 *  - 签名策略 requestSigner()：交易所签名差异内聚在 Signing/*RequestSigner（FR-008）
 *  - 业务错误码 extractErrorCode()/translateErrorCode()：原始码→client/server 二分类（FR-009）
 * 父类提供公共能力：
 *  - REST 调用统一模板（三家共用）：签名策略 → 限流 → HTTP → 健康埋点 → 异常分类
 *  - testnet/prod URL 解析（config('exchange.exchanges.{code}.testnet')）
 *  - API 凭据注入（StrategyRunner 从 exchange_accounts 解密后传入）
 *
 * HTTP 客户端说明：Laravel Http(Guzzle curl handler) 在 Swoole 协程容器内
 * 由 SWOOLE_HOOK_CURL 自动协程化（market:feed 入口调用
 * Swoole\Runtime::enableCoroutine），无需单独封装。
 *
 * 不使用 static 缓存（Octane 长驻）。
 */
abstract class AbstractExchangeAdapter implements ExchangeAdapter
{
    public function __construct(
        protected readonly RateLimiter $rateLimiter,
        protected readonly ExchangeHealthChecker $healthChecker,
        protected readonly array $credentials = [], // ['api_key' => .., 'api_secret' => ..]
    ) {}

    /**
     * 当前适配器对应的交易所代码（binance|okx|bybit|bitget）。
     */
    abstract protected function exchangeCode(): string;

    /**
     * 当前市场 spot|futures（URL 切换用）。
     */
    abstract protected function market(): string;

    // ------------------------------------------------------------------
    // REST 模板
    // ------------------------------------------------------------------

    /**
     * 公共 GET 请求（无需签名）。
     *
     * @return array<string,mixed>
     */
    protected function httpGet(string $path, array $query = [], int $weight = 1): array
    {
        return $this->doRequest('GET', $path, $query, signed: false, weight: $weight);
    }

    /**
     * 签名请求（GET/POST/DELETE）。
     *
     * @return array<string,mixed>
     */
    protected function httpSigned(string $method, string $path, array $query = [], int $weight = 1): array
    {
        return $this->doRequest($method, $path, $query, signed: true, weight: $weight);
    }

    /**
     * REST 调用统一模板（三家完全共用，FR-005/FR-008）：
     * 签名策略 → 限流 → HTTP → 健康埋点 → 异常分类。
     *
     * signed 请求委托 requestSigner() 策略产出 SignedRequest：
     *  - GET 走 query 通道（base+path+'?'+query）；POST/DELETE 走 body 通道（withBody）。
     *  - 序列化由策略负责，且与实际发送串逐字节一致（"签名串=发送串"）。
     *  - 策略内守卫（凭据缺失等）先于限流抛出，不占额度（FR-007/constitution V）。
     *
     * unsigned 请求走 httpHeaders()（子类可重写）+ query/form_params 通道。
     *
     * @return array<string,mixed>
     */
    protected function doRequest(string $method, string $path, array $query, bool $signed, int $weight): array
    {
        $exchange = $this->exchangeCode();
        $method = strtoupper($method);
        $endpoint = "{$method} {$path}";

        // 签名策略前置：凭据缺失等守卫在限流前抛出（FR-007：前置失败不占额度）
        $signedRequest = $signed
            ? $this->requestSigner()->signRequest(
                $method,
                $path,
                $query,
                null,
                $this->credentials,
                $this->currentTimestampMs(),
            )
            : null;

        if (! $this->rateLimiter->tryAcquire($exchange, $endpoint, $weight)) {
            throw ExchangeApiException::rateLimited($exchange, $endpoint);
        }

        $base = $this->restBaseUrl();
        $startedAt = microtime(true);
        $status = 'ok';
        $error = null;

        try {
            if ($signedRequest !== null) {
                $url = $base.$path;
                if (is_string($signedRequest->query) && $signedRequest->query !== '') {
                    $url .= '?'.$signedRequest->query;
                }

                $request = Http::timeout(10)->withHeaders($signedRequest->headers);
                $response = $signedRequest->body !== null
                    ? $request
                        ->withBody($signedRequest->body, $signedRequest->contentType ?? 'application/x-www-form-urlencoded')
                        ->send($method, $url)
                    : $request->send($method, $url);
            } else {
                $response = Http::timeout(10)->withHeaders($this->httpHeaders())->send(
                    $method,
                    $base.$path,
                    $method === 'GET' ? ['query' => $query] : ['form_params' => $query],
                );
            }

            if ($response->failed()) {
                $status = $response->serverError() ? 'down' : 'degraded';
                $error = $response->body();
                // HTTP 4xx 参数/签名类错误：硬失败；5xx/网络：可重试
                throw $response->serverError()
                    ? ExchangeApiException::serverError($exchange, $endpoint, $response->body())
                    : ExchangeApiException::clientError($exchange, $endpoint, $response->status(), $response->body());
            }

            $json = $response->json() ?? [];

            // 业务错误（FR-009）：部分交易所 HTTP 200 + body 错误码（OKX code / Bybit retCode）
            $rawCode = $this->extractErrorCode($json);
            if ($rawCode !== null) {
                $message = $this->businessErrorMessage($rawCode, $json);
                $status = 'degraded';
                $error = $message;
                throw $this->translateErrorCode($rawCode, (int) $response->status()) === 'server'
                    ? ExchangeApiException::serverError($exchange, $endpoint, $message)
                    : ExchangeApiException::clientError($exchange, $endpoint, 400, $message);
            }

            return $json;
        } catch (ExchangeApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            $status = 'down';
            $error = $e->getMessage();

            throw ExchangeApiException::networkError($exchange, $endpoint, $e->getMessage());
        } finally {
            // 健康记录写库失败不能掩盖正在传播的原始交易所异常
            // （finally 内抛异常会替换 try 中待抛出的异常）
            try {
                $this->healthChecker->record($exchange, $endpoint, (int) ((microtime(true) - $startedAt) * 1000), $status, $error);
            } catch (Throwable $recordError) {
                Log::warning('Exchange health record failed', [
                    'exchange' => $exchange,
                    'endpoint' => $endpoint,
                    'error' => $recordError->getMessage(),
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // 签名策略与业务错误抽象点（FR-008/FR-009）
    // ------------------------------------------------------------------

    /**
     * 签名策略（FR-008）：差异内聚在 Signing/*RequestSigner，策略纯函数可独立单测。
     * 默认抛 LogicException（未接入签名策略的适配器不支持签名请求）。
     */
    protected function requestSigner(): RequestSigner
    {
        throw new \LogicException(static::class.' does not support signed requests');
    }

    /**
     * 从 HTTP 200 响应体中抽取业务错误码（FR-009）。
     * 返回 null 表示无业务错误（响应体直接透传）。
     *
     * @param  array<string,mixed>  $responseBody
     */
    abstract protected function extractErrorCode(array $responseBody): ?string;

    /**
     * 业务错误码二分类（FR-009）：'client'（硬失败不重试）| 'server'（可重试）。
     */
    abstract protected function translateErrorCode(string $rawCode, int $httpStatus): string;

    /**
     * 业务错误消息（子类可附加提示，如 Bybit 10002 时钟校准提示）。
     *
     * @param  array<string,mixed>  $responseBody
     */
    protected function businessErrorMessage(string $rawCode, array $responseBody): string
    {
        return "code={$rawCode} msg=".(string) ($responseBody['msg'] ?? ($responseBody['retMsg'] ?? ''));
    }

    /**
     * 请求头（仅 unsigned 请求用；signed 请求头由签名策略产出）。
     *
     * @return array<string,string>
     */
    protected function httpHeaders(): array
    {
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        if (! empty($this->credentials['api_key'])) {
            $headers['X-MBX-APIKEY'] = (string) $this->credentials['api_key'];
        }

        return $headers;
    }

    /**
     * 本地时钟毫秒（签名用；与交易所时钟漂移超 recvWindow 时上层应同步服务器时间）。
     */
    protected function currentTimestampMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * 按 clientOrderId 查单的默认实现：未支持该查询方式的交易所直接抛异常，
     * 由崩溃恢复逻辑按「网络异常」保守处理（pending 保持阻塞）。
     * BinanceSpot/Okx/Bybit 已实装；其余交易所随对应策略落地时补。
     */
    public function getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder
    {
        throw new \RuntimeException(static::class.' does not support getOrderByClientOrderId');
    }

    /**
     * REST 基础 URL（testnet 开关自动切换）。
     * 合约 testnet 使用独立的 futures_testnet_rest_url（Binance 合约 testnet 域名与现货不同）。
     */
    protected function restBaseUrl(): string
    {
        $code = $this->exchangeCode();
        $cfg = "exchange.exchanges.{$code}.";
        $isFutures = $this->market() === 'futures';

        if ($this->isTestnet()) {
            $url = $isFutures
                ? config($cfg.'futures_testnet_rest_url')
                : config($cfg.'testnet_rest_url');

            return is_string($url) && $url !== '' ? $url : config($cfg.($isFutures ? 'futures_rest_url' : 'rest_url'));
        }

        $url = config($cfg.($isFutures ? 'futures_rest_url' : 'rest_url'));

        return is_string($url) ? $url : '';
    }

    /**
     * WebSocket 基础 URL（testnet 开关自动切换）。
     * 合约 testnet 使用独立的 futures_testnet_ws_url。
     */
    protected function wsBaseUrl(): string
    {
        $code = $this->exchangeCode();
        $cfg = "exchange.exchanges.{$code}.";
        $isFutures = $this->market() === 'futures';

        if ($this->isTestnet()) {
            $url = $isFutures
                ? config($cfg.'futures_testnet_ws_url')
                : config($cfg.'testnet_ws_url');

            return is_string($url) && $url !== '' ? $url : config($cfg.($isFutures ? 'futures_ws_url' : 'ws_url'));
        }

        $url = config($cfg.($isFutures ? 'futures_ws_url' : 'ws_url'));

        return is_string($url) ? $url : '';
    }

    private function isTestnet(): bool
    {
        return (bool) config('exchange.exchanges.'.$this->exchangeCode().'.testnet', false);
    }

    // ------------------------------------------------------------------
    // 依赖注入便捷方法（供 ExchangeManager 使用）
    // ------------------------------------------------------------------

    /**
     * 以新凭据克隆当前适配器（同配置、不同账户）。
     *
     * @param  array<string,string>  $credentials
     */
    abstract public function withCredentials(array $credentials): static;

    // ------------------------------------------------------------------
    // 默认实现：现货无持仓
    // ------------------------------------------------------------------

    public function getPosition(string $symbol): ?ExchangePosition
    {
        return null;
    }
}
