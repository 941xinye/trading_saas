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
        Schema::create('risk_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('circuit_breaker_event_id')->nullable();
            $table->unsignedBigInteger('instance_id');
            $table->string('channel', 20);
            $table->enum('status', ['queued', 'sent', 'failed', 'deduped'])->default('queued');
            $table->string('provider_message_id', 100)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table
                ->foreign('circuit_breaker_event_id')
                ->references('id')
                ->on('circuit_breaker_events')
                ->nullOnDelete();

            $table->index(['tenant_id', 'status', 'created_at'], 'idx_rn_tenant_status_created');
            $table->index(['circuit_breaker_event_id'], 'idx_rn_cbe');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_notifications');
    }
};
