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
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('payment_invoice_id')->nullable();
            $table->string('channel_code', 30);
            $table->string('ref', 64)->nullable();
            $table->string('event_type', 60);
            $table->boolean('signature_valid');
            $table->enum('status', ['received', 'processed', 'idempotent_skipped', 'rejected', 'error'])->default('received');
            $table->string('processed_key', 120)->nullable()->unique();
            $table->json('raw_payload');
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table
                ->foreign('payment_invoice_id')
                ->references('id')
                ->on('payment_invoices')
                ->nullOnDelete();

            $table->index(['ref', 'created_at'], 'idx_pwe_ref_created');
            $table->index(['channel_code', 'status', 'created_at'], 'idx_pwe_channel_status_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
