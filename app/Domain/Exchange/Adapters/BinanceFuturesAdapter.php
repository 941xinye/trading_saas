<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Adapters;

use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\LiquidationEvent;
use App\Domain\Exchange\Signing\BinanceRequestSigner;
use App\Domain\Exchange\Signing\RequestSigner;
use Illuminate\Support\Facades\Log;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as WsClient;
use Throwable;

/**
 * 币安合约（U 本位永续）适配器。
 *
 * REST: https://fapi.binance.com
 * WS:   wss://fstream.binance.com/ws
 *
 * P5 范围：只实现 subscribeLiquidationStream（公开强平流 !forceOrder@arr，无需凭据）。
 * 其余 REST/下单接口属 P6+ 范围，调用时抛 LogicException 提示超出 P5 边界。
 *
 * 与 BinanceSpotAdapter.subscribeKlineStream 范式完全对称：
 *  - Coroutine::create + while(true) 指数退避重连
 *  - 每个 branch 显式 Coroutine::isCanceled() 检查（坑2：cancel 非 sticky）
 *  - runLiquidationWsLoop 单次连接循环，断开抛异常由外层重连
 *  - recv 30s heartbeat_timeout 主动重连
 *
 * 载荷结构（Binance forceOrder）：
 *   {e:'forceOrder', E:<eventTs>, o:{s,symbol; S,side; ap,avgPrice; z,filledQty; T,tradeTs}}
 *   单流模式（wss://fstream.binance.com/ws/!forceOrder@arr）直接返回事件体（无外层 stream/data 包裹）。
 */
class BinanceFuturesAdapter extends AbstractExchangeAdapter
{
    private int $subCounter = 0;

    /** @var array<string,array{cid:int,streams:string}> subscriptionId → 协程信息 */
    private array $subscriptions = [];

    protected function exchangeCode(): string
    {
        return 'binance';
    }

    protected function market(): string
    {
        return 'futures';
    }

    /**
     * 签名策略（FR-008 迁移）：与 BinanceSpotAdapter 同一策略（P6+ 下单接口启用时生效）。
     */
    protected function requestSigner(): RequestSigner
    {
        return new BinanceRequestSigner();
    }

    /**
     * Binance 用标准 HTTP 状态码传错误（4xx/5xx），HTTP 200 响应体无业务错误码结构（现状保持）。
     */
    protected function extractErrorCode(array $responseBody): ?string
    {
        return null;
    }

    protected function translateErrorCode(string $rawCode, int $httpStatus): string
    {
        return str_starts_with($rawCode, '-') ? 'client' : 'server';
    }

    public function withCredentials(array $credentials): static
    {
        return new static($this->rateLimiter, $this->healthChecker, $credentials);
    }

    /**
     * 强平流是公开市场数据，必须用主网 fstream（testnet 现货 ws 端点不支持合约 forceOrder@arr）。
     * 即使 EXCHANGE_BINANCE_TESTNET=true（P1 现货 testnet 联调），合约强平流仍走主网。
     */
    protected function wsBaseUrl(): string
    {
        $url = config('exchange.exchanges.binance.futures_ws_url');

        return is_string($url) && $url !== '' ? $url : 'wss://fstream.binance.com/ws';
    }

    // ------------------------------------------------------------------
    // WebSocket
    // ------------------------------------------------------------------

    /**
     * 订阅全市场强平流（!forceOrder@arr，无需凭据）。
     *
     * 单流模式：完整 path = /ws/!forceOrder@arr（覆盖父类 wsBaseUrl 返回的 /ws 路径）。
     *
     * @param callable(LiquidationEvent):void $onLiquidation 强平事件回调
     */
    public function subscribeLiquidationStream(callable $onLiquidation): string
    {
        $stream = '!forceOrder@arr';
        $subscriptionId = 'binance-futures-' . (++$this->subCounter) . '-' . uniqid();

        $cid = Coroutine::create(function () use ($stream, $subscriptionId, $onLiquidation): void {
            $backoff = (int) config('exchange.ws_reconnect.initial_delay', 1);
            $maxBackoff = (int) config('exchange.ws_reconnect.max_delay', 60);
            $heartbeatTimeout = (int) config('exchange.ws_reconnect.heartbeat_timeout', 30);

            while (true) {
                // 踩坑（P1 POC）：Coroutine::cancel 非 sticky——recv 失败/异常被下方
                // catch(Throwable) 吞掉后循环会重连，协程永远退不出去。必须在每个
                // 分支显式检查 isCanceled() 并 return（配合 unsubscribeStream）。
                if (Coroutine::isCanceled()) {
                    return;
                }

                try {
                    $this->runLiquidationWsLoop($stream, $onLiquidation, $heartbeatTimeout);
                    $backoff = 1;
                } catch (Coroutine\CancelException) {
                    return;
                } catch (Throwable $e) {
                    if (Coroutine::isCanceled()) {
                        return;
                    }

                    Log::warning('Binance liquidation WS disconnected, reconnecting', [
                        'subscription' => $subscriptionId,
                        'stream' => $stream,
                        'error' => $e->getMessage(),
                        'backoff' => $backoff,
                    ]);
                }

                if (Coroutine::isCanceled()) {
                    return;
                }

                Coroutine::sleep($backoff);
                if (Coroutine::isCanceled()) {
                    return;
                }
                $backoff = min($backoff * 2, $maxBackoff);
            }
        });

        $this->subscriptions[$subscriptionId] = ['cid' => $cid, 'streams' => $stream];

        return $subscriptionId;
    }

