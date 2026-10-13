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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 60)->unique('uniq_tenants_slug');
            $table->text('exchange_dek');
            $table->enum('status', ['active', 'suspended', 'canceled'])->default('active');
            $table->string('plan_code', 30)->default('free');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'plan_code'], 'idx_tenants_status_plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
