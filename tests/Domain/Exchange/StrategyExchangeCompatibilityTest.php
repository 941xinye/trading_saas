<?php

declare(strict_types=1);

namespace Tests\Domain\Exchange;

use App\Domain\Exchange\Adapters\AbstractExchangeAdapter;
use App\Domain\Exchange\Adapters\BinanceSpotAdapter;
use App\Domain\Exchange\Adapters\BitgetAdapter;
use App\Domain\Exchange\Adapters\BybitAdapter;
use App\Domain\Exchange\Adapters\OkxAdapter;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Exchange\ExchangeHealthChecker;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Exchange\RateLimiter;
use App\Domain\Strategy\Base\AbstractStrategy;
use App\Domain\Strategy\OrderExecutor;
use App\Domain\Strategy\Contracts\StrategyContext;
use App\Domain\Strategy\Contracts\StrategyState;
use App\Domain\Strategy\Spot\SpotDcaStrategy;
use App\Domain\Strategy\Spot\SpotGridStrategy;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 策略 × 交易所兼容性断言（T043，FR-011 + SC-005，001 analyze C2 收尾）。
 *
 * 用 RecordingAdapter（记录方法调用序列）驱动 spot_grid 与 spot_dca 核心
 * 决策链（镜像 StrategyRunner onBar 链：onBar → shouldOpen/shouldClose →
 * attachClientOrderId → placeOrder → onFill，崩溃恢复链：pendingClientOrderIds
 * → getOrderByClientOrderId → applyRecoveredOrder），全程离线无网络无凭据。
 *
 * 四家一致断言链：策略只依赖 ExchangeAdapter 接口（源码零具体适配器引用）
 * + ExchangeManager 四家路由返回同接口实现 + 关键方法签名与接口逐一对齐
 * → RecordingAdapter 记录的调用序列对四家实现可原样平移。
 */
