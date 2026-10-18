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
            $table->timestamp('copied_watermark')->default('1970-01-01 00:00:00');
            $table->timestamp('applied_watermark')->default('1970-01-01 00:00:00');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_watermarks');
    }
};
