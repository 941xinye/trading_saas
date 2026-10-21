<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 寻优请求快照补全（P11 UI/API）。
 *
 * 异步队列执行时只持有 optimization_id，需从记录还原完整请求：
 *  - base_params：不参与寻优的固定参数（对齐 backtest:optimize --params）
 *  - top_n：发起时选择的 Top N（报告 top 截断口径）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parameter_optimizations', function (Blueprint $table) {
            $table->json('base_params')->nullable()->after('grid');
            $table->unsignedSmallInteger('top_n')->default(5)->after('objective');
        });
    }

    public function down(): void
    {
        Schema::table('parameter_optimizations', function (Blueprint $table) {
            $table->dropColumn(['base_params', 'top_n']);
        });
    }
};