    public function unsubscribeStream(string $subscriptionId): void
    {
        $sub = $this->subscriptions[$subscriptionId] ?? null;
        if ($sub === null) {
            return;
        }

        unset($this->subscriptions[$subscriptionId]);
        if (Coroutine::exists($sub['cid'])) {
            Coroutine::cancel($sub['cid']);
        }
    }

    /**
     * 单次 WS 连接循环（断开时抛异常，由外层退避重连）。
     *
     * @param callable(LiquidationEvent):void $onLiquidation
     */
    private function runLiquidationWsLoop(string $stream, callable $onLiquidation, int $heartbeatTimeout): void
    {
        $url = $this->wsBaseUrl(); // wss://fstream.binance.com/ws
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw new \RuntimeException("Invalid WS URL: {$url}");
        }

        $ssl = ($parts['scheme'] ?? 'wss') === 'wss';
        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? ($ssl ? 443 : 80));
        // 单流模式：path = /ws/{stream}，覆盖父类 wsBaseUrl 的 /ws
        $path = '/ws/' . $stream;

        $client = new WsClient($host, $port, $ssl);
        $client->set([
            'timeout' => $heartbeatTimeout,
            'keep_alive' => true,
        ]);

        if (! $client->upgrade($path)) {
            throw new \RuntimeException("WS upgrade failed: {$host}:{$port}{$path} err={$client->errCode}");
        }

        while (true) {
            $frame = $client->recv($heartbeatTimeout);
            if ($frame === false) {
                throw new \RuntimeException("WS recv timeout/disconnect: err={$client->errCode}");
            }

            if ($frame->opcode !== WEBSOCKET_OPCODE_TEXT || $frame->data === null || $frame->data === '') {
                continue;
            }

            $payload = json_decode((string) $frame->data, true);
            if (! is_array($payload)) {
                continue;
            }

            // forceOrder 事件：{e:'forceOrder', E, o:{...}}
            if (($payload['e'] ?? '') !== 'forceOrder') {
                continue;
            }

            $o = $payload['o'] ?? null;
            if (! is_array($o)) {
                continue;
            }

            try {
                $event = LiquidationEvent::fromBinancePayload($o, (int) ($payload['E'] ?? 0));
                // 跳过空事件（symbol/price/qty 缺失）
                if ($event->symbol === '' || bccomp($event->price, '0', 8) !== 1) {
                    continue;
                }
                $onLiquidation($event);
            } catch (Throwable $e) {
                // 单事件回调失败不阻断主循环
                Log::warning('Liquidation callback failed', [
                    'symbol' => $o['s'] ?? '',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // P5 范围外的接口（属 P6+，调用即抛）
    // ------------------------------------------------------------------

    public function subscribeKlineStream(string $symbol, string $interval, callable $onBar, ?callable $onTick = null): string
    {
        throw new \LogicException('Binance futures kline stream is out of P5 scope. Use spot adapter or wait for P6+.');
    }

    public function getTicker(string $symbol): \App\Domain\Exchange\Contracts\ExchangeTicker
    {
        throw new \LogicException('Binance futures REST (getTicker) is out of P5 scope. Wait for P6+.');
    }

    public function getKline(string $symbol, string $interval, int $limit = 500): array
    {
        throw new \LogicException('Binance futures REST (getKline) is out of P5 scope. Wait for P6+.');
    }

    public function getBalance(): \App\Domain\Exchange\Contracts\ExchangeBalance
    {
        throw new \LogicException('Binance futures REST (getBalance) is out of P5 scope. Wait for P6+.');
    }

    public function placeOrder(
        string $symbol,
        string $side,
        string $type,
        string $quantity,
        ?string $price = null,
        ?string $stopPrice = null,
        array $opts = [],
    ): ExchangeOrder {
        throw new \LogicException('Binance futures placeOrder is out of P5 scope. Wait for P6+.');
    }

    public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool
    {
        throw new \LogicException('Binance futures cancelOrder is out of P5 scope. Wait for P6+.');
    }

    public function getOrder(string $symbol, string $orderId): ExchangeOrder
    {
        throw new \LogicException('Binance futures getOrder is out of P5 scope. Wait for P6+.');
    }
}
