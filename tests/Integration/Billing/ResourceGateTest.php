<?php

declare(strict_types=1);

namespace Tests\Integration\Billing;

use App\Domain\Billing\EntitlementException;
use App\Domain\Billing\Metering\QuotaExceededException;
use App\Domain\Billing\QuotaGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 资源数/渠道门（迁自 poc:verify-billing 第 7 段）。
 *
 *  - QuotaGuard：free 拒绝 mail / 放行 log；当前数=上限时拒绝新建
 *  - poc:signal-setup 端到端：mail 整体 FAILURE 且无落行；log SUCCESS 并落 active 订阅
 *
 * SQLite + RefreshDatabase；命令执行期间临时置空 DeepSeek key 保持离线（不触发探测 HTTP）。
 */
class ResourceGateTest extends TestCase
{
    private const TENANT_ID = 990035;

    protected function setUp(): void
    {
        parent::setUp();

        $now = Carbon::create(2026, 10, 15, 8, 0, 0, 'UTC');
        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-billing-gate-990035',
            'slug' => 'poc-billing-gate-990035',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_mail_channel_rejected_and_log_channel_allowed_for_free_plan(): void
    {
        $guard = app(QuotaGuard::class);

        $threw = false;
        try {
            $guard->assertChannels(self::TENANT_ID, ['mail']);
        } catch (EntitlementException) {
            $threw = true;
        }
        static::assertTrue($threw, 'free 套餐订阅 mail 渠道必须被拒');

        // log 为系统内部渠道，不抛即通过
        $guard->assertChannels(self::TENANT_ID, ['log']);
    }

    public function test_resource_count_at_limit_blocks_creation(): void
    {
        $guard = app(QuotaGuard::class);

        $threw = false;
        try {
            // free 上限 max_signal_subscriptions=1，当前已占用 1
            $guard->assertResourceCount(self::TENANT_ID, 'max_signal_subscriptions', 1);
        } catch (QuotaExceededException) {
            $threw = true;
        }
        static::assertTrue($threw, '当前数=上限时新建必须被拒');
    }

    public function test_signal_setup_command_rejects_mail_and_allows_log_end_to_end(): void
    {
        $savedKey = config('ai.providers.deepseek.api_key');
        config(['ai.providers.deepseek.api_key' => '']);

        try {
            $before = (int) DB::table('signal_subscriptions')->where('tenant_id', self::TENANT_ID)->count();

            $this->artisan('poc:signal-setup', [
                '--tenant' => self::TENANT_ID,
                '--symbol' => 'ETHUSDT',
                '--channel' => 'mail',
            ])->assertExitCode(1);

            $afterMail = (int) DB::table('signal_subscriptions')->where('tenant_id', self::TENANT_ID)->count();
            static::assertSame($before, $afterMail, 'mail 被拒后不得落订阅行');

            $this->artisan('poc:signal-setup', [
                '--tenant' => self::TENANT_ID,
                '--symbol' => 'ETHUSDT',
                '--channel' => 'log',
            ])->assertExitCode(0);

            static::assertTrue(
                DB::table('signal_subscriptions')
                    ->where('tenant_id', self::TENANT_ID)
                    ->where('name', 'poc-signal-ETHUSDT')
                    ->where('active', 1)
                    ->exists(),
                'log 渠道必须落 active 订阅',
            );
        } finally {
            config(['ai.providers.deepseek.api_key' => $savedKey]);
        }
    }
}
