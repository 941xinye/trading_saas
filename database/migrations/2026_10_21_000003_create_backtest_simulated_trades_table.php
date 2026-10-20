<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_simulated_trades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('backtest_run_id');
            // 冗余隔离
            $table->unsignedBigInteger('tenant_id');
            // run 内顺序号（等价性对比锚点）
            $table->unsignedInteger('seq');
            // UTC ms（对齐 kline_bars 先例）
            $table->bigInteger('bar_open_time');
            $table->string('side', 8);
            $table->decimal('qty', 30, 12);
            // 含滑点成交价
            $table->decimal('price', 30, 12);
            $table->decimal('fee', 20, 8);
            // 平仓实现盈亏（开仓为 0）
            $table->decimal('realized_pnl', 20, 8);
            // append-only：无 updated_at
            $table->timestamp('created_at')->useCurrent();

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

            $table->index(['backtest_run_id', 'seq'], 'idx_bst_run_seq');
            $table->index(['tenant_id', 'backtest_run_id'], 'idx_bst_tenant_run');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_simulated_trades');
    }
};
