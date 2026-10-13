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
        Schema::create('balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('exchange_account_id');
            $table->timestamp('snapshot_at');
            $table->decimal('total_equity_usdt', 20, 8);
            $table->decimal('total_margin_usdt', 20, 8);
            $table->decimal('available_usdt', 20, 8);
            $table->decimal('unrealized_pnl', 20, 8);
            $table->json('assets')->nullable();
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table
                ->foreign('exchange_account_id')
                ->references('id')
                ->on('exchange_accounts')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'exchange_account_id', 'snapshot_at'], 'idx_bs_tenant_account_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balance_snapshots');
    }
};
