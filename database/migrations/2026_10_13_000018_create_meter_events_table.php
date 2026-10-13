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
        Schema::create('meter_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('event_type', 30);
            $table->decimal('quantity', 20, 8);
            $table->decimal('frozen_amount', 20, 8)->nullable();
            $table->decimal('settled_amount', 20, 8)->nullable();
            $table->enum('status', ['frozen', 'settled', 'reversed'])->default('frozen');
            $table->string('ref_id', 60)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'status', 'occurred_at'], 'idx_me_tenant_status_occurred');
            $table->index('ref_id', 'idx_me_ref_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meter_events');
    }
};
