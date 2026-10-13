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
        Schema::create('signal_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 60);
            $table->json('symbols');
            $table->json('intervals');
            $table->string('ai_prompter', 30)->default('deepseek');
            $table->string('ai_model', 40)->nullable();
            $table->boolean('rule_fallback_enabled')->default(true);
            $table->json('channels');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->index(['tenant_id', 'active'], 'idx_ss_tenant_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signal_subscriptions');
    }
};
