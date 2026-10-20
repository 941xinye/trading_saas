<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            // null = inline DSL 回测（非实例）
            $table->unsignedBigInteger('strategy_instance_id')->nullable();
            $table->string('strategy_code', 32);
            // 发起时刻完整参数快照（含 dsl_script 全文；实例后续变更/删除不影响进行中回测）
            $table->json('params_snapshot');
            // MatchingAssumptions 快照（fee_rate/slippage_bps/initial_capital/口径说明）
            $table->json('assumptions');
            $table->string('symbol', 32);
            $table->string('interval', 8);
            // UTC，区间 [from, to)
            $table->dateTime('from_utc');
            $table->dateTime('to_utc');
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->text('error_message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table
                ->foreign('strategy_instance_id')
                ->references('id')
                ->on('strategy_instances')
                ->nullOnDelete();

            $table->index(['tenant_id', 'status'], 'idx_br_tenant_status');
            $table->index(['status', 'updated_at'], 'idx_br_reap_stale');
            $table->index(['symbol', 'interval'], 'idx_br_symbol_interval');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_runs');
    }
};
