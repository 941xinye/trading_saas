<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\ExchangeAccountResource\Pages\CreateExchangeAccount;
use App\Filament\Admin\Resources\ExchangeAccountResource\Pages\EditExchangeAccount;
use App\Models\ExchangeAccount;
use App\Models\User;
use App\Support\KeyResolver;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin 面板交易所账户页加密落库测试（default 组）。
 *
 * 验证表单虚拟字段（api_key/api_secret）经 ExchangeAccountService 加密后
 * 才写入 encrypted_* 列；编辑留空保留原密文。
 */
class ExchangeAccountPageTest extends TestCase
{
    private const TENANT_ID = 990083;

    private const EXCHANGE_ID = 990183;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(KeyResolver::class, function ($mock): void {
            $mock->shouldReceive('masterKey')->andReturn(str_repeat('k', 32));
            $mock->shouldReceive('tenantDek')->andReturn(str_repeat('d', 32));
        });

        $now = now();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-filament-990083',
            'slug' => 'poc-filament-990083',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('exchanges')->insert([
            'id' => self::EXCHANGE_ID,
            'code' => 'binance-filament-990183',
            'name' => 'Binance',
            'base_url' => 'https://example.invalid',
            'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => self::TENANT_ID,
            'role' => 'tenant_admin',
        ]);

        $this->actingAs($this->user);
        TenantContext::initialize(self::TENANT_ID);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_create_page_encrypts_api_credentials(): void
    {
        Livewire::test(CreateExchangeAccount::class)
            ->fillForm([
                'exchange_id' => self::EXCHANGE_ID,
                'label' => 'filament-account',
                'market' => 'spot',
                'api_key' => 'plain-key',
                'api_secret' => 'plain-secret',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = DB::table('exchange_accounts')->where('label', 'filament-account')->first();

        static::assertNotNull($row);
        static::assertSame(self::TENANT_ID, (int) $row->tenant_id);
        static::assertNotSame('plain-key', $row->encrypted_api_key);
        static::assertNotSame('plain-secret', $row->encrypted_api_secret);
    }

    public function test_edit_without_credentials_keeps_existing_ciphertext(): void
    {
        $account = ExchangeAccount::query()->create([
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => self::EXCHANGE_ID,
            'label' => 'seeded-account',
            'market' => 'spot',
            'encrypted_api_key' => 'cipher-key',
            'encrypted_api_secret' => 'cipher-secret',
            'permissions' => [],
            'status' => 'active',
        ]);

        Livewire::test(EditExchangeAccount::class, ['record' => $account->getKey()])
            ->fillForm(['label' => 'renamed-account'])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();

        static::assertSame('renamed-account', $account->label);
        static::assertSame('cipher-key', $account->encrypted_api_key);
        static::assertSame('cipher-secret', $account->encrypted_api_secret);
    }
}
