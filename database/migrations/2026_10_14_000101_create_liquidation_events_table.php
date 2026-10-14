<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 大户爆仓：原始事件落库表。
 *
 * 用途：审计 / 回放 / 历史密集区重建（重启后 Redis 从 0 起算，本表保留 30 天）。
 * 高频写入：BTC 闪崩期 50-100 ev/s，POC 阶段单表 + 按月归档脚本。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidation_events', function (Blueprint $table) {
            $table->id();
            $table->string('exchange_code', 20);
            $table->string('symbol', 20);
            $table->string('side', 10);                 // BUY / SELL（Binance 原值大写）
            $table->decimal('price', 20, 8);             // 平均成交价
            $table->decimal('qty', 20, 8);               // 累计成交数量
            $table->decimal('value_usd', 20, 2);         // price × qty
            $table->dateTime('event_ts');               // Binance o.T（毫秒转 UTC datetime）
            $table->string('exchange_event_id', 64)->nullable()->unique(); // 可选幂等键
            $table->timestamps();

            $table->index(['symbol', 'event_ts'], 'idx_le_symbol_ts');
            $table->index(['symbol', 'side', 'event_ts'], 'idx_le_symbol_side_ts');
            $table->index(['exchange_code', 'event_ts'], 'idx_le_ex_ts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidation_events');
    }
};
