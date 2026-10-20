<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 模拟交易明细（回测产生的单笔模拟成交，append-only）。
 *
 * 无 updated_at（$timestamps=false + 手工 created_at）；与实盘 strategy_orders 无关（FR 边界）。
 * 数量/价格 decimal(30,12) 读出 string；seq 为 run 内顺序号（等价性对比锚点）。
 */
class BacktestSimulatedTrade extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * append-only：Eloquent 不自动维护时间戳；创建时显式落 created_at
     * （DB 层 useCurrent 默认值在 sqlite :memory: 下不可移植，故模型层兜底）。
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
        'backtest_run_id',
        'tenant_id',
        'seq',
        'bar_open_time',
        'side',
        'qty',
        'price',
        'fee',
        'realized_pnl',
        'created_at',
    ];

    protected $casts = [
        'seq' => 'integer',
        'bar_open_time' => 'integer',
        'qty' => 'decimal:12',
        'price' => 'decimal:12',
        'fee' => 'decimal:8',
        'realized_pnl' => 'decimal:8',
        'created_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
