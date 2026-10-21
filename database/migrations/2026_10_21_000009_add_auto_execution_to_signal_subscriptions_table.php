<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 信号自动跟单：订阅级执行配置。
 *
 * auto_execute=1 时，Pipeline 落库新信号后投递 AutoExecuteSignalJob（orders 队列），
 * 由该 Job 复用 SignalExecutionService 的全部安全校验后下单。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_subscriptions', function (Blueprint $table) {
            $table->boolean('auto_execute')->default(false)->after('active');
            $table->unsignedBigInteger('execution_instance_id')->nullable()->after('auto_execute');
            $table->decimal('execution_notional', 20, 8)->nullable()->after('execution_instance_id');
            // 0 表示不按置信度过滤；取值 0-100
            $table->unsignedTinyInteger('execution_min_confidence')->default(0)->after('execution_notional');
            // 0 表示不限；按实例当日已执行的信号单计数
            $table->unsignedSmallInteger('execution_daily_limit')->default(20)->after('execution_min_confidence');

            $table->foreign('execution_instance_id')
                ->references('id')
                ->on('strategy_instances')
                ->nullOnDelete();

            $table->index(['tenant_id', 'auto_execute'], 'idx_ss_tenant_auto_execute');
        });
    }

    public function down(): void
    {
        Schema::table('signal_subscriptions', function (Blueprint $table) {
            $table->dropIndex('idx_ss_tenant_auto_execute');
            $table->dropForeign(['execution_instance_id']);
            $table->dropColumn([
                'auto_execute',
                'execution_instance_id',
                'execution_notional',
                'execution_min_confidence',
                'execution_daily_limit',
            ]);
        });
    }
};
