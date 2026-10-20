<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Models;

use App\Domain\Backtest\Contracts\BacktestRunStatus;
use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 回测任务（一次回测执行的记录，只进不退状态机）。
 *
 * params_snapshot / assumptions 为发起时刻快照：实例后续变更或 DSL 删除不影响进行中回测。
 * 终态不可逆；重新发起 = 新 run 行（历史报告不覆盖，FR-008）。
 */
class BacktestRun extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'strategy_instance_id',
        'strategy_code',
        'params_snapshot',
        'assumptions',
        'symbol',
        'interval',
        'from_utc',
        'to_utc',
        'status',
        'progress_pct',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'status' => BacktestRunStatus::class,
        'params_snapshot' => 'array',
        'assumptions' => 'array',
        'from_utc' => 'datetime',
        'to_utc' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'progress_pct' => 'integer',
    ];

    public function report(): HasOne
    {
        return $this->hasOne(BacktestReport::class, 'backtest_run_id');
    }

    public function simulatedTrades(): HasMany
    {
        return $this->hasMany(BacktestSimulatedTrade::class, 'backtest_run_id');
    }
}
