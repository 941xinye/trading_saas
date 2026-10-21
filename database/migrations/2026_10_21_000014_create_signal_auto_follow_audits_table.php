<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自动跟单决策审计（FR-028）。
 *
 * 每次入队 / 跳过 / 拒绝 / 成交 / 异常落一行，支持按信号或订阅回放完整决策链；
 * append-only（仅 created_at），不参与业务读写。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_auto_follow_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('signal_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('instance_id')->nullable();
            $table->string('decision', 16);
            $table->string('reason', 255)->nullable();
            $table->string('client_order_id', 60)->nullable();
            $table->decimal('notional', 20, 8)->nullable();
            $table->decimal('reference_price', 20, 12)->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'signal_id'], 'idx_safa_tenant_signal');
            $table->index(['tenant_id', 'subscription_id'], 'idx_safa_tenant_subscription');
            $table->index(['tenant_id', 'created_at'], 'idx_safa_tenant_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_auto_follow_audits');
    }
};
