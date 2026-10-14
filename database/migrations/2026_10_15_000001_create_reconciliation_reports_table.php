<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 账实对账报告（P5.5 步骤 1）。
 *
 * 报告只追加、不可变语义（同周期重跑走 upsert，保证每周期每实例至多一行）；
 * 对账只报告 + 暂停，绝不自动改账/补单/平仓。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('strategy_instance_id');
            $table->unsignedBigInteger('exchange_account_id');
            // ok=容差内；drift=容差外（通常为交易所多币）；critical=交易所少币或超严重阈值；unknown=取数失败
            $table->enum('status', ['ok', 'drift', 'critical', 'unknown'])->default('unknown');
            $table->json('diff_json')->nullable();
            // UTC 周期桶，如 20261015T0800（5 分钟桶）
            $table->string('period_key', 16);
            $table->timestamp('checked_at');
            $table->timestamps();

            $table
                ->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table
                ->foreign('strategy_instance_id')
                ->references('id')
                ->on('strategy_instances')
                ->cascadeOnDelete();
            $table
                ->foreign('exchange_account_id')
                ->references('id')
                ->on('exchange_accounts')
                ->cascadeOnDelete();

            // 同租户同实例同周期至多一行（幂等锚点）
            $table->unique(
                ['tenant_id', 'strategy_instance_id', 'period_key'],
                'uniq_rr_tenant_instance_period',
            );
            $table->index(
                ['tenant_id', 'strategy_instance_id', 'checked_at'],
                'idx_rr_tenant_instance_checked',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_reports');
    }
};
