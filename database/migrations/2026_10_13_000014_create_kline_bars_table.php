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
        Schema::create('kline_bars', function (Blueprint $table) {
            $table->id();
            $table->string('exchange_code', 20);
            $table->string('symbol', 20);
            $table->string('interval', 5);
            $table->timestamp('open_time');
            $table->timestamp('close_time');
            $table->decimal('open', 20, 8);
            $table->decimal('high', 20, 8);
            $table->decimal('low', 20, 8);
            $table->decimal('close', 20, 8);
            $table->decimal('volume', 20, 8);
            $table->decimal('quote_volume', 20, 8);
            $table->integer('trades_count')->default(0);
            $table->timestamps();

            $table->unique(['exchange_code', 'symbol', 'interval', 'open_time'], 'uniq_kb_exchange_symbol_interval_open');
            $table->index(['symbol', 'interval', 'close_time'], 'idx_kb_symbol_interval_close');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kline_bars');
    }
};
