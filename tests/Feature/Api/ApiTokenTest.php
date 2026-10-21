<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sanctum 个人访问令牌管理。
 *
 * 覆盖：签发只回一次明文、哈希落库、列表不泄露密钥、只能吊销自己的 token、
 * 当前 token 注销、参数校验。
 */
final class ApiTokenTest extends TestCase
{
    private const TENANT_ID = 990501;

    private User $user;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'token-test',
            'slug' => 'token-test',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'pro',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => self::TENANT_ID,
            'role' => 'tenant_admin',
        ]);

        $this->otherUser = User::factory()->create([
            'tenant_id' => self::TENANT_ID,
            'role' => 'tenant_admin',
        ]);
    }

    public function test_create_returns_plain_text_once_and_stores_only_hash(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/tokens', [
            'name' => 'ci-pipeline',
            'expires_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'ci-pipeline')
            ->assertJsonPath('data.abilities.0', '*')
            ->assertJsonStructure(['data' => ['id', 'name', 'abilities', 'expires_at', 'plain_text_token']]);

        $plain = (string) $response->json('data.plain_text_token');
        $this->assertStringContainsString('|', $plain);

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $hash = explode('|', $plain, 2)[1];
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'ci-pipeline',
            'token' => hash('sha256', $hash),
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $plain]);
    }

    public function test_list_returns_only_own_tokens_without_secrets(): void
    {
        $this->user->createToken('mine');
        $this->otherUser->createToken('others');

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/tokens');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'mine')
            ->assertJsonPath('data.0.is_current', false)
            ->assertJsonMissingPath('data.0.token')
            ->assertJsonMissingPath('data.0.plain_text_token');
    }

    public function test_destroy_own_token_and_reject_cross_user(): void
    {
        $own = $this->user->createToken('mine')->accessToken;
        $foreign = $this->otherUser->createToken('others')->accessToken;

        Sanctum::actingAs($this->user);

        $this->deleteJson("/api/v1/tokens/{$own->id}")->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $own->id]);

        $this->deleteJson("/api/v1/tokens/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->id]);
    }

    public function test_destroy_current_with_real_bearer_token(): void
    {
        $created = $this->user->createToken('cli');
        $tokenId = $created->accessToken->id;
        $plain = $created->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->deleteJson('/api/v1/tokens/current')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_list_marks_current_token(): void
    {
        $created = $this->user->createToken('cli');

        $this->withHeader('Authorization', 'Bearer '.$created->plainTextToken)
            ->getJson('/api/v1/tokens')
            ->assertOk()
            ->assertJsonPath('data.0.id', $created->accessToken->id)
            ->assertJsonPath('data.0.is_current', true);
    }

    public function test_validation_rejects_missing_name_and_past_expiry(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/tokens', [])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->postJson('/api/v1/tokens', [
            'name' => 'expired',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
    }
}
