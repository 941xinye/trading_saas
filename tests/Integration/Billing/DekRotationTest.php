<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Domain\Tenancy\TenantKeyResolver;
use App\Support\AesGcmCrypt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\ChecksRedisAvailability;
use Tests\Support\RedisCleanup;
use Tests\TestCase;

/**
 * 租户 DEK 轮换（迁自 poc:verify-billing 第 8 段，@group redis）。
 *
 * rotateDek 用 Redis SET NX 做防并发锁，故归 redis 组；加解密本身离线。
 * 断言：DEK/库指纹更新、新旧 DEK 解密凭据一致、旧密文全表零残留、租户 B 不受影响。
 */
#[Group('redis')]
class DekRotationTest extends TestCase
{
    use ChecksRedisAvailability;
    use RedisCleanup;

    private const TENANT_A = 990036;

    private const TENANT_B = 990037;

    /** 固定测试 KEK（32 字节 hex），不依赖本机 .env */
    private const TEST_KEK_HEX = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private TenantKeyResolver $resolver;

    private AesGcmCrypt $crypt;

    private int $accountA;

    private int $accountB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireRedis();

        config(['exchange.master_key' => self::TEST_KEK_HEX]);
        $this->cleanupRedis($this->redisKeys());

        $now = Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC');
        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']), 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->resolver = app(TenantKeyResolver::class);
        $this->crypt = app(AesGcmCrypt::class);

        $this->accountA = $this->createTenantWithAccount(self::TENANT_A, 'poc-billing-dek-a-990036', $exchangeId, 'poc-key-A', 'poc-secret-A');
        $this->accountB = $this->createTenantWithAccount(self::TENANT_B, 'poc-billing-dek-b-990037', $exchangeId, 'poc-key-B', 'poc-secret-B');
    }

    protected function tearDown(): void
    {
        // 坑 #24：外部清理先于 parent::tearDown()（trait 内部静默降级）
        $this->cleanupRedis($this->redisKeys());

        parent::tearDown();
    }

    public function test_rotation_reencrypts_credentials_without_affecting_other_tenant(): void
    {
        $dekAOld = $this->resolver->tenantDek(self::TENANT_A);
        $dekBOld = $this->resolver->tenantDek(self::TENANT_B);
        $storedOld = (string) DB::table('tenants')->where('id', self::TENANT_A)->value('exchange_dek');

        $accountRow = DB::table('exchange_accounts')->where('id', $this->accountA)->first();
        $plainKeyOld = $this->crypt->decrypt((string) $accountRow->encrypted_api_key, $dekAOld);
        $plainSecretOld = $this->crypt->decrypt((string) $accountRow->encrypted_api_secret, $dekAOld);
        $oldKeyCipher = (string) $accountRow->encrypted_api_key;
        $oldSecretCipher = (string) $accountRow->encrypted_api_secret;

        $fingerprint = $this->resolver->rotateDek(self::TENANT_A);

        // tenants.exchange_dek 已更新（指纹非空）
        $storedNew = (string) DB::table('tenants')->where('id', self::TENANT_A)->value('exchange_dek');
        static::assertNotSame('', $storedNew);
        static::assertNotSame($storedOld, $storedNew);
        static::assertNotSame('', $fingerprint);

        // 新 DEK 与旧 DEK 不同，且新 DEK 解密结果与轮换前一致
        $dekANew = $this->resolver->tenantDek(self::TENANT_A);
        static::assertNotSame($dekAOld, $dekANew);

        $accountRow = DB::table('exchange_accounts')->where('id', $this->accountA)->first();
        $plainKeyNew = $this->crypt->decrypt((string) $accountRow->encrypted_api_key, $dekANew);
        $plainSecretNew = $this->crypt->decrypt((string) $accountRow->encrypted_api_secret, $dekANew);
        static::assertSame('poc-key-A', $plainKeyNew);
        static::assertSame($plainKeyOld, $plainKeyNew);
        static::assertSame('poc-secret-A', $plainSecretNew);
        static::assertSame($plainSecretOld, $plainSecretNew);

        // 两列旧密文均已消失
        static::assertNotSame($oldKeyCipher, (string) $accountRow->encrypted_api_key);
        static::assertNotSame($oldSecretCipher, (string) $accountRow->encrypted_api_secret);

        // 全表扫描旧密文残留=0
        $leftover = DB::table('exchange_accounts')
            ->where('encrypted_api_key', $oldKeyCipher)
            ->orWhere('encrypted_api_secret', $oldSecretCipher)
            ->count();
        static::assertSame(0, $leftover, '全表不得残留旧密文');

        // 租户 B 的 DEK/凭据不受影响
        $dekBAfter = $this->resolver->tenantDek(self::TENANT_B);
        $bRow = DB::table('exchange_accounts')->where('id', $this->accountB)->first();
        $bPlain = $this->crypt->decrypt((string) $bRow->encrypted_api_key, $dekBAfter);
        static::assertSame($dekBOld, $dekBAfter, '租户 B 的 DEK 不应变化');
        static::assertSame('poc-key-B', $bPlain);
    }

    private function createTenantWithAccount(int $tenantId, string $slug, int $exchangeId, string $apiKeyPlain, string $secretPlain): int
    {
        $now = Carbon::now();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => $slug,
            'slug' => $slug,
            'exchange_dek' => '',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $dek = $this->resolver->tenantDek($tenantId);

        return (int) DB::table('exchange_accounts')->insertGetId([
            'tenant_id' => $tenantId,
            'exchange_id' => $exchangeId,
            'label' => 'poc',
            'market' => 'spot',
            'encrypted_api_key' => $this->crypt->encrypt($apiKeyPlain, $dek),
            'encrypted_api_secret' => $this->crypt->encrypt($secretPlain, $dek),
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return string[]
     */
    private function redisKeys(): array
    {
        return [
            'tenant:'.self::TENANT_A.':dek:rotation',
            'tenant:'.self::TENANT_B.':dek:rotation',
        ];
    }
}
