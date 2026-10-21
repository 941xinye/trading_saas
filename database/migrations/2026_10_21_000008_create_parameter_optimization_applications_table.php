<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 寻优结果应用历史（append-only + 可回滚一次）。
 *
 * 每次应用记录 rank（Top N 名次）、应用前后完整 params 快照与操作人；
 * 回滚将实例 params 恢复为 params_before，并标记 rolled_back_at。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parameter_optimization_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('optimization_id');
            $table->unsignedBigInteger('strategy_instance_id')->nullable();
            // Top N 名次（1 = best）
            $table->unsignedSmallInteger('rank')->default(1);
            $table->json('params_before')->nullable();
            $table->json('params_after')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table
                ->foreign('optimization_id')
                ->references('id')
                ->on('parameter_optimizations')
                ->cascadeOnDelete();
            $table
                ->foreign('strategy_instance_id')
                ->references('id')
                ->on('strategy_instances')
                ->nullOnDelete();
            $table
                ->foreign('applied_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'optimization_id'], 'idx_poa_tenant_opt');
            $table->index(['tenant_id', 'strategy_instance_id'], 'idx_poa_tenant_instance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameter_optimization_applications');
    }
};
