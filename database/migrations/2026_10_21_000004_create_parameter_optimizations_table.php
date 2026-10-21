<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parameter_optimizations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('strategy_code', 32);
            $table->string('symbol', 32);
            $table->string('interval', 8);
            $table->dateTime('from_utc');
            $table->dateTime('to_utc');
            // grid = 全区间逐组合；walk_forward = 分折训练/样本外
            $table->string('mode', 16)->default('grid');
            $table->unsignedTinyInteger('folds')->default(3);
            $table->string('objective', 32)->default('total_return_pct');
            // 寻优网格定义（显式值列表或 min/max/step）
            $table->json('grid');
            // MatchingAssumptions 快照（与回测报告同口径）
            $table->json('assumptions');
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            // 最优参数组与得分（得分口径见 objective；无法评分时 null）
            $table->json('best_params')->nullable();
            $table->decimal('best_score', 20, 8)->nullable();
            // Top N / 分折明细汇总
            $table->json('report')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'status'], 'idx_po_tenant_status');
            $table->index(['strategy_code', 'symbol'], 'idx_po_strategy_symbol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameter_optimizations');
    }
};
