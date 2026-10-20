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
        Schema::create('payment_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('ref', 64)->unique();
            $table->string('channel_code', 30);
            $table->string('channel_invoice_id', 120)->nullable();
            $table->string('plan_code', 30);
            $table->string('currency', 10)->default('USDT');
            $table->string('chain', 10)->default('TRC20');
            $table->decimal('amount_usdt', 20, 8);
            $table->decimal('paid_amount_usdt', 20, 8)->nullable();
            $table->string('pay_address', 120)->nullable();
            $table->string('pay_uri', 255)->nullable();
            $table->enum('status', ['pending', 'processing', 'partial', 'confirmed', 'expired', 'manual'])->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'status'], 'idx_pi_tenant_status');
            $table->index(['tenant_id', 'plan_code', 'status'], 'idx_pi_tenant_plan_status');
            $table->index(['channel_invoice_id'], 'idx_pi_channel_invoice');
            $table->index(['status', 'expires_at'], 'idx_pi_status_expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_invoices');
    }
};
