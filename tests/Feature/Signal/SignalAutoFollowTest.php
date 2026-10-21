<?php

declare(strict_types=1);

namespace Tests\Feature\Signal;

use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Signal\SignalAutoTrader;
use App\Domain\Signal\SignalExecutionService;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\AutoExecuteSignalJob;
use App\Models\MeterEvent;
use App\Models\Signal;
use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * 信号自动跟单（订阅开启 → orders 队列 Job → SignalExecutionService 下单）。
 *
 * 覆盖：开启即下单且幂等、关闭不下单、置信度阈值、实例未运行、当日上限；
 * AutoTrader 仅在符合条件时入队。
 */
final class SignalAutoFollowTest extends TestCase
{
    private const TENANT_ID = 990501;

    private const EXCHANGE_ID = 990601;

    private const DEK = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    private int $accountId;

    private int $strategyId;

    private int $instanceId;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-auto-follow',
            'slug' => 'poc-auto-follow',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('exchanges')->insert([
            'id' => self::EXCHANGE_ID,
            'code' => 'binance-auto-990601',
            'name' => 'Binance',
            'base_url' => 'https://example.invalid',
            'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot', 'futures']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid',
            'name' => '现货网格',
            'category' => 'spot',
            'risk_profile' => 'conservative',
            'default_params' => json_encode([]),
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $keyResolver = $this->mock(KeyResolver::class);
        $keyResolver->shouldReceive('tenantDek')->andReturn(self::DEK);
        $this->app->forgetInstance(AesGcmCrypt::class);
        $cipher = (new AesGcmCrypt($this->app->make(KeyResolver::class)))
            ->encrypt('api-key', self::DEK);

        $this->accountId = (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => self::EXCHANGE_ID,
            'label' => 'auto-follow-account',
            'market' => 'spot',
            'encrypted_api_key' => $cipher,
            'encrypted_api_secret' => $cipher,
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->instanceId = (int) DB::table('strategy_instances')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'strategy_id' => $this->strategyId,
            'exchange_account_id' => $this->accountId,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => json_encode([]),
            'allocated_capital' => '1000',
            'status' => 'running',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->mock(QuotaGuard::class, function ($mock): void {
            $mock->shouldReceive('assertEntitled')->andReturnNull();
            $mock->shouldReceive('freezeDaily')->andReturn(new MeterEvent);
            $mock->shouldReceive('settle')->andReturnNull();
            $mock->shouldReceive('release')->andReturnNull();
        });
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_enabled_subscription_places_order_once(): void
    {
        $this->mockAdapter();

        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription();

        $this->runJob($signal, $subscriptionId);

        $order = DB::table('strategy_orders')
            ->where('tenant_id', self::TENANT_ID)
            ->where('client_order_id', "sig_{$signal->id}_{$this->instanceId}")
            ->first();

        self::assertNotNull($order);
        self::assertSame('buy', $order->side);
        self::assertSame('filled', $order->status);

        // 重复投递（同一信号）幂等：不新增订单
        $this->runJob($signal, $subscriptionId);

        self::assertSame(1, DB::table('strategy_orders')
            ->where('tenant_id', self::TENANT_ID)
            ->where('client_order_id', "sig_{$signal->id}_{$this->instanceId}")
            ->count());
    }

    public function test_disabled_subscription_places_no_order(): void
    {
        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription(['auto_execute' => 0]);

        $this->runJob($signal, $subscriptionId);

        self::assertSame(0, DB::table('strategy_orders')
            ->where('strategy_instance_id', $this->instanceId)
            ->count());
    }

    public function test_signal_below_min_confidence_is_skipped(): void
    {
        $signal = $this->createSignal(['confidence' => '50']);
        $subscriptionId = $this->createSubscription(['execution_min_confidence' => 80]);

        $this->runJob($signal, $subscriptionId);

        self::assertSame(0, DB::table('strategy_orders')
            ->where('strategy_instance_id', $this->instanceId)
            ->count());
    }

    public function test_stopped_instance_is_skipped(): void
    {
        DB::table('strategy_instances')
            ->where('id', $this->instanceId)
            ->update(['status' => 'paused_by_breaker']);

        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription();

        $this->runJob($signal, $subscriptionId);

        self::assertSame(0, DB::table('strategy_orders')
            ->where('strategy_instance_id', $this->instanceId)
            ->count());
    }

    public function test_daily_limit_blocks_further_auto_orders(): void
    {
        DB::table('strategy_orders')->insert([
            'tenant_id' => self::TENANT_ID,
            'strategy_instance_id' => $this->instanceId,
            'client_order_id' => 'sig_999_'.$this->instanceId,
            'symbol' => 'BTCUSDT',
            'side' => 'buy',
            'type' => 'market',
            'quantity' => '1',
            'status' => 'filled',
            'filled_qty' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription(['execution_daily_limit' => 1]);

        $this->runJob($signal, $subscriptionId);

        self::assertSame(1, DB::table('strategy_orders')
            ->where('strategy_instance_id', $this->instanceId)
            ->count());
    }

    public function test_auto_trader_queues_job_only_when_eligible(): void
    {
        Queue::fake();

        $signal = $this->createSignal();
        $subscription = (object) [
            'id' => $this->createSubscription(),
            'tenant_id' => self::TENANT_ID,
            'auto_execute' => 1,
            'execution_instance_id' => $this->instanceId,
            'execution_min_confidence' => 0,
        ];

        $trader = app(SignalAutoTrader::class);

        self::assertTrue($trader->maybeQueue($signal, $subscription));
        Queue::assertPushedOn('orders', AutoExecuteSignalJob::class);

        $disabled = (object) array_merge((array) $subscription, ['auto_execute' => 0]);

        self::assertFalse($trader->maybeQueue($signal, $disabled));
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function runJob(Signal $signal, int $subscriptionId): void
    {
        (new AutoExecuteSignalJob(self::TENANT_ID, (int) $signal->id, $subscriptionId))
            ->handle(app(SignalExecutionService::class));
    }

    private function mockAdapter(): void
    {
        $adapter = Mockery::mock(ExchangeAdapter::class);
        $adapter->shouldReceive('symbolFilters')->andReturn([
            'step_size' => '0.00000001',
            'tick_size' => '0.01',
            'min_notional' => '5',
        ]);
        $adapter->shouldReceive('alignToStep')->andReturnUsing(fn ($qty) => $qty);
        $adapter->shouldReceive('placeOrder')->andReturnUsing(
            fn ($symbol, $side, $type, $qty, $price, $stop, $opts) => new ExchangeOrder(
                exchangeOrderId: 'ex-auto-1',
                clientOrderId: (string) ($opts['clientOrderId'] ?? ''),
                symbol: $symbol,
                side: $side,
                type: 'market',
                quantity: $qty,
                price: null,
                stopPrice: null,
                leverage: null,
                reduceOnly: false,
                status: 'filled',
                filledQty: $qty,
                avgFillPrice: '100',
                fee: '0.1',
                placedAt: time(),
                filledAt: time(),
            ),
        );

        $this->mock(ExchangeManager::class, function ($mock) use ($adapter): void {
            $mock->shouldReceive('adapter')->andReturn($adapter);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSubscription(array $overrides = []): int
    {
        return (int) DB::table('signal_subscriptions')->insertGetId(array_merge([
            'tenant_id' => self::TENANT_ID,
            'name' => 'btc-auto',
            'symbols' => json_encode(['BTCUSDT']),
            'intervals' => json_encode(['5m']),
            'ai_prompter' => 'deepseek',
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(['in_app']),
            'active' => 1,
            'auto_execute' => 1,
            'execution_instance_id' => $this->instanceId,
            'execution_notional' => '100',
            'execution_min_confidence' => 0,
            'execution_daily_limit' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSignal(array $overrides = []): Signal
    {
        return Signal::query()->create(array_merge([
            'tenant_id' => self::TENANT_ID,
            'subscription_id' => $this->createSubscription(),
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'direction' => 'long',
            'entry_price' => '100',
            'exit_price' => '110',
            'stop_loss' => '95',
            'confidence' => '80',
            'source' => 'ai',
            'rationale' => 'test',
            'raw_payload' => [],
            'triggered_at' => now(),
        ], $overrides));
    }
}
