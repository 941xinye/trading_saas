<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * market:feed --dry-run 实例扫描口径测试（2026-10-21 热启停）。
 *
 * 验证：
 *  1. starting（后台点「启动」后的中间态）与各暂停态都在扫描集合内
 *  2. stopped 不在扫描集合内
 *  3. --dry-run 不依赖 swoole 扩展（扩展缺失的开发机/CI 也可执行）
 */
class MarketFeedDryRunTest extends TestCase
{
    private const TENANT_ID = 990070;

    private const ACCOUNT_ID = 990170;

    /** @var array<string,int> status → instance id */
    private array $instances = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->scaffold();
    }

    public function test_dry_run_lists_live_statuses_but_not_stopped(): void
    {
        $exitCode = Artisan::call('market:feed', ['--dry-run' => true]);
        $output = Artisan::output();

        static::assertSame(0, $exitCode, '--dry-run 应在无 swoole 环境下也返回成功');

        foreach (['starting', 'running', 'paused_by_breaker', 'paused_by_position_risk', 'paused_by_reconcile'] as $status) {
            static::assertStringContainsString(
                (string) $this->instances[$status],
                $output,
                "status={$status} 的实例应进入启动清单",
            );
        }

        static::assertStringNotContainsString(
            (string) $this->instances['stopped'],
            $output,
            'stopped 实例不应进入启动清单',
        );
    }

    // ------------------------------------------------------------------
    // 辅助
    // ------------------------------------------------------------------

    private function scaffold(): void
    {
        $now = now();

        DB::table('tenants')->insert([
            'id' => self::TENANT_ID,
            'name' => 'poc-market-feed-990070',
            'slug' => 'poc-market-feed-990070',
            'exchange_dek' => 'dummy-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $exchangeId = (int) DB::table('exchanges')->insertGetId([
            'code' => 'binance', 'name' => 'Binance',
            'base_url' => 'https://example.invalid', 'ws_url' => 'wss://example.invalid',
            'markets' => json_encode(['spot']),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $strategyId = (int) DB::table('strategies')->insertGetId([
            'code' => 'spot_grid', 'name' => '现货网格', 'category' => 'spot',
            'risk_profile' => 'conservative', 'default_params' => json_encode([]),
            'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('exchange_accounts')->insert([
            'id' => self::ACCOUNT_ID,
            'tenant_id' => self::TENANT_ID,
            'exchange_id' => $exchangeId,
            'label' => 'poc-market-feed',
            'market' => 'spot',
            'encrypted_api_key' => 'poc',
            'encrypted_api_secret' => 'poc',
            'permissions' => json_encode(['spot']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $statuses = ['starting', 'running', 'stopped', 'paused_by_breaker', 'paused_by_position_risk', 'paused_by_reconcile'];

        foreach ($statuses as $index => $status) {
            $symbol = 'POCFEED'.$index.'USDT';
            $id = (int) DB::table('strategy_instances')->insertGetId([
                'tenant_id' => self::TENANT_ID,
                'strategy_id' => $strategyId,
                'exchange_account_id' => self::ACCOUNT_ID,
                'symbol' => $symbol,
                'interval' => '5m',
                'params' => json_encode([]),
                'allocated_capital' => '100',
                'status' => $status,
                'started_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->instances[$status] = $id;
        }
    }
}