#[Group('offline')]
class StrategyExchangeCompatibilityTest extends TestCase
{
    private ExchangeManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['binance', 'okx', 'bybit', 'bitget'] as $code) {
            config(["exchange.exchanges.{$code}.weight_limit_per_minute" => 0]);
        }

        $this->manager = new ExchangeManager(new RateLimiter(), new ExchangeHealthChecker());
    }

    public function test_spot_grid_drive_sequence(): void
    {
        $adapter = new RecordingAdapter();

        $strategy = new SpotGridStrategy(1, [
            'upper_price' => '110',
            'lower_price' => '100',
            'grid_count' => 10,
            'investment_amount' => '1000',
        ], $this->context());

        // 镜像 StrategyRunner onBar 决策链（Runner L246-269 + executeSignal L324-354）
        $recentBars = [];
        $emitBar = function (ExchangeKline $bar) use ($strategy, $adapter, &$recentBars): void {
            $recentBars[] = $bar;
            $strategy->onBar($bar);

            $ctx = new StrategyContext(1, $strategy->getState()->params, null, null, $recentBars, null);
            $open = $strategy->shouldOpen($ctx);
            $close = $open !== null ? null : $strategy->shouldClose($ctx);
            $signal = $open ?? $close;

            if ($signal !== null) {
                $clientOrderId = OrderExecutor::generateClientOrderId(1, 1);
                $strategy->attachClientOrderId($clientOrderId, (string) $signal['side'], (string) $signal['qty']);
                $order = $adapter->placeOrder('BTCUSDT', (string) $signal['side'], 'market', (string) $signal['qty'], null, null, [
                    'clientOrderId' => $clientOrderId,
                ]);
                $strategy->onFill($order);
            }
        };

        // bar1：区间上方（index=10），首 bar 只建立 lastIndex，不交易
        $emitBar($this->kline(1697443200000, '111'));
        // bar2：价格下行进入格 0（index 10→0）→ 空格买入信号
        $emitBar($this->kline(1697443260000, '100.5'));
        // bar3：价格上行离开格 0（index 0→1）→ 止盈卖出信号
        $emitBar($this->kline(1697443320000, '101.5'));

        // 停止实例场景：撤掉未成交挂单（接口契约：bool）
        static::assertTrue($adapter->cancelOrder('BTCUSDT', 'x-1', '1_1_1697443260_ab12cd'));

        // 崩溃恢复链（生产时序：新进程新实例 restoreState 后核实 pending 格）
        $restarted = new SpotGridStrategy(1, [
            'upper_price' => '110',
            'lower_price' => '100',
            'grid_count' => 10,
            'investment_amount' => '1000',
        ], $this->context());
        $this->restoreSnapshot($restarted, [
            'grid_step' => '1',
            'last_index' => 0,
            'grids' => ['0' => ['qty' => '0', 'cost' => '0', 'pending' => true, 'cid' => 'rec-1']],
        ]);
        $cids = $restarted->pendingClientOrderIds();
        static::assertSame(['rec-1'], $cids);
        $recovered = $adapter->getOrderByClientOrderId('BTCUSDT', (string) $cids[0]);
        static::assertNotNull($recovered);
        $restarted->applyRecoveredOrder($recovered);

        // ---- 调用序列断言：下单（含 clientOrderId 模板）/撤单/查单 ----
        $methods = array_column($adapter->calls, 'method');
        static::assertSame(
            ['placeOrder', 'placeOrder', 'cancelOrder', 'getOrderByClientOrderId'],
            $methods,
            'spot_grid 决策链 + 恢复链调用序列',
        );

        $buy = $adapter->calls[0];
        static::assertSame('buy', $buy['args']['side']);
        static::assertMatchesRegularExpression('/^1_1_\d{10}_[0-9a-f]{6}$/', $buy['args']['opts']['clientOrderId'], 'clientOrderId 符合模板 {tenant_id}_{instance_id}_{timestamp}_{random}');
        static::assertSame($buy['args']['opts']['clientOrderId'], $buy['returned']->clientOrderId, '交易所订单回传 cid 幂等锚点');
        static::assertSame('sell', $adapter->calls[1]['args']['side']);
    }

    public function test_spot_dca_drive_sequence(): void
    {
        $adapter = new RecordingAdapter();

        $strategy = new SpotDcaStrategy(1, [
            'amount_per_order' => '100',
            'interval_minutes' => 1,
            'max_orders' => 2,
        ], $this->context());

        $recentBars = [];
        $emitBar = function (ExchangeKline $bar) use ($strategy, $adapter, &$recentBars): void {
            $recentBars[] = $bar;
            $strategy->onBar($bar);

            $ctx = new StrategyContext(1, $strategy->getState()->params, null, null, $recentBars, null);
            $open = $strategy->shouldOpen($ctx);
            $close = $open !== null ? null : $strategy->shouldClose($ctx);
            $signal = $open ?? $close;

            if ($signal !== null) {
                $clientOrderId = OrderExecutor::generateClientOrderId(1, 1);
                $strategy->attachClientOrderId($clientOrderId, (string) $signal['side'], (string) $signal['qty']);
                $order = $adapter->placeOrder('BTCUSDT', (string) $signal['side'], 'market', (string) $signal['qty'], null, null, [
                    'clientOrderId' => $clientOrderId,
                ]);
                $strategy->onFill($order);
            }
        };

        // bar1：只锚定 anchor_at，不产生信号
        $emitBar($this->kline(1697443200000, '100'));
        // bar2：距锚定 120s > 60s 间隔 → 定额买入
        $emitBar($this->kline(1697443320000, '100'));

        // 崩溃恢复链（生产时序：新进程新实例 restoreState 后核实 pending_cid）
        $restarted = new SpotDcaStrategy(1, [
            'amount_per_order' => '100',
            'interval_minutes' => 1,
            'max_orders' => 2,
        ], $this->context());
        $this->restoreSnapshot($restarted, [
            'anchor_at' => 1697443200,
            'orders_count' => 0,
            'total_spent' => '0',
            'last_order_at' => null,
            'pending_cid' => 'rec-2',
        ]);
        $cids = $restarted->pendingClientOrderIds();
        static::assertSame(['rec-2'], $cids);
        $recovered = $adapter->getOrderByClientOrderId('BTCUSDT', (string) $cids[0]);
        static::assertNotNull($recovered);
        $restarted->applyRecoveredOrder($recovered);

        $methods = array_column($adapter->calls, 'method');
        static::assertSame(['placeOrder', 'getOrderByClientOrderId'], $methods, 'spot_dca 单飞决策 + 恢复核实序列');

        $buy = $adapter->calls[0];
        static::assertSame('buy', $buy['args']['side']);
        static::assertSame('market', $buy['args']['type']);
        static::assertSame('1.00000000', $buy['args']['quantity'], 'qty = amount/close = 100/100（bcmath 8 位截断）');
        static::assertMatchesRegularExpression('/^1_1_\d{10}_[0-9a-f]{6}$/', $buy['args']['opts']['clientOrderId']);
    }

    public function test_all_exchanges_share_adapter_contract(): void
    {
        $routes = [
            'binance' => BinanceSpotAdapter::class,
            'okx' => OkxAdapter::class,
            'bybit' => BybitAdapter::class,
            'bitget' => BitgetAdapter::class,
        ];

        foreach ($routes as $code => $expected) {
            $adapter = $this->manager->adapter($code, 'spot');
            static::assertInstanceOf($expected, $adapter);
            static::assertInstanceOf(ExchangeAdapter::class, $adapter);
            static::assertInstanceOf(AbstractExchangeAdapter::class, $adapter);
        }
    }

    public function test_strategy_signatures_match_interface(): void
    {
        $methods = [
            'getTicker', 'getKline', 'getBalance', 'getPosition', 'placeOrder',
            'cancelOrder', 'getOrder', 'getOrderByClientOrderId',
            'subscribeKlineStream', 'unsubscribeStream', 'subscribeLiquidationStream',
        ];

        $implementations = [
            BinanceSpotAdapter::class,
            OkxAdapter::class,
            BybitAdapter::class,
            BitgetAdapter::class,
        ];

        foreach ($implementations as $class) {
            foreach ($methods as $method) {
                $impl = new ReflectionMethod($class, $method);
                $iface = new ReflectionMethod(ExchangeAdapter::class, $method);

                static::assertSame(
                    $iface->getNumberOfParameters(),
                    $impl->getNumberOfParameters(),
                    "{$class}::{$method} 参数个数与接口一致",
                );
            }
        }
    }

    public function test_strategy_code_has_no_concrete_adapter_reference(): void
    {
        $concreteTypes = [
            'BinanceSpotAdapter', 'BinanceFuturesAdapter', 'OkxAdapter',
            'BybitAdapter', 'BitgetAdapter', 'ExchangeManager',
        ];

        foreach ([SpotGridStrategy::class, SpotDcaStrategy::class] as $strategy) {
            $file = (string) (new ReflectionClass($strategy))->getFileName();
            $source = (string) file_get_contents($file);

            foreach ($concreteTypes as $leak) {
                static::assertStringNotContainsString(
                    $leak,
                    $source,
                    "{$strategy} 不得引用具体适配器（FR-011 策略零改动契约，仅依赖 ExchangeAdapter 接口）",
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function context(): StrategyContext
    {
        return new StrategyContext(1, [], null, null, [], null);
    }

    private function kline(int $openTimeMs, string $close): ExchangeKline
    {
        return new ExchangeKline(
            exchangeCode: 'bybit',
            symbol: 'BTCUSDT',
            interval: '1m',
            openTime: $openTimeMs,
            closeTime: $openTimeMs + 59999,
            open: $close,
            high: $close,
            low: $close,
            close: $close,
            volume: '1',
            quoteVolume: '100',
            tradesCount: 1,
        );
    }

    private function restoreSnapshot(AbstractStrategy $strategy, array $snapshot): void
    {
        $strategy->restoreState(new StrategyState(
            schemaVersion: 1,
            params: [],
            positionSnapshot: $snapshot,
            realizedPnl: '0',
            consecutiveLosses: 0,
            lastBarTime: null,
        ));
    }
}

/**
 * 调用记录适配器：实现 ExchangeAdapter 全接口，按序记录方法调用与返回值。
 *
 * placeOrder 返回 filled 订单（cid 透传，onFill 幂等入账）；
 * getOrderByClientOrderId 返回 filled 订单（恢复核实路径）；
 * 其余方法记录并返回最小 DTO。
 */
final class RecordingAdapter implements ExchangeAdapter
{
    /** @var array<int,array{method:string,args:array,returned:mixed}> */
    public array $calls = [];

    private int $counter = 0;

    public function getTicker(string $symbol): ExchangeTicker
    {
        return $this->record('getTicker', [$symbol], new ExchangeTicker(
            symbol: $symbol, lastPrice: '100', bidPrice: '99.9', askPrice: '100.1',
            volume24h: '1', changePct24h: '0', timestamp: time(),
        ));
    }

    public function getKline(string $symbol, string $interval, int $limit = 500): array
    {
        return $this->record('getKline', [$symbol, $interval, $limit], []);
    }

    public function getBalance(): ExchangeBalance
    {
        return $this->record('getBalance', [], new ExchangeBalance(
            totalEquity: '0', totalMargin: '0', available: '0', unrealizedPnl: '0', assets: [],
        ));
    }

    public function getPosition(string $symbol): ?ExchangePosition
    {
        return $this->record('getPosition', [$symbol], null);
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
        $order = new ExchangeOrder(
            exchangeOrderId: 'x-'.(++$this->counter),
            clientOrderId: (string) ($opts['clientOrderId'] ?? ''),
            symbol: $symbol,
            side: $side,
            type: $type,
            quantity: $quantity,
            price: $price,
            stopPrice: $stopPrice,
            leverage: null,
            reduceOnly: false,
            status: 'filled',
            filledQty: $quantity,
            avgFillPrice: '100',
            fee: '0',
            placedAt: time(),
            filledAt: time(),
        );

        return $this->record('placeOrder', compact('symbol', 'side', 'type', 'quantity', 'opts'), $order);
    }

    public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool
    {
        return $this->record('cancelOrder', compact('symbol', 'orderId', 'clientOrderId'), true);
    }

    public function getOrder(string $symbol, string $orderId): ExchangeOrder
    {
        return $this->record('getOrder', compact('symbol', 'orderId'), $this->order($symbol));
    }

    public function getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder
    {
        return $this->record('getOrderByClientOrderId', compact('symbol', 'clientOrderId'), $this->order($symbol, $clientOrderId));
    }

    public function subscribeKlineStream(string $symbol, string $interval, callable $onBar, ?callable $onTick = null): string
    {
        return $this->record('subscribeKlineStream', [$symbol, $interval], 'sub-1');
    }

    public function unsubscribeStream(string $subscriptionId): void
    {
        $this->record('unsubscribeStream', [$subscriptionId], null);
    }

    public function subscribeLiquidationStream(callable $onLiquidation): string
    {
        return $this->record('subscribeLiquidationStream', [], 'liq-1');
    }

    private function order(string $symbol, string $clientOrderId = 'rec-1'): ExchangeOrder
    {
        return new ExchangeOrder(
            exchangeOrderId: 'x-rec',
            clientOrderId: $clientOrderId,
            symbol: $symbol,
            side: 'buy',
            type: 'market',
            quantity: '1',
            price: null,
            stopPrice: null,
            leverage: null,
            reduceOnly: false,
            status: 'filled',
            filledQty: '1',
            avgFillPrice: '100',
            fee: '0',
            placedAt: time(),
            filledAt: time(),
        );
    }

    private function record(string $method, array $args, mixed $returned): mixed
    {
        $this->calls[] = ['method' => $method, 'args' => $args, 'returned' => $returned];

        return $returned;
    }
}
