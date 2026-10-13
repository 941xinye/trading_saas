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
        Schema::create('exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique('uniq_exchanges_code');
            $table->string('name', 60);
            $table->string('base_url', 190);
            $table->string('ws_url', 190);
            $table->json('markets');
            $table->json('weight_limits')->nullable();
            $table->enum('status', ['active', 'degraded', 'down'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchanges');
    }
};
