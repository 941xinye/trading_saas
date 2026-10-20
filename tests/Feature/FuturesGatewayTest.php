<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Billing\EntitlementException;
use App\Domain\Strategy\StrategyGateway;
use App\Models\Exchange;
use App\Models\ExchangeAccount;
use App\Models\Strategy;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 合约网关售卖门控测试（spec 005 T041）。
 *
 * 验证 futures_grid 策略实例创建：
 *  - pro/enterprise 租户：放行
 *  - free 租户：拒绝（EntitlementException）
 *
 * default 组（SQLite in-memory，无 Redis/网络依赖）。
 */
class FuturesGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 策略目录
        Strategy::query()->create([
            'code' => 'futures_grid',
            'name' => '合约网格',
            'category' => 'futures',
            'risk_profile' => 'medium',
            'default_params' => ['grid_count' => 20],
            'enabled' => true,
        ]);

        // 交易所 + 账户
        $exchange = Exchange::query()->create([
            'code' => 'binance',
            'name' => 'Binance',
            'base_url' => 'https://fapi.binance.com',
            'ws_url' => 'wss://fstream.binance.com',
            'markets' => ['spot', 'futures'],
        ]);

        // free 租户 + 账户
        $this->freeTenant = Tenant::query()->create([
            'name' => 'poc-free',
            'slug' => 'poc-free',
            'exchange_dek' => 'dummy-dek-free',
            'status' => 'active',
            'plan_code' => 'free',
        ]);
        $this->freeAccount = ExchangeAccount::query()->create([
            'tenant_id' => $this->freeTenant->id,
            'exchange_id' => $exchange->id,
            'label' => 'free-binance',
            'market' => 'futures',
            'encrypted_api_key' => 'k',
            'encrypted_api_secret' => 's',
            'dek_version' => 1,
            'permissions' => ['read', 'trade'],
            'status' => 'active',
        ]);

        // pro 租户 + 账户
        $this->proTenant = Tenant::query()->create([
            'name' => 'poc-pro',
            'slug' => 'poc-pro',
            'exchange_dek' => 'dummy-dek-pro',
            'status' => 'active',
            'plan_code' => 'pro',
        ]);
        $this->proAccount = ExchangeAccount::query()->create([
            'tenant_id' => $this->proTenant->id,
            'exchange_id' => $exchange->id,
            'label' => 'pro-binance',
            'market' => 'futures',
            'encrypted_api_key' => 'k',
            'encrypted_api_secret' => 's',
            'dek_version' => 1,
            'permissions' => ['read', 'trade'],
            'status' => 'active',
        ]);
    }

    public function test_pro_tenant_can_create_futures_grid_instance(): void
    {
        $gateway = app(StrategyGateway::class);

        $instance = $gateway->createInstance(
            tenant: $this->proTenant,
            strategyCode: 'futures_grid',
            exchangeAccountId: $this->proAccount->id,
            symbol: 'BTCUSDT',
            interval: '1m',
            params: ['grid_count' => 20, 'lower_price' => '50000', 'upper_price' => '70000'],
            allocatedCapital: '1000',
            leverage: 10,
        );

        static::assertNotNull($instance->id);
        static::assertSame('futures_grid', $instance->strategy->code);
        static::assertSame(10, $instance->leverage);
        static::assertSame('stopped', $instance->status);
    }

    public function test_free_tenant_cannot_create_futures_grid_instance(): void
    {
        $gateway = app(StrategyGateway::class);

        $this->expectException(EntitlementException::class);

        $gateway->createInstance(
            tenant: $this->freeTenant,
            strategyCode: 'futures_grid',
            exchangeAccountId: $this->freeAccount->id,
            symbol: 'BTCUSDT',
            interval: '1m',
            params: ['grid_count' => 20],
            allocatedCapital: '1000',
            leverage: 10,
        );
    }

    /**
     * @var Tenant
     */
    private $freeTenant;

    /**
     * @var Tenant
     */
    private $proTenant;

    /**
     * @var ExchangeAccount
     */
    private $freeAccount;

    /**
     * @var ExchangeAccount
     */
    private $proAccount;
}
