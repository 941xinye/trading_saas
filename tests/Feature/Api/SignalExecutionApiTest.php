<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Tenancy\TenantContext;
use App\Models\MeterEvent;
use App\Models\Signal;
use App\Models\StrategyInstance;
use App\Models\User;
use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * 信号一键下单 API（信号 → 交易联动第一步）。
 *
 * 覆盖：long 市价买入成功并落 strategy_orders、重复执行 409、过期信号 422、
 * 实例未运行 409、现货做空 422、跨租户 404。
 */
final class SignalExecutionApiTest extends TestCase
{
    private const TENANT_A = 990301;

    private const TENANT_B = 990302;

    private const EXCHANGE_ID = 990401;

    private const DEK = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    private User $userA;

    private User $userB;

    private int $accountA;

    private int $accountB;

    private int $subscriptionA;

    private int $strategyId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [self::TENANT_A, 'poc-signal-a'],
            [self::TENANT_B, 'poc-signal-b'],
        ] as [$tenantId, $slug]) {
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'name' => $slug,
                'slug' => $slug,
                'exchange_dek' => 'test-dek',
                'status' => 'active',
                'plan_code' => 'pro',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('exchanges')->insert([
            'id' => self::EXCHANGE_ID,
            'code' => 'binance-sig-990401',
            'name' => 'Binance',
            'base_url' => 'https://example.invalid',
            'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot', 'futures']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid',
            'name' => '现货网格',
            'category' => 'spot',
            'risk_profile' => 'conservative',
            'default_params' => json_encode([]),
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 凭据用真实 AesGcmCrypt 加密，服务内部用同一 KeyResolver 解密
        $keyResolver = $this->mock(KeyResolver::class);
        $keyResolver->shouldReceive('tenantDek')->andReturn(self::DEK);
        $this->app->forgetInstance(AesGcmCrypt::class);
        $cipher = (new AesGcmCrypt($this->app->make(KeyResolver::class)))
            ->encrypt('api-key', self::DEK);

        $this->accountA = $this->createAccount(self::TENANT_A, 'signal-account-a', $cipher);
        $this->accountB = $this->createAccount(self::TENANT_B, 'signal-account-b', $cipher);

        $this->subscriptionA = (int) DB::table('signal_subscriptions')->insertGetId([
            'tenant_id' => self::TENANT_A,
            'name' => 'btc',
            'symbols' => json_encode(['BTCUSDT']),
            'intervals' => json_encode(['5m']),
            'ai_prompter' => 'deepseek',
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(['in_app']),
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->userA = User::factory()->create(['tenant_id' => self::TENANT_A, 'role' => 'tenant_admin']);
        $this->userB = User::factory()->create(['tenant_id' => self::TENANT_B, 'role' => 'tenant_admin']);

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

    public function test_execute_long_signal_places_market_buy_and_is_idempotent(): void
    {
        $this->mockAdapter();
        Sanctum::actingAs($this->userA);

        $signal = $this->createSignal();
        $instance = $this->createInstance();

        $response = $this->postJson(
            "/api/v1/signals/{$signal->id}/execute",
            ['instance_id' => $instance->id, 'notional' => '100'],
        )
            ->assertCreated()
            ->assertJsonPath('data.signal_id', $signal->id)
            ->assertJsonPath('data.instance_id', $instance->id)
            ->assertJsonPath('data.side', 'buy')
            ->assertJsonPath('data.status', 'filled');

        self::assertSame(0, bccomp((string) $response->json('data.quantity'), '1', 8));

        $row = DB::table('strategy_orders')
            ->where('tenant_id', self::TENANT_A)
            ->where('client_order_id', "sig_{$signal->id}_{$instance->id}")
            ->first();
        self::assertNotNull($row);
        self::assertSame('buy', $row->side);
        self::assertSame('filled', $row->status);

        // 同一信号在同一实例上不可重复执行
        $this->postJson(
            "/api/v1/signals/{$signal->id}/execute",
            ['instance_id' => $instance->id, 'notional' => '100'],
        )->assertStatus(409);
    }

    public function test_expired_signal_is_rejected(): void
    {
        Sanctum::actingAs($this->userA);

        $signal = $this->createSignal(['triggered_at' => now()->subHour()]);
        $instance = $this->createInstance();

        $this->postJson(
            "/api/v1/signals/{$signal->id}/execute",
            ['instance_id' => $instance->id],
        )
            ->assertStatus(422)
            ->assertJsonPath('message', '信号已超过 15 分钟执行窗口。');
    }

    public function test_stopped_instance_is_rejected(): void
    {
        Sanctum::actingAs($this->userA);

        $signal = $this->createSignal();
        $instance = $this->createInstance(['status' => 'stopped']);

        $this->postJson(
            "/api/v1/signals/{$signal->id}/execute",
            ['instance_id' => $instance->id],
        )->assertStatus(409);
    }

    public function test_spot_account_rejects_short_signal(): void
    {
        Sanctum::actingAs($this->userA);

        $signal = $this->createSignal(['direction' => 'short']);
        $instance = $this->createInstance();

        $this->postJson(
            "/api/v1/signals/{$signal->id}/execute",
            ['instance_id' => $instance->id],
        )
            ->assertStatus(422)
            ->assertJsonPath('message', '现货账户不支持执行做空信号。');
    }

    public function test_cross_tenant_signal_or_instance_is_not_found(): void
    {
        $signal = $this->createSignal();
        $instanceB = $this->createInstance([
            'tenant_id' => self::TENANT_B,
            'exchange_account_id' => $this->accountB,
        ]);

        Sanctum::actingAs($this->userA);

        $this->postJson(
            "/api/v1/signals/{$signal->id}/execute",
            ['instance_id' => $instanceB->id],
        )->assertNotFound();

        Sanctum::actingAs($this->userB);

        $this->postJson(
            "/api/v1/signals/{$signal->id}/execute",
            ['instance_id' => $instanceB->id],
        )->assertNotFound();
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

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
                exchangeOrderId: 'ex-1',
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

    private function createAccount(int $tenantId, string $label, string $cipher): int
    {
        return (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => $tenantId,
            'exchange_id' => self::EXCHANGE_ID,
            'label' => $label,
            'market' => 'spot',
            'encrypted_api_key' => $cipher,
            'encrypted_api_secret' => $cipher,
            'permissions' => json_encode(['spot']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSignal(array $overrides = []): Signal
    {
        return Signal::query()->create(array_merge([
            'tenant_id' => self::TENANT_A,
            'subscription_id' => $this->subscriptionA,
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createInstance(array $overrides = []): StrategyInstance
    {
        return StrategyInstance::query()->create(array_merge([
            'tenant_id' => self::TENANT_A,
            'strategy_id' => $this->strategyId,
            'exchange_account_id' => $this->accountA,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => [],
            'allocated_capital' => '1000',
            'status' => 'running',
        ], $overrides));
    }
}
