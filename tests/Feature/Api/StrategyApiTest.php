<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\KeyResolver;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 策略实例 / 交易所账户 / Dashboard API 写接口 Feature 测试（2026-10-21 补齐 202 骨架）。
 *
 * 覆盖：
 *  - 账户创建走加密落库、响应不回明文
 *  - 实例创建 → start（starting）→ stop（stopped）状态机
 *  - 运行中禁止改配/删除（409）
 *  - 跨租户资源 404（TenantScope）
 *  - Dashboard 聚合结构
 */
class StrategyApiTest extends TestCase
{
    private const TENANT_A = 990081;

    private const TENANT_B = 990082;

    private const EXCHANGE_ID = 990181;

    private User $userA;

    private User $userB;

    private int $accountId;

    private int $strategyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(KeyResolver::class, function ($mock): void {
            $mock->shouldReceive('masterKey')->andReturn(str_repeat('k', 32));
            $mock->shouldReceive('tenantDek')->andReturn(str_repeat('d', 32));
        });

        $now = now();

        foreach ([
            [self::TENANT_A, 'poc-api-a-990081'],
            [self::TENANT_B, 'poc-api-b-990082'],
        ] as [$tenantId, $slug]) {
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'name' => $slug,
                'slug' => $slug,
                'exchange_dek' => 'test-dek',
                'status' => 'active',
                'plan_code' => 'free',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('exchanges')->insert([
            'id' => self::EXCHANGE_ID,
            'code' => 'binance-api-990181',
            'name' => 'Binance',
            'base_url' => 'https://example.invalid',
            'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid_api_990081',
            'name' => '现货网格',
            'category' => 'spot',
            'risk_profile' => 'conservative',
            'default_params' => json_encode([]),
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->accountId = (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => self::TENANT_A,
            'exchange_id' => self::EXCHANGE_ID,
            'label' => 'poc-api-account',
            'market' => 'spot',
            'encrypted_api_key' => 'seed',
            'encrypted_api_secret' => 'seed',
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->userA = User::factory()->create(['tenant_id' => self::TENANT_A, 'role' => 'tenant_admin']);
        $this->userB = User::factory()->create(['tenant_id' => self::TENANT_B, 'role' => 'tenant_admin']);
    }

    public function test_exchange_account_store_encrypts_and_hides_secrets(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/v1/exchange-accounts', [
            'exchange_id' => self::EXCHANGE_ID,
            'label' => 'api-created-account',
            'market' => 'spot',
            'api_key' => 'plain-key',
            'api_secret' => 'plain-secret',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.label', 'api-created-account')
            ->assertJsonPath('data.api_key', null)
            ->assertJsonPath('data.api_secret', null);

        $row = DB::table('exchange_accounts')->where('label', 'api-created-account')->first();
        static::assertNotNull($row);
        static::assertNotSame('plain-key', $row->encrypted_api_key);
        static::assertNotSame('plain-secret', $row->encrypted_api_secret);
    }

    public function test_strategy_instance_lifecycle_and_live_guards(): void
    {
        Sanctum::actingAs($this->userA);

        $created = $this->postJson('/api/v1/strategy-instances', [
            'strategy_id' => $this->strategyId,
            'exchange_account_id' => $this->accountId,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => ['grid_count' => 10],
            'allocated_capital' => '100',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'stopped');

        $id = (int) $created->json('data.id');

        $this->postJson("/api/v1/strategy-instances/{$id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'starting');

        // 运行中禁止改配
        $this->putJson("/api/v1/strategy-instances/{$id}", ['allocated_capital' => '200'])
            ->assertStatus(409);

        $this->postJson("/api/v1/strategy-instances/{$id}/stop")
            ->assertOk()
            ->assertJsonPath('data.status', 'stopped');

        $this->putJson("/api/v1/strategy-instances/{$id}", ['allocated_capital' => '200'])
            ->assertOk()
            ->assertJsonPath('data.allocated_capital', '200.00000000');

        $this->deleteJson("/api/v1/strategy-instances/{$id}")->assertNoContent();
    }

    public function test_cross_tenant_instance_is_not_found(): void
    {
        $id = (int) DB::table('strategy_instances')->insertGetId([
            'tenant_id' => self::TENANT_A,
            'strategy_id' => $this->strategyId,
            'exchange_account_id' => $this->accountId,
            'symbol' => 'ETHUSDT',
            'interval' => '5m',
            'params' => json_encode([]),
            'allocated_capital' => '100',
            'status' => 'stopped',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->userB);

        $this->getJson("/api/v1/strategy-instances/{$id}")->assertNotFound();
    }

    public function test_dashboard_returns_aggregated_panels(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk()->assertJsonStructure([
            'data' => [
                'market_heat' => ['symbols', 'top_gainers', 'fear_greed'],
                'balance' => ['total_equity_usdt', 'total_margin_usdt', 'available_usdt', 'unrealized_pnl', 'accounts'],
                'signal_feed' => ['signals', 'running_instances'],
                'alert_feed' => ['breaker_events', 'failed_orders'],
                'liquidation_map' => ['symbol', 'window', 'clusters'],
                'cached_at',
            ],
        ]);
    }
}
