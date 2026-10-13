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
        Schema::create('circuit_breaker_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('strategy_instance_id');
            $table->enum('from_state', ['closed', 'open', 'half_open']);
            $table->enum('to_state', ['closed', 'open', 'half_open']);
            $table->string('rule_type', 40);
            $table->decimal('trigger_value', 10, 4);
            $table->decimal('threshold_value', 10, 4);
            $table->string('action_taken', 60)->nullable();
            $table->timestamp('occurred_at');
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

            $table->index(['tenant_id', 'strategy_instance_id', 'occurred_at'], 'idx_cbe_tenant_instance_occurred');
            $table->index(['tenant_id', 'to_state', 'occurred_at'], 'idx_cbe_tenant_state_occurred');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('circuit_breaker_events');
    }
};
