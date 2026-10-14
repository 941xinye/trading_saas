<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 大户爆仓：聚类桶快照表。
 *
 * 用途：每 N 秒把 Redis HASH 桶聚合快照写入本表，重启后历史密集区回放。
 * 与 liquidation_events 区别：本表是聚合桶级数据（按 symbol+side+window+bucket），
 * 不存单事件；体量小（月级 < 100MB），可永久保留。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidation_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('exchange_code', 20);
            $table->string('symbol', 20);
            $table->string('side', 10);
            $table->string('window', 5);                 // 5m / 15m / 60m
            $table->decimal('bucket_center', 20, 8);
            $table->decimal('price_from', 20, 8);
            $table->decimal('price_to', 20, 8);
            $table->decimal('total_qty', 20, 8);
            $table->integer('event_count');
            $table->decimal('top_qty', 20, 8)->nullable(); // 单事件最大 qty（暂留字段，POC 阶段未填）
            $table->dateTime('snapshot_ts');
            $table->timestamps();

            // 同一桶同一时刻只允许一条快照（updateOrInsert 幂等）
            $table->unique(
                ['exchange_code', 'symbol', 'side', 'window', 'bucket_center', 'snapshot_ts'],
                'uniq_lc_bucket_snapshot',
            );
            $table->index(['symbol', 'window', 'snapshot_ts'], 'idx_lc_sym_win_ts');
            $table->index(['symbol', 'side', 'window', 'snapshot_ts'], 'idx_lc_sym_side_win_ts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidation_clusters');
    }
};
