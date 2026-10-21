<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parameter_optimization_trials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('optimization_id');
            // 冗余隔离
            $table->unsignedBigInteger('tenant_id');
            // null = grid 模式全区间；walk_forward 下为折序号（从 0 起）
            $table->unsignedTinyInteger('fold_index')->nullable();
            $table->json('params');
            // true = 训练段，false = 样本外段（grid 模式恒为 true）
            $table->boolean('in_sample')->default(true);
            $table->enum('status', ['ok', 'failed'])->default('ok');
            // 参与排名的得分（null = 交易数不足或指标不可计算）
            $table->decimal('score', 20, 8)->nullable();
            $table->json('metrics')->nullable();
            $table->text('error_message')->nullable();
            // append-only：无 updated_at
            $table->timestamp('created_at')->useCurrent();

            $table
                ->foreign('optimization_id')
                ->references('id')
                ->on('parameter_optimizations')
                ->cascadeOnDelete();
            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->index(['optimization_id', 'fold_index'], 'idx_pot_opt_fold');
            $table->index(['tenant_id', 'optimization_id'], 'idx_pot_tenant_opt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameter_optimization_trials');
    }
};
