<?php

declare(strict_types=1);

namespace App\Domain\Backtest\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 回测报告（与 run 1:1，completed 时单事务写入）。
 *
 * 金额列 decimal(20,8) + decimal:8 cast（读出 string，直接喂 bccomp/bcsub，浮点禁入）。
 * equity_curve 为 [[barTime_ms, equity_str], ...]，字符串保值。
 */
class BacktestReport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'backtest_run_id',
        'tenant_id',
        'initial_capital',
        'final_equity',
        'total_return_pct',
        'annualized_return_pct',
        'sharpe_ratio',
        'max_drawdown_pct',
        'win_rate_pct',
        'profit_factor',
        'trade_count',
        'total_fee',
        'bar_count',
        'data_coverage_pct',
        'equity_curve',
    ];

    protected $casts = [
        'initial_capital' => 'decimal:8',
        'final_equity' => 'decimal:8',
        'total_return_pct' => 'decimal:8',
        'annualized_return_pct' => 'decimal:8',
        'sharpe_ratio' => 'decimal:8',
        'max_drawdown_pct' => 'decimal:8',
        'win_rate_pct' => 'decimal:8',
        'profit_factor' => 'decimal:8',
        'total_fee' => 'decimal:8',
        'trade_count' => 'integer',
        'bar_count' => 'integer',
        'equity_curve' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'backtest_run_id');
    }
}
