<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Risk\Breaker\BreakerStateMachine;
use App\Domain\Risk\Breaker\CircuitBreaker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 查看熔断实例状态（risk:status {instanceId}）。
 *
 * 打印：breaker state / triggerInfo / 最近 5 条 circuit_breaker_events 审计。
 */
class RiskStatusCommand extends Command
{
    protected $signature = 'risk:status {instanceId}';

    protected $description = 'Show circuit breaker status for a strategy instance';

    public function handle(): int
    {
        $instanceId = (int) $this->argument('instanceId');

        $instance = DB::table('strategy_instances')->where('id', $instanceId)->first();
        if ($instance === null) {
            $this->error("实例 #{$instanceId} 不存在");

            return self::FAILURE;
        }

        $tenantId = (int) $instance->tenant_id;
        $breaker = new CircuitBreaker(new BreakerStateMachine(), $tenantId, $instanceId);

        $state = $breaker->state();
        $info = $breaker->triggerInfo();

        $this->info("=== 实例 #{$instanceId} 熔断状态 ===");
        $this->line("  state:            {$state}");
        $this->line("  triggered_rule:   {$info['rule']}");
        $this->line("  triggered_value:  {$info['value']}");
        $this->line("  opened_at:        " . ($info['opened_at'] > 0 ? gmdate('Y-m-d H:i:s', $info['opened_at']) . ' UTC' : '-'));
        $this->line("  next_attempt_at:  " . ($info['next_attempt_at'] > 0 ? gmdate('Y-m-d H:i:s', $info['next_attempt_at']) . ' UTC' : '-'));
        $this->line("  last_trade_date:  " . ($breaker->getLastTradeDate() ?: '-'));

        $this->newLine();
        $this->info('--- 最近 5 条审计 ---');

        $events = DB::table('circuit_breaker_events')
            ->where('strategy_instance_id', $instanceId)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'from_state', 'to_state', 'rule_type', 'trigger_value', 'action_taken', 'occurred_at']);

        if ($events->isEmpty()) {
            $this->line('  （无审计记录）');
        } else {
            foreach ($events as $e) {
                $this->line(sprintf(
                    '  #%d  %s→%s  rule=%s  val=%s  action=%s  %s',
                    $e->id,
                    $e->from_state,
                    $e->to_state,
                    $e->rule_type,
                    $e->trigger_value,
                    $e->action_taken ?? '-',
                    $e->occurred_at,
                ));
            }
        }

        return self::SUCCESS;
    }
}
