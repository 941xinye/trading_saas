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
        Schema::create('exchange_health_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exchange_id');
            $table->string('endpoint', 60);
            $table->integer('latency_ms')->nullable();
            $table->enum('status', ['up', 'degraded', 'down'])->default('up');
            $table->text('error')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table
                ->foreign('exchange_id')
                ->references('id')
                ->on('exchanges')
                ->cascadeOnDelete();

            $table->index(['exchange_id', 'checked_at'], 'idx_ehc_exchange_checked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_health_checks');
    }
};
