<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 仓位风控事件审计表（spec 005 US2/US3）。
 *
 * 记录仓位级风控动作：预警 / 减仓 / 紧急全平。
 * 与 circuit_breaker_events（账户级）正交，独立审计。
 *
 * 索引策略：
 *  - (tenant_id, strategy_instance_id, occurred_at)：实例维度时序查询
 *  - (tenant_id, symbol, occurred_at)：交易对维度时序查询
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('position_risk_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('strategy_instance_id');
            $table->string('symbol', 20);
            $table->enum('position_side', ['long', 'short']);
            $table->enum('action_type', ['warning', 'reduce', 'emergency_close']);
            $table->string('trigger_reason', 40);
            $table->decimal('margin_ratio', 10, 6)->comment('触发时保证金率');
            $table->decimal('mark_price', 20, 8);
            $table->decimal('liquidation_price', 20, 8)->nullable();
            $table->json('position_before')->comment('动作前仓位快照 {size,entry_price,unrealized_pnl}');
            $table->json('position_after')->nullable()->comment('动作后仓位快照（全平时为 null）');
            $table->string('order_id', 64)->nullable()->comment('关联 strategy_orders.client_order_id');
            $table->enum('status', ['pending', 'executed', 'failed', 'skipped'])->default('pending');
            $table->string('error_message', 500)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('strategy_instance_id')->references('id')->on('strategy_instances')->cascadeOnDelete();

            $table->index(['tenant_id', 'strategy_instance_id', 'occurred_at'], 'idx_pre_tenant_instance');
            $table->index(['tenant_id', 'symbol', 'occurred_at'], 'idx_pre_tenant_symbol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('position_risk_events');
    }
};
