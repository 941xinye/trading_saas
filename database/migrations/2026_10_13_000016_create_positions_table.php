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
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('exchange_account_id');
            $table->unsignedBigInteger('strategy_instance_id')->nullable();
            $table->string('symbol', 20);
            $table->enum('side', ['long', 'short']);
            $table->decimal('size', 20, 8);
            $table->decimal('entry_price', 20, 8);
            $table->decimal('mark_price', 20, 8);
            $table->decimal('liquidation_price', 20, 8)->nullable();
            $table->integer('leverage')->default(1);
            $table->decimal('unrealized_pnl', 20, 8)->default(0);
            $table->decimal('margin', 20, 8)->default(0);
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
            $table
                ->foreign('strategy_instance_id')
                ->references('id')
                ->on('strategy_instances')
                ->cascadeOnDelete();

            $table->unique(['tenant_id', 'exchange_account_id', 'symbol'], 'uniq_pos_tenant_account_symbol');
            $table->index('strategy_instance_id', 'idx_pos_strategy_instance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
