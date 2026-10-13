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
        Schema::create('strategy_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('strategy_instance_id');
            $table->string('exchange_order_id', 60)->nullable();
            $table->string('client_order_id', 60);
            $table->string('symbol', 20);
            $table->enum('side', ['buy', 'sell']);
            $table->enum('type', ['market', 'limit', 'stop', 'stop_market', 'take_profit']);
            $table->decimal('quantity', 20, 8);
            $table->decimal('price', 20, 8)->nullable();
            $table->decimal('stop_price', 20, 8)->nullable();
            $table->integer('leverage')->nullable();
            $table->boolean('reduce_only')->default(false);
            $table->enum('status', ['pending', 'submitted', 'partial', 'filled', 'canceled', 'rejected', 'failed'])->default('pending');
            $table->decimal('filled_qty', 20, 8)->default(0);
            $table->decimal('avg_fill_price', 20, 8)->nullable();
            $table->decimal('fee', 20, 8)->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table
                ->foreign('strategy_instance_id')
                ->references('id')
                ->on('strategy_instances')
                ->cascadeOnDelete();

            $table->unique(['tenant_id', 'client_order_id'], 'uniq_so_tenant_client_order');
            $table->index(['strategy_instance_id', 'status'], 'idx_so_instance_status');
            $table->index(['tenant_id', 'created_at'], 'idx_so_tenant_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategy_orders');
    }
};
