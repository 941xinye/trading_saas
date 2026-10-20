<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_reports', function (Blueprint $table) {
            $table->id();
            // 与 run 1:1（completed 时单事务写入）
            $table->unsignedBigInteger('backtest_run_id')->unique();
            // 冗余保查询隔离简单（不 join runs）
            $table->unsignedBigInteger('tenant_id');
            $table->decimal('initial_capital', 20, 8);
            $table->decimal('final_equity', 20, 8);
            $table->decimal('total_return_pct', 20, 8);
            $table->decimal('annualized_return_pct', 20, 8);
            // 零交易/零方差时 null（不除零）
            $table->decimal('sharpe_ratio', 20, 8)->nullable();
            $table->decimal('max_drawdown_pct', 20, 8)->default(0);
            // 零平仓 null
            $table->decimal('win_rate_pct', 20, 8)->nullable();
            // 零亏损平仓 null（口径注明「无穷大记 null」）
            $table->decimal('profit_factor', 20, 8)->nullable();
            $table->unsignedInteger('trade_count')->default(0);
            $table->decimal('total_fee', 20, 8)->default(0);
            $table->unsignedInteger('bar_count');
            // 实际 bar 数/区间理论 bar 数 ×100；< 阈值不阻断但标注（FR-013）
            $table->decimal('data_coverage_pct', 5, 2);
            // [[barTime_ms, equity_str], ...]；字符串保值（对齐 CH output_format_json_quote_decimals 先例）
            $table->json('equity_curve');
            $table->timestamps();

            $table
                ->foreign('backtest_run_id')
                ->references('id')
                ->on('backtest_runs')
                ->cascadeOnDelete();
            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'created_at'], 'idx_brep_tenant_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_reports');
    }
};
