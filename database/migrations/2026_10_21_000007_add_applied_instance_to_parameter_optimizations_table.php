<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 寻优结果落地追踪（回测 → 寻优 → 实盘闭环）。
 *
 * 记录最优参数最近一次被应用到哪个策略实例；实例删除时置空，保留任务本身。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parameter_optimizations', function (Blueprint $table) {
            $table->unsignedBigInteger('applied_instance_id')->nullable()->after('finished_at');
            $table->dateTime('applied_at')->nullable()->after('applied_instance_id');

            $table
                ->foreign('applied_instance_id')
                ->references('id')
                ->on('strategy_instances')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('parameter_optimizations', function (Blueprint $table) {
            $table->dropForeign(['applied_instance_id']);
            $table->dropColumn(['applied_instance_id', 'applied_at']);
        });
    }
};
