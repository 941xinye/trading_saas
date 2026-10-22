<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Domain\Billing\Metering\TwoPhaseMeter;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeBalance;
use App\Domain\Exchange\Contracts\ExchangeKline;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\Contracts\ExchangePosition;
use App\Domain\Exchange\Contracts\ExchangeTicker;
use App\Domain\Strategy\OrderExecutor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Support\RedisCleanup;
use Tests\TestCase;
use Throwable;

/**
 * OrderExecutor 配额/权益闸门（迁自 poc:verify-billing 第 6 段）。
 *
 *  - orders_per_day=0 → failed，零交易所调用（@group redis：拒绝异常 payload 读 usedToday）
 *  - suspended → failed，零交易所调用（default 组：权益门在计量之前）
 *  - 正常下单 filled 且 meter settled；对齐后数量为 0 → failed + 冲正还额（@group redis）
 */
class OrderQuotaGateTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private const TENANT_ID = 990034;

    private const EVENT = 'order_placed';

    private int $instanceId;

    /** 本轮要清的日配额桶键（逻辑名，Carbon 固定为 2026-10-15） */
    private array $redisKeys = [];

    /** tearDown 守卫：仅 redis 用例真实连过 Redis 才清理（坑 #24） */
    private bool $redisAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC'));
        $this->redisKeys = [
            sprintf('tenant:%d:meter:%s:%s', self::TENANT_ID, self::EVENT, '20261015'),
        ];

        $now = Carbon::now();
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-billing-order-990034',
            'slug' => 'poc-billing-order-990034',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid', 'name' => '现货网格', 'category' => 'spot',
            'risk_profile' => 'conservative', 'default_params' => json_encode([]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $accountId = (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => self::TENANT_ID, 'exchange_id' => $exchangeId, 'label' => 'poc',
            'market' => 'spot', 'encrypted_api_key' => 'k', 'encrypted_api_secret' => 's',
            'dek_version' => 1, 'permissions' => json_encode(['spot']), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->instanceId = (int) DB::table('strategy_instances')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'strategy_id' => $strategyId,
            'exchange_account_id' => $accountId,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => json_encode([]),
            'allocated_capital' => '100',
            'status' => 'stopped',
            'started_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->redisAvailable) {
            try {
                $this->cleanupRedis($this->redisKeys);
            } catch (Throwable) {
                // key TTL 2 天且用高位隔离租户 ID
            }
        }
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    #[Group('redis')]
    public function test_zero_daily_quota_fails_order_without_exchange_call(): void
    {
        // 注意：freezeDaily 构造 QuotaExceededException 时要读 usedToday（Redis GET），
        // limit<=0 虽在 INCR 前短路，但异常 payload 仍触 Redis，故归 redis 组。
        $this->requireRedis();
        $this->redisAvailable = true;
        $this->cleanupRedis($this->redisKeys);

        config(['plans.plans.free.features.orders_per_day' => 0]);

        $adapter = $this->countingAdapter();
        $order = app(OrderExecutor::class)->place(
            self::TENANT_ID,
            $this->instanceRow(),
            $adapter,
            ['side' => 'buy', 'qty' => '0.01', 'price' => '100'],
        );

        static::assertSame('failed', $order->status);
        static::assertSame(0, $adapter->calls, '配额拒绝路径不得调用交易所');
    }

    public function test_suspended_tenant_fails_order_without_exchange_call(): void
    {
        DB::table('tenants')->where('id', self::TENANT_ID)->update(['status' => 'suspended']);

        try {
            $adapter = $this->countingAdapter();
            $order = app(OrderExecutor::class)->place(
                self::TENANT_ID,
                $this->instanceRow(),
                $adapter,
                ['side' => 'buy', 'qty' => '0.01', 'price' => '100'],
            );

            static::assertSame('failed', $order->status);
            static::assertSame(0, $adapter->calls, '权益拒绝路径不得调用交易所');
        } finally {
            DB::table('tenants')->where('id', self::TENANT_ID)->update(['status' => 'active']);
        }
    }

    #[Group('redis')]
    public function test_filled_order_settles_meter_and_zero_alignment_reverses_quota(): void
    {
        $this->requireRedis();
        $this->redisAvailable = true;
        $this->cleanupRedis($this->redisKeys);

        $meter = app(TwoPhaseMeter::class);
        $executor = app(OrderExecutor::class);

        // (c) 正常下单 → filled，交易所被调用 1 次，order_placed 用量=1
        $adapter = $this->countingAdapter();
        $order = $executor->place(
            self::TENANT_ID,
            $this->instanceRow(),
            $adapter,
            ['side' => 'buy', 'qty' => '0.01', 'price' => '100'],
        );

        static::assertSame('filled', $order->status);
        static::assertSame(1, $adapter->calls);
        static::assertSame(1, $meter->usedToday(self::TENANT_ID, self::EVENT));

        // (d) 复用成交 cid + 对齐后数量为 0 → failed，零交易所调用，冲正后用量仍=1
        $adapter2 = $this->countingAdapter($adapter->lastClientOrderId);
        $order2 = $executor->place(
            self::TENANT_ID,
            $this->instanceRow(),
            $adapter2,
            ['side' => 'buy', 'qty' => '0.00000001', 'price' => '100'],
        );

        static::assertSame('failed', $order2->status);
        static::assertSame(0, $adapter2->calls, '对齐失败路径不得调用交易所');
        static::assertSame(1, $meter->usedToday(self::TENANT_ID, self::EVENT), '冲正后用量应保持 1');
    }

    private function instanceRow(): object
    {
        return (object) ['id' => $this->instanceId, 'tenant_id' => self::TENANT_ID, 'symbol' => 'BTCUSDT'];
    }

    /**
     * 计数桩适配器（迁自 POC）：除 placeOrder/精度方法外全部抛异常（证明不该被走到）。
     */
    private function countingAdapter(?string $reuseClientOrderId = null): ExchangeAdapter
    {
        return new class($reuseClientOrderId) implements ExchangeAdapter
        {
            public int $calls = 0;

            public ?string $lastClientOrderId = null;

            public function __construct(?string $reuseClientOrderId)
            {
                $this->lastClientOrderId = $reuseClientOrderId;
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
                $this->calls++;
                $this->lastClientOrderId = (string) ($opts['clientOrderId'] ?? '');

                return new ExchangeOrder(
                    exchangeOrderId: 'stub-1',
                    clientOrderId: $this->lastClientOrderId,
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
                    avgFillPrice: $price,
                    fee: '0',
                    placedAt: time(),
                    filledAt: time(),
                );
            }

            /** @return array<string,string> */
            public function symbolFilters(string $symbol): array
            {
                return ['step_size' => '0.00001000', 'min_notional' => '1', 'tick_size' => '0.01000000'];
            }

            public function alignToStep(string $value, string $step): string
            {
                if (bccomp($step, '0', 12) !== 1) {
                    return $value;
                }
                $floored = bcadd(bcdiv($value, $step, 12), '0', 0);

                return bcmul($floored, $step, 8);
            }

            public function getTicker(string $symbol): ExchangeTicker
            {
                throw new \RuntimeException('unexpected getTicker in billing order gate test');
            }

            /** @return ExchangeKline[] */
            public function getKline(string $symbol, string $interval, int $limit = 500): array
            {
                throw new \RuntimeException('unexpected getKline in billing order gate test');
            }

            public function getBalance(): ExchangeBalance
            {
                throw new \RuntimeException('unexpected getBalance in billing order gate test');
            }

            public function getPosition(string $symbol): ?ExchangePosition
            {
                throw new \RuntimeException('unexpected getPosition in billing order gate test');
            }

            public function getPositions(string $symbol): array
            {
                throw new \RuntimeException('unexpected getPositions in billing order gate test');
            }

            public function cancelOrder(string $symbol, string $orderId, ?string $clientOrderId = null): bool
            {
                throw new \RuntimeException('unexpected cancelOrder in billing order gate test');
            }

            public function getOrder(string $symbol, string $orderId): ExchangeOrder
            {
                throw new \RuntimeException('unexpected getOrder in billing order gate test');
            }

            public function getOrderByClientOrderId(string $symbol, string $clientOrderId): ?ExchangeOrder
            {
                throw new \RuntimeException('unexpected getOrderByClientOrderId in billing order gate test');
            }

            public function subscribeKlineStream(string $symbol, string $interval, callable $onBar, ?callable $onTick = null): string
            {
                throw new \RuntimeException('unexpected subscribeKlineStream in billing order gate test');
            }

            public function unsubscribeStream(string $subscriptionId): void
            {
                throw new \RuntimeException('unexpected unsubscribeStream in billing order gate test');
            }

            public function subscribeLiquidationStream(callable $onLiquidation): string
            {
                throw new \RuntimeException('unexpected subscribeLiquidationStream in billing order gate test');
            }
        };
    }
}
