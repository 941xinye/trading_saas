<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * MySQL 方言/精度样板测试（P5.5 步骤 0，@group mysql）。
 *
 * 默认 phpunit.xml 跑 sqlite :memory:，本文件全部 skip；
 * 需要以真实 MySQL 8.x 验证迁移方言与 decimal(20,8) 精度时：
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=<test_db> \
 *   DB_USERNAME=root DB_PASSWORD=*** php artisan test --group=mysql
 * CI 的 mysql job 以环境变量注入上述连接参数。
 */
#[Group('mysql')]
class MysqlConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('默认数据库连接不是 mysql（设置 DB_CONNECTION=mysql 后运行本组）。');
        }
    }

    public function test_server_version_is_mysql_8x(): void
    {
        $version = (string) DB::scalar('SELECT VERSION()');

        static::assertStringStartsWith('8.', $version);
    }

    public function test_refresh_database_migrated_core_tables(): void
    {
        static::assertTrue(Schema::hasTable('tenants'));
        static::assertTrue(Schema::hasTable('meter_events'));
        static::assertTrue(Schema::hasTable('liquidation_events'));
    }

    public function test_decimal_20_8_precision_is_preserved(): void
    {
        // 交易金额口径：decimal(20,8)，验证 MySQL 下 8 位小数不丢精度（sqlite 近似类型无法保证）
        DB::table('tenants')->insert([
            'id' => 990009,
            'name' => 'mysql-precision',
            'slug' => 'mysql-precision-990009',
            'exchange_dek' => 'test-dek',
            'status' => 'active',
            'plan_code' => 'free',
            'created_at' => '2026-10-15 08:00:00',
            'updated_at' => '2026-10-15 08:00:00',
        ]);
        DB::table('meter_events')->insert([
            'tenant_id' => 990009,
            'event_type' => 'order_placed',
            'quantity' => '0.00000001',
            'frozen_amount' => '0.00000001',
            'status' => 'settled',
            'settled_amount' => '0.00000001',
            'ref_id' => 'precision-1',
            'occurred_at' => '2026-10-15 08:00:00',
            'created_at' => '2026-10-15 08:00:00',
            'updated_at' => '2026-10-15 08:00:00',
        ]);

        $quantity = (string) DB::table('meter_events')->where('ref_id', 'precision-1')->value('quantity');

        static::assertSame('0.00000001', $quantity);
    }
}
