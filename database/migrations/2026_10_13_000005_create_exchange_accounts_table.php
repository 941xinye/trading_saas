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
        Schema::create('exchange_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('exchange_id');
            $table->string('label', 60);
            $table->enum('market', ['spot', 'futures']);
            $table->string('sub_account', 60)->nullable();
            $table->text('encrypted_api_key');
            $table->text('encrypted_api_secret');
            $table->text('passphrase_encrypted')->nullable();
            $table->tinyInteger('dek_version')->default(1);
            $table->json('permissions');
            $table->enum('status', ['active', 'revoked', 'expired'])->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table
                ->foreign('exchange_id')
                ->references('id')
                ->on('exchanges')
                ->restrictOnDelete();

            $table->unique(['tenant_id', 'exchange_id', 'label'], 'uniq_ea_tenant_exchange_label');
            $table->index(['tenant_id', 'status'], 'idx_ea_tenant_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_accounts');
    }
};
