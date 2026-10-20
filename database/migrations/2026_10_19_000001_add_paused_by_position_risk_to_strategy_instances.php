<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * strategy_instances.status 增加 paused_by_position_risk（spec 005）。
 *
 * 与 paused_by_breaker / paused_by_reconcile 正交：
 *  - paused_by_breaker：账户级熔断，停开新仓
 *  - paused_by_reconcile：账实对账 critical，暂停
 *  - paused_by_position_risk：仓位紧急风险（强平价逼近），全平后暂停待人工确认
 *
 * MySQL：直接 MODIFY 枚举。
 * SQLite（测试库）：enum 落为带 CHECK 的 varchar，不支持 ALTER COLUMN，
 * 按影子表流程（与 2026_10_15_000002 同范式）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                "ALTER TABLE strategy_instances MODIFY status ENUM('stopped','starting','running','paused_by_breaker','paused_by_reconcile','paused_by_position_risk','error') NOT NULL DEFAULT 'stopped'"
            );

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::create('strategy_instances__posrisk', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('strategy_id');
            $table->unsignedBigInteger('exchange_account_id');
            $table->string('symbol', 20);
            $table->string('interval', 5);
            $table->json('params');
            $table->decimal('allocated_capital', 20, 8);
            $table->integer('leverage')->nullable();
            $table->enum('status', [
                'stopped', 'starting', 'running',
                'paused_by_breaker', 'paused_by_reconcile',
                'paused_by_position_risk', 'error',
            ])->default('stopped');
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
        });

        DB::statement('INSERT INTO strategy_instances__posrisk SELECT * FROM strategy_instances');
        Schema::drop('strategy_instances');
        Schema::rename('strategy_instances__posrisk', 'strategy_instances');

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
                "UPDATE strategy_instances SET status = 'error' WHERE status = 'paused_by_position_risk'"
            );
            DB::statement(
                "ALTER TABLE strategy_instances MODIFY status ENUM('stopped','starting','running','paused_by_breaker','paused_by_reconcile','error') NOT NULL DEFAULT 'stopped'"
            );
        }
    }
};
