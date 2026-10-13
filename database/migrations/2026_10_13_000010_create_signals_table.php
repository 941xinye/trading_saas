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
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('subscription_id');
            $table->string('symbol', 20);
            $table->string('interval', 5);
            $table->enum('direction', ['long', 'short', 'neutral']);
            $table->decimal('entry_price', 20, 8);
            $table->decimal('exit_price', 20, 8);
            $table->decimal('stop_loss', 20, 8)->nullable();
            $table->decimal('confidence', 5, 2);
            $table->enum('source', ['ai', 'rule_fallback'])->default('ai');
            $table->text('rationale')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table
                ->foreign('subscription_id')
                ->references('id')
                ->on('signal_subscriptions')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'subscription_id', 'triggered_at'], 'idx_sig_tenant_sub_triggered');
            $table->index(['tenant_id', 'symbol', 'triggered_at'], 'idx_sig_tenant_symbol_triggered');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};
