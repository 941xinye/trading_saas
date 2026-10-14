<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * strategy_instances.status 增加 paused_by_reconcile（P5.5 步骤 1）。
 *
 * 与 paused_by_breaker 正交：对账无权覆盖熔断暂停；恢复由人工显式执行，
 * 对账本身只暂停、从不自动恢复。
 *
 * MySQL：直接 MODIFY 枚举。
 * SQLite（测试库）：enum 落为带 CHECK 的 varchar，不支持 ALTER COLUMN，
 * 按 SQLite 官方推荐流程临时关 foreign_keys → 影子表复制 → 删旧改名 → 重建索引。
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE strategy_instances MODIFY status ENUM('stopped','starting','running','paused_by_breaker','paused_by_reconcile','error') NOT NULL DEFAULT 'stopped'"
            );

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        // 影子表：结构镜像 000007，仅 status 枚举增加 paused_by_reconcile
        Schema::create('strategy_instances__reconcile', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('strategy_id');
            $table->unsignedBigInteger('exchange_account_id');
            $table->string('symbol', 20);
            $table->string('interval', 5);
            $table->json('params');
            $table->decimal('allocated_capital', 20, 8);
            $table->integer('leverage')->nullable();
            $table->enum('status', ['stopped', 'starting', 'running', 'paused_by_breaker', 'paused_by_reconcile', 'error'])->default('stopped');
            $table->enum('breaker_state', ['closed', 'open', 'half_open'])->default('closed');
            $table->json('position_snapshot')->nullable();
            $table->decimal('realized_pnl', 20, 8)->default(0);
            $table->decimal('unrealized_pnl', 20, 8)->default(0);
            $table->decimal('max_drawdown_pct', 6, 2)->default(0);
            $table->integer('consecutive_losses')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('strategy_id')->references('id')->on('strategies')->restrictOnDelete();
            $table->foreign('exchange_account_id')->references('id')->on('exchange_accounts')->cascadeOnDelete();

            // 索引不在此创建：SQLite 索引名全局唯一，与旧表同名索引冲突；改名后再建
        });

        DB::statement('INSERT INTO strategy_instances__reconcile SELECT * FROM strategy_instances');
        Schema::drop('strategy_instances');
        Schema::rename('strategy_instances__reconcile', 'strategy_instances');

        DB::statement('CREATE UNIQUE INDEX uniq_si_tenant_strategy_symbol ON strategy_instances (tenant_id, strategy_id, symbol)');
        DB::statement('CREATE INDEX idx_si_tenant_status ON strategy_instances (tenant_id, status)');
        DB::statement('CREATE INDEX idx_si_tenant_breaker ON strategy_instances (tenant_id, breaker_state)');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                "UPDATE strategy_instances SET status = 'error' WHERE status = 'paused_by_reconcile'"
            );
            DB::statement(
                "ALTER TABLE strategy_instances MODIFY status ENUM('stopped','starting','running','paused_by_breaker','error') NOT NULL DEFAULT 'stopped'"
            );
        }
    }
};
