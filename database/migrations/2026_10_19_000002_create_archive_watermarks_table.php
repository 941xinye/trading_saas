<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P8 归档水位（spec 004 / data-model §2 / R-010）。
     *
     * copied_watermark：最大 copied 批次 time_to（进度展示）；
     * applied_watermark：最大 applied 批次 time_to（HistoricalQuery 冷热路由基准，R-007）。
     * 两水位只增不减，与批次完成同事务推进。
     */
    public function up(): void
    {
        Schema::create('archive_watermarks', function (Blueprint $table) {
            $table->id();
            $table->string('data_type', 32)->unique();
            // dateTime 而非 timestamp：MySQL TIMESTAMP 下界 '1970-01-01 00:00:01' UTC 且按会话时区换算，
            // epoch 默认在 UTC+8 会话被拒（T031 实测 1067）；DATETIME 无时区换算、字面量原样存储
            $table->dateTime('copied_watermark')->default('1970-01-01 00:00:00');
            $table->dateTime('applied_watermark')->default('1970-01-01 00:00:00');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_watermarks');
    }
};
