<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Models;

use App\Domain\Tenancy\BelongsToTenant;
use App\Models\StrategyInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 寻优结果应用记录（append-only）。
 *
 * 每次「应用到实例」落一行：rank 为 Top N 名次（1 = best），
 * params_before/params_after 为应用前后实例完整参数快照，供回滚与审计。
 */
class ParameterOptimizationApplication extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'optimization_id',
        'strategy_instance_id',
        'rank',
        'params_before',
        'params_after',
        'applied_by',
        'rolled_back_at',
    ];

    protected $casts = [
        'optimization_id' => 'integer',
        'strategy_instance_id' => 'integer',
        'rank' => 'integer',
        'params_before' => 'array',
        'params_after' => 'array',
        'applied_by' => 'integer',
        'rolled_back_at' => 'datetime',
    ];

    public function optimization(): BelongsTo
    {
        return $this->belongsTo(ParameterOptimization::class, 'optimization_id');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(StrategyInstance::class, 'strategy_instance_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
