<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订单名义额快照。
 *
 * 自动跟单单日资金总额上限（FR-027）需要按当日 sig_ 订单汇总名义额；
 * quantity × price 在 pending 阶段 price 为空，无法可靠估算，故落库时记录
 * qty × 参考价，成交后回写 filled_qty × avg_fill_price。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategy_orders', function (Blueprint $table) {
            $table->decimal('notional', 20, 8)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('strategy_orders', function (Blueprint $table) {
            $table->dropColumn('notional');
        });
    }
};
