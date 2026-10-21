<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 信号订阅 REST 写接口（store/update/destroy）与自动跟单配置校验。
 *
 * 覆盖：创建/更新/删除、自动跟单字段落库与回显、开启自动跟单必须绑定
 * 本租户实例、跨租户实例与订阅一律 404/422、列表租户隔离。
 */
final class SignalSubscriptionApiTest extends TestCase
{
    private const TENANT_A = 990901;

    private const TENANT_B = 990902;

    private const EXCHANGE_ID = 990911;

    private User $userA;

    private int $strategyId;

    private int $accountA;

    private int $accountB;

    private int $instanceA;

    private int $instanceB;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();

        foreach ([
            [self::TENANT_A, 'poc-sub-api-a'],
            [self::TENANT_B, 'poc-sub-api-b'],
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
            'code' => 'binance-sub-api-990911',
            'name' => 'Binance',
            'base_url' => 'https://example.invalid',
            'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot', 'futures']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid_sub_api_990901',
            'name' => '现货网格',
            'category' => 'spot',
            'risk_profile' => 'conservative',
            'default_params' => json_encode([]),
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->accountA = $this->createAccount(self::TENANT_A, 'sub-api-account-a', $now);
        $this->accountB = $this->createAccount(self::TENANT_B, 'sub-api-account-b', $now);

        $this->instanceA = $this->createInstance(self::TENANT_A, $this->accountA, $now);
        $this->instanceB = $this->createInstance(self::TENANT_B, $this->accountB, $now);

        $this->userA = User::factory()->create(['tenant_id' => self::TENANT_A, 'role' => 'tenant_admin']);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    public function test_store_creates_subscription_with_auto_follow_config(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/v1/signal-subscriptions', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'BTC 自动跟单')
            ->assertJsonPath('data.auto_execute', true)
            ->assertJsonPath('data.execution_instance_id', $this->instanceA)
            ->assertJsonPath('data.execution_min_confidence', 70)
            ->assertJsonPath('data.execution_daily_limit', 5);

        self::assertSame(0, bccomp((string) $response->json('data.execution_notional'), '250', 8));

        $row = DB::table('signal_subscriptions')->where('tenant_id', self::TENANT_A)->first();
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->auto_execute);
        self::assertSame($this->instanceA, (int) $row->execution_instance_id);
        self::assertSame(0, bccomp((string) $row->execution_notional, '250', 8));
        self::assertSame(70, (int) $row->execution_min_confidence);
        self::assertSame(5, (int) $row->execution_daily_limit);
    }

