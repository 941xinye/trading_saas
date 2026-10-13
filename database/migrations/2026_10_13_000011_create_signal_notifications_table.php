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
        Schema::create('signal_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('signal_id');
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
                ->foreign('signal_id')
                ->references('id')
                ->on('signals')
                ->cascadeOnDelete();

            $table->index(['signal_id', 'channel'], 'idx_sn_signal_channel');
            $table->index(['tenant_id', 'status', 'created_at'], 'idx_sn_tenant_status_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signal_notifications');
    }
};
