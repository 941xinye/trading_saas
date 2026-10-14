<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Risk\Breaker\BreakerStateMachine;
use App\Domain\Risk\Breaker\CircuitBreaker;
use App\Domain\Risk\Contracts\RiskGuard;
use App\Domain\Risk\RiskGuard as RiskGuardImpl;
use App\Domain\Risk\Rules\ConsecutiveLossRule;
use App\Domain\Risk\Rules\MaxDailyLossRule;
use App\Domain\Risk\Rules\MaxDrawdownRule;
use App\Domain\Risk\Rules\MaxLossPerTradeRule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 手动恢复熔断实例（risk:recover {instanceId}）。
 *
 * HALF_OPEN → CLOSED（探测窗口内人工确认恢复）。
 * CLOSED 状态幂等返回提示，不报错。
 * 写 circuit_breaker_events 审计行 action_taken='manual_resume'。
 */
class RiskRecoverCommand extends Command
{
    protected $signature = 'risk:recover {instanceId}';

    protected $description = 'Manually recover a circuit breaker instance (HALF_OPEN → CLOSED)';

    public function handle(): int
    {
        $instanceId = (int) $this->argument('instanceId');

        $instance = DB::table('strategy_instances')->where('id', $instanceId)->first();
        if ($instance === null) {
            $this->error("实例 #{$instanceId} 不存在");

            return self::FAILURE;
        }

        $tenantId = (int) $instance->tenant_id;
        $defaults = (array) config('risk.defaults');

        $guard = new RiskGuardImpl(
            [
                new MaxLossPerTradeRule((string) ($defaults['max_loss_per_trade']['threshold_value'] ?? 2)),
                new MaxDailyLossRule((string) ($defaults['max_daily_loss']['threshold_value'] ?? 5)),
                new MaxDrawdownRule((string) ($defaults['max_drawdown']['threshold_value'] ?? 10)),
                new ConsecutiveLossRule((int) ($defaults['consecutive_losses']['threshold_value'] ?? 5)),
            ],
            new CircuitBreaker(new BreakerStateMachine(), $tenantId, $instanceId),
            (string) $instance->allocated_capital,
            $tenantId,
            $instanceId,
        );

        $fromState = $guard->getBreakerState($instanceId);
        $recovered = $guard->attemptRecovery($instanceId);

        if (! $recovered) {
            $this->info("实例 #{$instanceId} 当前状态={$fromState}，无需恢复（幂等跳过）");

            return self::SUCCESS;
        }

        // 写手动恢复审计行
        DB::table('circuit_breaker_events')->insert([
            'tenant_id' => $tenantId,
            'strategy_instance_id' => $instanceId,
            'from_state' => $fromState,
            'to_state' => CircuitBreaker::STATE_CLOSED,
            'rule_type' => 'manual',
            'trigger_value' => 0,
            'threshold_value' => 0,
            'action_taken' => 'manual_resume',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("实例 #{$instanceId} 已恢复：{$fromState} → closed（manual_resume）");

        return self::SUCCESS;
    }
}