    public function test_store_requires_instance_when_auto_execute_enabled(): void
    {
        Sanctum::actingAs($this->userA);

        $payload = $this->payload();
        unset($payload['execution_instance_id']);

        $this->postJson('/api/v1/signal-subscriptions', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('execution_instance_id');
    }

    public function test_store_rejects_foreign_tenant_instance(): void
    {
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/v1/signal-subscriptions', $this->payload([
            'execution_instance_id' => $this->instanceB,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('execution_instance_id');
    }

    public function test_store_rejects_invalid_auto_follow_bounds(): void
    {
        Sanctum::actingAs($this->userA);

        $this->postJson('/api/v1/signal-subscriptions', $this->payload([
            'intervals' => ['7m'],
            'execution_min_confidence' => 101,
            'execution_daily_limit' => 70000,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['intervals.0', 'execution_min_confidence', 'execution_daily_limit']);
    }

    public function test_update_toggles_auto_follow_and_persists_fields(): void
    {
        Sanctum::actingAs($this->userA);

        $subscriptionId = $this->createSubscriptionRow(self::TENANT_A);

        $this->patchJson("/api/v1/signal-subscriptions/{$subscriptionId}", [
            'auto_execute' => true,
            'execution_instance_id' => $this->instanceA,
            'execution_notional' => '300',
            'execution_min_confidence' => 65,
            'execution_daily_limit' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('data.auto_execute', true)
            ->assertJsonPath('data.execution_instance_id', $this->instanceA)
            ->assertJsonPath('data.execution_min_confidence', 65)
            ->assertJsonPath('data.execution_daily_limit', 3);

        $row = DB::table('signal_subscriptions')->where('id', $subscriptionId)->first();
        self::assertSame(1, (int) $row->auto_execute);
        self::assertSame($this->instanceA, (int) $row->execution_instance_id);
        self::assertSame(0, bccomp((string) $row->execution_notional, '300', 8));

        // 关闭自动跟单时不要求重新提供实例
        $this->patchJson("/api/v1/signal-subscriptions/{$subscriptionId}", [
            'auto_execute' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.auto_execute', false);
    }

    public function test_update_cannot_enable_auto_follow_without_any_instance(): void
    {
        Sanctum::actingAs($this->userA);

        $subscriptionId = $this->createSubscriptionRow(self::TENANT_A);

        $this->patchJson("/api/v1/signal-subscriptions/{$subscriptionId}", [
            'auto_execute' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('execution_instance_id');
    }

    public function test_index_is_tenant_isolated(): void
    {
        $ownId = $this->createSubscriptionRow(self::TENANT_A);
        $this->createSubscriptionRow(self::TENANT_B, $this->instanceB);

        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/v1/signal-subscriptions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        self::assertSame($ownId, (int) $response->json('data.0.id'));
    }

    public function test_cross_tenant_subscription_is_not_found(): void
    {
        $foreignId = $this->createSubscriptionRow(self::TENANT_B, $this->instanceB);

        Sanctum::actingAs($this->userA);

        $this->getJson("/api/v1/signal-subscriptions/{$foreignId}")->assertNotFound();

        $this->patchJson("/api/v1/signal-subscriptions/{$foreignId}", ['active' => false])
            ->assertNotFound();

        $this->deleteJson("/api/v1/signal-subscriptions/{$foreignId}")->assertNotFound();
    }

    public function test_destroy_deletes_own_subscription(): void
    {
        $subscriptionId = $this->createSubscriptionRow(self::TENANT_A);

        Sanctum::actingAs($this->userA);

        $this->deleteJson("/api/v1/signal-subscriptions/{$subscriptionId}")
            ->assertStatus(204);

        self::assertNull(DB::table('signal_subscriptions')->where('id', $subscriptionId)->first());
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'BTC 自动跟单',
            'symbols' => ['BTCUSDT'],
            'intervals' => ['5m'],
            'ai_prompter' => 'deepseek',
            'rule_fallback_enabled' => true,
            'channels' => ['in_app'],
            'active' => true,
            'auto_execute' => true,
            'execution_instance_id' => $this->instanceA,
            'execution_notional' => '250',
            'execution_min_confidence' => 70,
            'execution_daily_limit' => 5,
        ], $overrides);
    }

    private function createSubscriptionRow(int $tenantId, ?int $instanceId = null): int
    {
        return (int) DB::table('signal_subscriptions')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => 'sub-'.$tenantId,
            'symbols' => json_encode(['BTCUSDT']),
            'intervals' => json_encode(['5m']),
            'ai_prompter' => 'deepseek',
            'rule_fallback_enabled' => 1,
            'channels' => json_encode(['in_app']),
            'active' => 1,
            'auto_execute' => $instanceId !== null ? 1 : 0,
            'execution_instance_id' => $instanceId,
            'execution_min_confidence' => 0,
            'execution_daily_limit' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAccount(int $tenantId, string $label, \DateTimeInterface $now): int
    {
        return (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => $tenantId,
            'exchange_id' => self::EXCHANGE_ID,
            'label' => $label,
            'market' => 'spot',
            'encrypted_api_key' => 'cipher',
            'encrypted_api_secret' => 'cipher',
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createInstance(int $tenantId, int $accountId, \DateTimeInterface $now): int
    {
        return (int) DB::table('strategy_instances')->insertGetId([
            'tenant_id' => $tenantId,
            'strategy_id' => $this->strategyId,
            'exchange_account_id' => $accountId,
            'symbol' => 'BTCUSDT',
            'interval' => '5m',
            'params' => json_encode([]),
            'allocated_capital' => '1000',
            'status' => 'running',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
