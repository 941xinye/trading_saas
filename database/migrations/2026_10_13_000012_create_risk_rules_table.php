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
        Schema::create('risk_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('strategy_instance_id')->nullable();
            $table->enum('rule_type', ['max_loss_per_trade', 'max_daily_loss', 'max_drawdown', 'consecutive_losses']);
            $table->decimal('threshold_value', 10, 4);
            $table->enum('threshold_unit', ['amount', 'percent', 'count']);
            $table->integer('cooldown_minutes')->default(60);
            $table->boolean('auto_recover')->default(true);
            $table->boolean('enabled')->default(true);
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

            $table->index(['tenant_id', 'strategy_instance_id', 'enabled'], 'idx_rr_tenant_instance_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_rules');
    }
};
