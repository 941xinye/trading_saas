<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Billing\QuotaGuard;
use App\Domain\Exchange\Contracts\ExchangeAdapter;
use App\Domain\Exchange\Contracts\ExchangeOrder;
use App\Domain\Exchange\ExchangeManager;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\SignalPage;
use App\Models\MeterEvent;
use App\Models\Signal;
use App\Models\StrategyInstance;
use App\Models\User;
use App\Support\AesGcmCrypt;
use App\Support\KeyResolver;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * 信号页（列表 + 一键下单 action）。
 */
final class SignalPageTest extends TestCase
{
    private const TENANT_ID = 990501;

    private const EXCHANGE_ID = 990601;

    private const DEK = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    private int $accountId;

    private int $strategyId;

    private int $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-signal-page',
            'slug' => 'poc-signal-page',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exchanges')->insert([
            'id' => self::EXCHANGE_ID,
            'code' => 'binance-sig-page-990601',
            'name' => 'Binance',
            'base_url' => 'https://example.invalid',
            'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
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

        $keyResolver = $this->mock(KeyResolver::class);
        $keyResolver->shouldReceive('tenantDek')->andReturn(self::DEK);
        $this->app->forgetInstance(AesGcmCrypt::class);
        $cipher = (new AesGcmCrypt($this->app->make(KeyResolver::class)))
            ->encrypt('api-key', self::DEK);

        $this->accountId = (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => self::EXCHANGE_ID,
            'label' => 'signal-page-account',
            'market' => 'spot',
            'encrypted_api_key' => $cipher,
            'encrypted_api_secret' => $cipher,
            'permissions' => json_encode(['spot']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->subscriptionId = (int) DB::table('signal_subscriptions')->insertGetId([
            'tenant_id' => self::TENANT_ID,
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

        $this->mock(QuotaGuard::class, function ($mock): void {
            $mock->shouldReceive('assertEntitled')->andReturnNull();
            $mock->shouldReceive('freezeDaily')->andReturn(new MeterEvent);
            $mock->shouldReceive('settle')->andReturnNull();
            $mock->shouldReceive('release')->andReturnNull();
        });

        TenantContext::initialize(self::TENANT_ID);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['tenant_id' => self::TENANT_ID, 'role' => 'tenant_admin']));
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_page_lists_signals_and_executes_via_action(): void
    {
        $signal = Signal::query()->create([
            'tenant_id' => self::TENANT_ID,
            'subscription_id' => $this->subscriptionId,
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
        ]);

        $instance = StrategyInstance::query()->create([
            'tenant_id' => self::TENANT_ID,
            'strategy_id' => $this->strategyId,
            'exchange_account_id' => $this->accountId,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => [],
            'allocated_capital' => '1000',
            'status' => 'running',
        ]);

        $adapter = Mockery::mock(ExchangeAdapter::class);
        $adapter->shouldReceive('symbolFilters')->andReturn([
            'step_size' => '0.00000001',
            'tick_size' => '0.01',
            'min_notional' => '5',
        ]);
        $adapter->shouldReceive('alignToStep')->andReturnUsing(fn ($qty) => $qty);
        $adapter->shouldReceive('placeOrder')->andReturnUsing(
            fn ($symbol, $side, $type, $qty, $price, $stop, $opts) => new ExchangeOrder(
                exchangeOrderId: 'ex-page-1',
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

        Livewire::test(SignalPage::class)
            ->assertSee('最近信号')
            ->assertSee('BTCUSDT')
            ->callAction('executeSignal', data: [
                'signal_id' => $signal->id,
                'instance_id' => $instance->id,
                'notional' => '100',
            ])
            ->assertHasNoActionErrors();

        $row = DB::table('strategy_orders')
            ->where('tenant_id', self::TENANT_ID)
            ->where('client_order_id', "sig_{$signal->id}_{$instance->id}")
            ->first();
        self::assertNotNull($row);
        self::assertSame('filled', $row->status);
        self::assertSame('buy', $row->side);
    }
}
