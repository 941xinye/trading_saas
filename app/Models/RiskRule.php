<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 租户级风控规则（strategy_instance_id 为 null 表示租户级默认规则）。
 */
class RiskRule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'strategy_instance_id',
        'rule_type',
        'threshold_value',
        'threshold_unit',
        'cooldown_minutes',
        'auto_recover',
        'enabled',
    ];

    protected $casts = [
        'threshold_value' => 'decimal:4',
        'cooldown_minutes' => 'integer',
        'auto_recover' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function strategyInstance(): BelongsTo
    {
        return $this->belongsTo(StrategyInstance::class);
    }
}
