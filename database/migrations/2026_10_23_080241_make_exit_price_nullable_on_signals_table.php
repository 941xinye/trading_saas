<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 2026-10-23 防御性变更：exit_price 语义上可能「未知」（AI 返回方向矛盾价位被丢弃、neutral 信号、
     * 或者未来引入不确定止盈的策略源），改为 nullable 与 stop_loss 对齐，避免 NOT NULL 列被强行
     * 塞占位值时产生语义误导。
     */
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->decimal('exit_price', 20, 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table) {
            $table->decimal('exit_price', 20, 8)->nullable(false)->change();
        });
    }
};
