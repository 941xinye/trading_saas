<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\SignalSubscriptionResource\Pages\CreateSignalSubscription;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 信号订阅表单的自动跟单配置项（开启后展示执行实例/名义额/置信度/日上限）。
 */
final class SignalSubscriptionAutoFollowFormTest extends TestCase
{
    private const TENANT_ID = 990701;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-sub-form',
            'slug' => 'poc-sub-form',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('exchanges')->insert([
            'id' => 990801,
            'code' => 'binance-sub-990801',
            'name' => 'Binance',
            'base_url' => 'https://example.invalid',
            'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid',
            'name' => '现货网格',
            'category' => 'spot',
            'risk_profile' => 'conservative',
            'default_params' => json_encode([]),
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $accountId = (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => 990801,
            'label' => 'sub-form-account',
            'market' => 'spot',
            'encrypted_api_key' => 'cipher',
            'encrypted_api_secret' => 'cipher',
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('strategy_instances')->insert([
            'tenant_id' => self::TENANT_ID,
            'strategy_id' => $strategyId,
            'exchange_account_id' => $accountId,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => json_encode([]),
            'allocated_capital' => '1000',
            'status' => 'running',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        TenantContext::initialize(self::TENANT_ID);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['tenant_id' => self::TENANT_ID, 'role' => 'tenant_admin']));
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_auto_execution_fields_appear_after_enabling(): void
    {
        Livewire::test(CreateSignalSubscription::class)
            ->assertOk()
            ->assertSee('自动跟单')
            ->fillForm(['auto_execute' => 1])
            ->assertSee('执行实例')
            ->assertSee('BTCUSDT')
            ->assertSee('最低置信度')
            ->assertSee('当日信号单上限');
    }
}
