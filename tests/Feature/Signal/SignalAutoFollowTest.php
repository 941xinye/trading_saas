<?php

declare(strict_types=1);

namespace Tests\Feature\Signal;

use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Risk\PlatformKillSwitch;
use App\Domain\Signal\SignalAutoFollowAudit;
use App\Domain\Signal\SignalAutoTrader;
use App\Domain\Signal\SignalExecutionService;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\AutoExecuteSignalJob;
use App\Jobs\NotifyAutoFollowExecutedJob;
use App\Models\MeterEvent;
use App\Models\Signal;
use App\Models\SignalSubscription;
use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
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

    private const OTHER_TENANT_ID = 990502;

    private const EXCHANGE_ID = 990601;

    private const DEK = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    private int $accountId;

    private int $accountOtherId;

    private int $strategyId;

    private int $instanceId;

    private int $otherInstanceId;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();

        foreach ([
            [self::TENANT_ID, 'poc-auto-follow'],
            [self::OTHER_TENANT_ID, 'poc-auto-follow-other'],
        ] as [$tenantId, $slug]) {
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'name' => $slug,
                'slug' => $slug,
                'exchange_dek' => 'test-dek',
                'status' => 'active',
                'plan_code' => 'pro',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

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

        $this->accountOtherId = (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => self::OTHER_TENANT_ID,
            'exchange_id' => self::EXCHANGE_ID,
            'label' => 'auto-follow-account-other',
            'market' => 'spot',
            'encrypted_api_key' => $cipher,
            'encrypted_api_secret' => $cipher,
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->otherInstanceId = (int) DB::table('strategy_instances')->insertGetId([
            'tenant_id' => self::OTHER_TENANT_ID,
            'strategy_id' => $this->strategyId,
            'exchange_account_id' => $this->accountOtherId,
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
        self::assertDatabaseHas('signal_auto_follow_audits', [
            'tenant_id' => self::TENANT_ID,
            'signal_id' => (int) $signal->id,
            'decision' => 'queued',
        ]);

        $disabled = (object) array_merge((array) $subscription, ['auto_execute' => 0]);

        self::assertFalse($trader->maybeQueue($signal, $disabled));
    }

    public function test_platform_kill_switch_blocks_auto_order(): void
    {
        $this->mockAdapter(expectOrder: false);

        $connection = Mockery::mock();
        $connection->shouldReceive('exists')->with(PlatformKillSwitch::KEY)->andReturn(1);
        Redis::shouldReceive('connection')->andReturn($connection);

        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription();

        $this->runJob($signal, $subscriptionId);

        self::assertSame(0, DB::table('strategy_orders')
            ->where('strategy_instance_id', $this->instanceId)
            ->count());
    }

    public function test_subscription_of_other_tenant_is_skipped(): void
    {
        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription([
            'tenant_id' => self::OTHER_TENANT_ID,
            'execution_instance_id' => $this->otherInstanceId,
        ]);

        $this->runJob($signal, $subscriptionId);

        self::assertSame(0, DB::table('strategy_orders')->count());
    }

    public function test_subscription_pointing_to_foreign_instance_is_skipped(): void
    {
        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription([
            'execution_instance_id' => $this->otherInstanceId,
        ]);

        $this->runJob($signal, $subscriptionId);

        self::assertSame(0, DB::table('strategy_orders')->count());
    }

    public function test_expired_signal_is_skipped(): void
    {
        $signal = $this->createSignal(['triggered_at' => now()->subMinutes(16)]);
        $subscriptionId = $this->createSubscription();

        $this->runJob($signal, $subscriptionId);

        self::assertSame(0, DB::table('strategy_orders')
            ->where('strategy_instance_id', $this->instanceId)
            ->count());
    }

    public function test_spot_account_rejects_short_signal(): void
    {
        $signal = $this->createSignal(['direction' => 'short']);
        $subscriptionId = $this->createSubscription();

        $this->runJob($signal, $subscriptionId);

        self::assertSame(0, DB::table('strategy_orders')
            ->where('strategy_instance_id', $this->instanceId)
            ->count());
    }

    public function test_zero_daily_limit_means_unlimited(): void
    {
        foreach ([1, 2, 3] as $i) {
            DB::table('strategy_orders')->insert([
                'tenant_id' => self::TENANT_ID,
                'strategy_instance_id' => $this->instanceId,
                'client_order_id' => "sig_90{$i}_{$this->instanceId}",
                'symbol' => 'BTCUSDT',
                'side' => 'buy',
                'type' => 'market',
                'quantity' => '1',
                'status' => 'filled',
                'filled_qty' => '1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->mockAdapter();

        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription(['execution_daily_limit' => 0]);

        $this->runJob($signal, $subscriptionId);

        self::assertSame(4, DB::table('strategy_orders')
            ->where('strategy_instance_id', $this->instanceId)
            ->count());
    }

    public function test_pending_order_with_same_client_order_id_blocks_replay(): void
    {
        $this->mockAdapter(expectOrder: false);

        $signal = $this->createSignal();
        $clientOrderId = "sig_{$signal->id}_{$this->instanceId}";

        DB::table('strategy_orders')->insert([
            'tenant_id' => self::TENANT_ID,
            'strategy_instance_id' => $this->instanceId,
            'client_order_id' => $clientOrderId,
            'symbol' => 'BTCUSDT',
            'side' => 'buy',
            'type' => 'market',
            'quantity' => '1',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subscriptionId = $this->createSubscription();

        $this->runJob($signal, $subscriptionId);

        self::assertSame(1, DB::table('strategy_orders')
            ->where('client_order_id', $clientOrderId)
            ->count());
        self::assertSame('pending', DB::table('strategy_orders')
            ->where('client_order_id', $clientOrderId)
            ->value('status'));
    }

    public function test_failed_order_with_same_client_order_id_allows_retry(): void
    {
        $this->mockAdapter();

        $signal = $this->createSignal();
        $clientOrderId = "sig_{$signal->id}_{$this->instanceId}";

        DB::table('strategy_orders')->insert([
            'tenant_id' => self::TENANT_ID,
            'strategy_instance_id' => $this->instanceId,
            'client_order_id' => $clientOrderId,
            'symbol' => 'BTCUSDT',
            'side' => 'buy',
            'type' => 'market',
            'quantity' => '1',
            'status' => 'failed',
            'error_message' => 'previous failure',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subscriptionId = $this->createSubscription();

        $this->runJob($signal, $subscriptionId);

        self::assertSame(1, DB::table('strategy_orders')
            ->where('client_order_id', $clientOrderId)
            ->count());
        self::assertSame('filled', DB::table('strategy_orders')
            ->where('client_order_id', $clientOrderId)
            ->value('status'));
    }

    // ------------------------------------------------------------------
    // FR-027 / FR-028 / FR-029 / FR-030 补测
    // ------------------------------------------------------------------

    public function test_daily_notional_cap_blocks_further_auto_orders(): void
    {
        config(['signal.execution.daily_notional_cap' => '150']);

        DB::table('strategy_orders')->insert([
            'tenant_id' => self::TENANT_ID,
            'strategy_instance_id' => $this->instanceId,
            'client_order_id' => 'sig_990_'.$this->instanceId,
            'symbol' => 'BTCUSDT',
            'side' => 'buy',
            'type' => 'market',
            'quantity' => '1',
            'notional' => '100',
            'status' => 'filled',
            'filled_qty' => '1',
            'avg_fill_price' => '100',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription(['execution_notional' => '100']);

        $this->runJob($signal, $subscriptionId);

        self::assertSame(1, DB::table('strategy_orders')
            ->where('strategy_instance_id', $this->instanceId)
            ->count());
        self::assertDatabaseHas('signal_auto_follow_audits', [
            'tenant_id' => self::TENANT_ID,
            'signal_id' => (int) $signal->id,
            'decision' => 'skipped',
            'reason' => 'daily_notional_cap_reached',
        ]);
    }

    public function test_zero_daily_limit_allows_consecutive_executions(): void
    {
        $this->mockAdapter();

        $subscriptionId = $this->createSubscription(['execution_daily_limit' => 0]);

        $first = $this->createSignal();
        $second = $this->createSignal();

        $this->runJob($first, $subscriptionId);
        $this->runJob($second, $subscriptionId);

        self::assertSame(2, DB::table('strategy_orders')
            ->where('strategy_instance_id', $this->instanceId)
            ->whereRaw("substr(client_order_id, 1, 4) = 'sig_'")
            ->count());
    }

    public function test_config_version_mismatch_is_skipped(): void
    {
        $this->mockAdapter(expectOrder: false);

        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription();

        // 订阅当前版本 1，Job 携带入队时版本 2（期间配置被改过）→ 执行前比对不一致
        $this->runJob($signal, $subscriptionId, configVersion: 2);

        self::assertSame(0, DB::table('strategy_orders')
            ->where('strategy_instance_id', $this->instanceId)
            ->count());
        self::assertDatabaseHas('signal_auto_follow_audits', [
            'tenant_id' => self::TENANT_ID,
            'signal_id' => (int) $signal->id,
            'decision' => 'skipped',
            'reason' => 'config_version_changed',
        ]);
    }

    public function test_execution_is_audited(): void
    {
        $this->mockAdapter();

        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription();

        $this->runJob($signal, $subscriptionId);

        self::assertDatabaseHas('signal_auto_follow_audits', [
            'tenant_id' => self::TENANT_ID,
            'signal_id' => (int) $signal->id,
            'subscription_id' => $subscriptionId,
            'instance_id' => $this->instanceId,
            'decision' => 'executed',
            'client_order_id' => "sig_{$signal->id}_{$this->instanceId}",
        ]);
    }

    public function test_successful_execution_dispatches_notification_job(): void
    {
        Queue::fake();

        $this->mockAdapter();

        $signal = $this->createSignal();
        $subscriptionId = $this->createSubscription();

        $this->runJob($signal, $subscriptionId);

        Queue::assertPushedOn('notifications', NotifyAutoFollowExecutedJob::class);
    }

    public function test_model_update_bumps_execution_config_version(): void
    {
        $subscriptionId = $this->createSubscription();

        $model = SignalSubscription::query()->withoutGlobalScopes()->findOrFail($subscriptionId);
        self::assertSame(1, (int) $model->execution_config_version);

        $model->execution_notional = '250';
        $model->save();
        self::assertSame(2, (int) SignalSubscription::query()->withoutGlobalScopes()->find($subscriptionId)->execution_config_version);

        $model = SignalSubscription::query()->withoutGlobalScopes()->findOrFail($subscriptionId);
        $model->name = 'renamed';
        $model->save();
        self::assertSame(2, (int) SignalSubscription::query()->withoutGlobalScopes()->find($subscriptionId)->execution_config_version);
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function runJob(
        Signal $signal,
        int $subscriptionId,
        ?int $tenantId = null,
        ?int $configVersion = null,
    ): void {
        (new AutoExecuteSignalJob(
            $tenantId ?? self::TENANT_ID,
            (int) $signal->id,
            $subscriptionId,
            $configVersion,
        ))->handle(
            app(SignalExecutionService::class),
            app(SignalAutoFollowAudit::class),
        );
    }

    private function mockAdapter(bool $expectOrder = true): ExchangeAdapter
    {
        $adapter = Mockery::mock(ExchangeAdapter::class);
        $adapter->shouldReceive('symbolFilters')->andReturn([
            'step_size' => '0.00000001',
            'tick_size' => '0.01',
            'min_notional' => '5',
        ]);
        $adapter->shouldReceive('alignToStep')->andReturnUsing(fn ($qty) => $qty);

        if ($expectOrder) {
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
        } else {
            $adapter->shouldNotReceive('placeOrder');
        }

        $this->mock(ExchangeManager::class, function ($mock) use ($adapter): void {
            $mock->shouldReceive('adapter')->andReturn($adapter);
        });

        return $adapter;
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
