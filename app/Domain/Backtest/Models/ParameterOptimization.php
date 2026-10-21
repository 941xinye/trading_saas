<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Models;

use App\Domain\Backtest\Contracts\OptimizationStatus;
use App\Domain\Tenancy\BelongsToTenant;
use App\Models\StrategyInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 参数寻优任务（一次网格搜索 / walk-forward 的记录）。
 *
 * grid / assumptions 为发起时刻快照；报告与 trials 持久化后可二次查看，不重跑。
 */
class ParameterOptimization extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'strategy_code',
        'symbol',
        'interval',
        'from_utc',
        'to_utc',
        'mode',
        'folds',
        'objective',
        'grid',
        'base_params',
        'top_n',
        'assumptions',
        'status',
        'progress_pct',
        'best_params',
        'best_score',
        'report',
        'error_message',
        'started_at',
        'finished_at',
        'applied_instance_id',
        'applied_at',
    ];

    protected $casts = [
        'status' => OptimizationStatus::class,
        'grid' => 'array',
        'base_params' => 'array',
        'top_n' => 'integer',
        'assumptions' => 'array',
        'best_params' => 'array',
        'best_score' => 'decimal:8',
        'report' => 'array',
        'from_utc' => 'datetime',
        'to_utc' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'applied_instance_id' => 'integer',
        'applied_at' => 'datetime',
        'progress_pct' => 'integer',
        'folds' => 'integer',
    ];

    public function trials(): HasMany
    {
        return $this->hasMany(ParameterOptimizationTrial::class, 'optimization_id');
    }

    /**
     * 最优参数最近一次应用的策略实例（未应用时为 null）。
     */
    public function appliedInstance(): BelongsTo
    {
        return $this->belongsTo(StrategyInstance::class, 'applied_instance_id');
    }
}
