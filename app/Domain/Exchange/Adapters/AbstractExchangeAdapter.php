<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Exceptions\ExchangeApiException;
use App\Domain\Exchange\ExchangeHealthChecker;
use App\Domain\Exchange\RateLimiter;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 交易所适配器抽象基类。
 *
 * 子类（BinanceSpotAdapter / OkxAdapter 等）实现具体 HTTP/WS 行为，
 * 父类提供公共能力：
 *  - REST 调用模板（限流前置 → HTTP → 健康埋点 → 异常分类）
 *  - 签名模板方法（HMAC，按交易所差异重写 signQuery()）
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
    ) {
    }

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
     * REST 调用统一模板：限流 → HTTP → 健康埋点 → 异常分类。
     *
     * @return array<string,mixed>
     */
    private function doRequest(string $method, string $path, array $query, bool $signed, int $weight): array
    {
        $exchange = $this->exchangeCode();
        $endpoint = "{$method} {$path}";

        if (! $this->rateLimiter->tryAcquire($exchange, $endpoint, $weight)) {
            throw ExchangeApiException::rateLimited($exchange, $endpoint);
        }

        if ($signed) {
            $query = $this->applySignature($query);
        }

        $base = $this->restBaseUrl();
        $startedAt = microtime(true);
        $status = 'ok';
        $error = null;

        try {
            $response = Http::timeout(10)
                ->withHeaders($this->httpHeaders())
                ->send($method, $base . $path, $method === 'GET' ? ['query' => $query] : ['form_params' => $query]);

            $latency = (int) ((microtime(true) - $startedAt) * 1000);

            if ($response->failed()) {
                $status = $response->serverError() ? 'down' : 'degraded';
                $error = $response->body();
                // 4xx 参数/签名类错误：硬失败；5xx/网络：可重试
                throw $response->serverError()
                    ? ExchangeApiException::serverError($exchange, $endpoint, $response->body())
                    : ExchangeApiException::clientError($exchange, $endpoint, $response->status(), $response->body());
            }

            return $response->json() ?? [];
        } catch (ExchangeApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            $status = 'down';
            $error = $e->getMessage();

            throw ExchangeApiException::networkError($exchange, $endpoint, $e->getMessage());
        } finally {
            $this->healthChecker->record($exchange, $endpoint, (int) ((microtime(true) - $startedAt) * 1000), $status, $error);
        }
    }

    /**
     * 注入签名参数（timestamp + signature）。子类按交易所差异重写。
     *
     * @param array<string,mixed> $query
     *
     * @return array<string,mixed>
     */
    protected function applySignature(array $query): array
    {
        $query['timestamp'] = $this->currentTimestampMs();
        $query['recvWindow'] = (int) config('exchange.recv_window', 5000);
        $query['signature'] = $this->signQuery($this->buildQuery($query));

        return $query;
    }

    /**
     * Binance 风格：HMAC SHA256(queryString, apiSecret) → hex。
     */
    protected function signQuery(string $queryString): string
    {
        return hash_hmac('sha256', $queryString, (string) ($this->credentials['api_secret'] ?? ''));
    }

    /**
     * 稳定 query 序列化（按 key 升序，URL encode）。
     *
     * @param array<string,mixed> $query
     */
    protected function buildQuery(array $query): string
    {
        ksort($query);

        return http_build_query($query);
    }

    /**
     * 请求头。Binance 用 X-MBX-APIKEY，子类可重写。
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
     * REST 基础 URL（testnet 开关自动切换）。
     */
    protected function restBaseUrl(): string
    {
        $code = $this->exchangeCode();
        $cfg = "exchange.exchanges.{$code}.";

        if ($this->isTestnet()) {
            $url = config($cfg . 'testnet_rest_url');

            return is_string($url) && $url !== '' ? $url : config($cfg . 'rest_url');
        }

        $url = config($cfg . ($this->market() === 'futures' ? 'futures_rest_url' : 'rest_url'));

        return is_string($url) ? $url : '';
    }

    /**
     * WebSocket 基础 URL（testnet 开关自动切换）。
     */
    protected function wsBaseUrl(): string
    {
        $code = $this->exchangeCode();
        $cfg = "exchange.exchanges.{$code}.";

        if ($this->isTestnet()) {
            $url = config($cfg . 'testnet_ws_url');

            return is_string($url) && $url !== '' ? $url : config($cfg . 'ws_url');
        }

        $url = config($cfg . ($this->market() === 'futures' ? 'futures_ws_url' : 'ws_url'));

        return is_string($url) ? $url : '';
    }

    private function isTestnet(): bool
    {
        return (bool) config('exchange.exchanges.' . $this->exchangeCode() . '.testnet', false);
    }

    // ------------------------------------------------------------------
    // 依赖注入便捷方法（供 ExchangeManager 使用）
    // ------------------------------------------------------------------

    /**
     * 以新凭据克隆当前适配器（同配置、不同账户）。
     *
     * @param array<string,string> $credentials
     */
    abstract public function withCredentials(array $credentials): static;

    // ------------------------------------------------------------------
    // 默认实现：现货无持仓
    // ------------------------------------------------------------------

    public function getPosition(string $symbol): ?\App\Domain\Exchange\Contracts\ExchangePosition
    {
        return null;
    }
}
