<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P8 归档批次账目（spec 004 / data-model §2 / R-010）。
     *
     * 账目与主库数据同库，保证 apply 阶段「删除 + 水位推进」同事务（R-007 路由正确性根基）。
     * 账目行永不删除（FR-009 状态查询与幂等续跑的事实源）。
     * applied_at 只允许写入 verify_status='passed' 且 source_rows=archived_rows 的批次
     * （DB 层不设 CHECK，由 ArchivePipeline 事务内守卫 + 测试断言）。
     */
    public function up(): void
    {
        Schema::create('archive_batches', function (Blueprint $table) {
            $table->id();
            $table->string('data_type', 32);                 // kline_bar / signal / strategy_order / liquidation_event
            $table->timestamp('time_from');                  // 批次切片左界（含），UTC
            $table->timestamp('time_to');                    // 批次切片右界（不含），UTC
            $table->unsignedBigInteger('source_rows');       // copy 阶段主库实读行数
            $table->unsignedBigInteger('archived_rows')->default(0); // copy 阶段 CH 实写行数
            $table->string('verify_status', 16)->default('pending'); // 状态机：pending / copied / passed / failed（applied 由 applied_at 标记）
            $table->timestamp('applied_at')->nullable();     // 主库删除完成时间戳
            $table->text('failed_reason')->nullable();       // 最近一次失败原因
            $table->timestamps();

            $table->unique(['data_type', 'time_from', 'time_to'], 'uniq_batch_type_range');
            $table->index(['data_type', 'verify_status'], 'idx_batch_type_status');
            $table->index(['data_type', 'applied_at'], 'idx_batch_type_applied');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_batches');
    }
};
