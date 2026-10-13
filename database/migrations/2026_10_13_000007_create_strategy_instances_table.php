<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('strategy_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('strategy_id');
            $table->unsignedBigInteger('exchange_account_id');
            $table->string('symbol', 20);
            $table->string('interval', 5);
            $table->json('params');
            $table->decimal('allocated_capital', 20, 8);
            $table->integer('leverage')->nullable();
            $table->enum('status', ['stopped', 'starting', 'running', 'paused_by_breaker', 'error'])->default('stopped');
            $table->enum('breaker_state', ['closed', 'open', 'half_open'])->default('closed');
            $table->json('position_snapshot')->nullable();
            $table->decimal('realized_pnl', 20, 8)->default(0);
            $table->decimal('unrealized_pnl', 20, 8)->default(0);
            $table->decimal('max_drawdown_pct', 6, 2)->default(0);
            $table->integer('consecutive_losses')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table
                ->foreign('strategy_id')
                ->references('id')
                ->on('strategies')
                ->restrictOnDelete();
            $table
                ->foreign('exchange_account_id')
                ->references('id')
                ->on('exchange_accounts')
                ->cascadeOnDelete();

            $table->unique(['tenant_id', 'strategy_id', 'symbol'], 'uniq_si_tenant_strategy_symbol');
            $table->index(['tenant_id', 'status'], 'idx_si_tenant_status');
            $table->index(['tenant_id', 'breaker_state'], 'idx_si_tenant_breaker');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategy_instances');
    }
};
