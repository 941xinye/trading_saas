<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 参数寻优单次试跑（append-only）。
 *
 * 每个组合每次评估一行：fold_index=null 表示 grid 全区间；walk_forward 下
 * in_sample 区分训练段与样本外段。失败组合落 status=failed，不中断整体寻优。
 */
class ParameterOptimizationTrial extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * append-only：创建时显式落 created_at（sqlite :memory: 下 useCurrent 不可移植）。
     */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ($model->getAttribute('created_at') === null) {
                $model->setAttribute('created_at', $model->freshTimestamp());
            }
        });
    }

    protected $fillable = [
        'optimization_id',
        'tenant_id',
        'fold_index',
        'params',
        'in_sample',
        'status',
        'score',
        'metrics',
        'error_message',
        'created_at',
    ];

    protected $casts = [
        'fold_index' => 'integer',
        'params' => 'array',
        'in_sample' => 'boolean',
        'score' => 'decimal:8',
        'metrics' => 'array',
        'created_at' => 'datetime',
    ];

    public function optimization(): BelongsTo
    {
        return $this->belongsTo(ParameterOptimization::class, 'optimization_id');
    }
}
