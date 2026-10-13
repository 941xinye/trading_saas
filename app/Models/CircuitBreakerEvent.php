<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 租户级熔断状态机审计事件（仅业务亏损触发，API 异常不记录）。
 */
class CircuitBreakerEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'strategy_instance_id',
        'from_state',
        'to_state',
        'rule_type',
        'trigger_value',
        'threshold_value',
        'action_taken',
        'occurred_at',
    ];

    protected $casts = [
        'trigger_value' => 'decimal:4',
        'threshold_value' => 'decimal:4',
        'occurred_at' => 'datetime',
    ];

    public function strategyInstance(): BelongsTo
    {
        return $this->belongsTo(StrategyInstance::class);
    }
}
