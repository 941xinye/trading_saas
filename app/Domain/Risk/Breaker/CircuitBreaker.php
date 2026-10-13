<?php

declare(strict_types=1);

namespace App\Domain\Risk\Breaker;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * 熔断状态机。
 *
 * 三态转换：
 *   CLOSED ─触发─► OPEN ─冷却到─► HALF_OPEN ─探测盈利─► CLOSED
 *                                       └─探测亏损─► OPEN（重置计时）
 *
 * 状态存储：Redis Hash `tenant:{tid}:breaker:{iid}`
 * 字段：state / opened_at / triggered_rule / triggered_value / attempts / next_attempt_at
 *
 * 关键约束：
 *  - 进程崩溃恢复从 Redis 读状态，无丢失
 *  - 冷却窗口记 next_attempt_at 时间戳（Redis TTL 无法承载多字段语义），
 *    attemptProbe 时对比 now 判断是否放行探测单
 *  - 仅业务亏损触发熔断，API 异常不触发（count_api_errors=false）
 *  - trip/recover 同步 best-effort 写 circuit_breaker_events 审计 + strategy_instances 状态
 */
class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private readonly BreakerStateMachine $stateMachine,
        private readonly int $tenantId,
        private readonly int $instanceId,
    ) {
    }

    /**
     * 触发熔断：CLOSED/HALF_OPEN → OPEN。
     *
     * @param string $ruleType     触发的规则类型（max_loss_per_trade|max_daily_loss|max_drawdown|consecutive_losses）
     * @param string $triggerValue 触发时的指标值（%）
     * @param string $thresholdValue 规则阈值（%）
     */
    public function trip(string $ruleType, string $triggerValue = '0', string $thresholdValue = '0'): void
    {
        $from = $this->state();
        $this->stateMachine->assertTransition($from, self::STATE_OPEN);

        $now = time();
        $cooldownMinutes = (int) config(
            "risk.defaults.{$ruleType}.cooldown_minutes",
            (int) config('risk.defaults.max_loss_per_trade.cooldown_minutes', 60),
        );

        $this->redis()->hMset($this->key(), [
            'state' => self::STATE_OPEN,
            'opened_at' => $now,
            'triggered_rule' => $ruleType,
            'triggered_value' => $triggerValue,
            'attempts' => 0,
            'next_attempt_at' => $now + $cooldownMinutes * 60,
        ]);

        $this->persistEvent($from, self::STATE_OPEN, $ruleType, $triggerValue, $thresholdValue, 'pause_strategy');
        $this->syncInstanceRow(self::STATE_OPEN, 'paused_by_breaker');
    }

    /**
     * HALF_OPEN 状态下放行探测单。
     *
     * @return bool true=允许放行（冷却窗口已到），false=仍在冷却中
     */
    public function attemptProbe(): bool
    {
        $state = $this->state();

        if ($state === self::STATE_OPEN) {
            $next = (int) ($this->redis()->hGet($this->key(), 'next_attempt_at') ?: 0);
            if (time() < $next) {
                return false;
            }

            // 冷却窗口到期：OPEN → HALF_OPEN，消耗一个探测名额
            $this->stateMachine->assertTransition(self::STATE_OPEN, self::STATE_HALF_OPEN);
            $this->redis()->hIncrBy($this->key(), 'attempts', 1);
            $this->redis()->hSet($this->key(), 'state', self::STATE_HALF_OPEN);
            $this->syncInstanceRow(self::STATE_HALF_OPEN, 'paused_by_breaker');

            return true;
        }

        if ($state === self::STATE_HALF_OPEN) {
            $attempts = (int) ($this->redis()->hGet($this->key(), 'attempts') ?: 0);
            $probeCount = (int) config('risk.breaker.half_open_probe_count', 1);
            if ($attempts >= $probeCount) {
                return false; // 探测名额已用完，等待探测单成交回调
            }

            $this->redis()->hIncrBy($this->key(), 'attempts', 1);

            return true;
        }

        return false; // CLOSED 状态无探测语义
    }

    /**
     * 探测成功：HALF_OPEN → CLOSED。
     */
    public function recover(): void
    {
        $from = $this->state();
        if ($from === self::STATE_CLOSED) {
            return;
        }

        $this->stateMachine->assertTransition($from, self::STATE_CLOSED);

        $this->redis()->hMset($this->key(), [
            'state' => self::STATE_CLOSED,
            'opened_at' => 0,
            'triggered_rule' => '',
            'triggered_value' => '0',
            'attempts' => 0,
            'next_attempt_at' => 0,
        ]);

        $this->persistEvent($from, self::STATE_CLOSED, 'recovered', '0', '0', 'resume_strategy');
        $this->syncInstanceRow(self::STATE_CLOSED, 'running');
    }

    /**
     * 当前状态。
     *
     * @return string closed|open|half_open
     */
    public function state(): string
    {
        $state = $this->redis()->hGet($this->key(), 'state');

        return is_string($state) && $state !== '' ? $state : self::STATE_CLOSED;
    }

    /**
     * 触发信息（审计展示用）。
     *
     * @return array{rule:string,value:string,opened_at:int,next_attempt_at:int}
     */
    public function triggerInfo(): array
    {
        $hash = $this->redis()->hGetAll($this->key());

        return [
            'rule' => (string) ($hash['triggered_rule'] ?? ''),
            'value' => (string) ($hash['triggered_value'] ?? '0'),
            'opened_at' => (int) ($hash['opened_at'] ?? 0),
            'next_attempt_at' => (int) ($hash['next_attempt_at'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    private function redis(): \Illuminate\Redis\Connections\Connection
    {
        return Redis::connection('default');
    }

    private function key(): string
    {
        return str_replace(
            ['{tenant_id}', '{instance_id}'],
            [$this->tenantId, $this->instanceId],
            (string) config('risk.breaker.key_prefix', 'tenant:{tenant_id}:breaker:{instance_id}'),
        );
    }

    /**
     * 审计事件落库（best-effort：审计失败不阻塞熔断动作）。
     */
    private function persistEvent(
        string $from,
        string $to,
        string $ruleType,
        string $triggerValue,
        string $thresholdValue,
        string $action,
    ): void {
        try {
            DB::table('circuit_breaker_events')->insert([
                'tenant_id' => $this->tenantId,
                'strategy_instance_id' => $this->instanceId,
                'from_state' => $from,
                'to_state' => $to,
                'rule_type' => mb_substr($ruleType, 0, 40),
                'trigger_value' => round((float) $triggerValue, 4),
                'threshold_value' => round((float) $thresholdValue, 4),
                'action_taken' => $action,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('circuit_breaker_events insert failed', [
                'instance' => $this->instanceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 同步 strategy_instances 状态（best-effort）。
     */
    private function syncInstanceRow(string $breakerState, string $status): void
    {
        try {
            DB::table('strategy_instances')
                ->where('id', $this->instanceId)
                ->where('tenant_id', $this->tenantId)
                ->update([
                    'breaker_state' => $breakerState,
                    'status' => $status,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('strategy_instances breaker sync failed', [
                'instance' => $this->instanceId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
