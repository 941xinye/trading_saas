<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订阅自动跟单配置版本号（FR-029）。
 *
 * 入队时携带版本号，Job 执行前比对；配置在入队后被修改则跳过本次执行，
 * 避免使用「已过期的决定」下单。版本由模型 updating 钩子自增。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('execution_config_version')->default(1)->after('execution_daily_limit');
        });
    }

    public function down(): void
    {
        Schema::table('signal_subscriptions', function (Blueprint $table) {
            $table->dropColumn('execution_config_version');
        });
    }
};
