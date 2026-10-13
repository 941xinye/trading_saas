<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 租户级策略运行实例（核心运行态：状态/熔断/PnL/快照）。
 */
class StrategyInstance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'strategy_id',
        'exchange_account_id',
        'symbol',
        'interval',
        'params',
        'allocated_capital',
        'leverage',
        'status',
        'breaker_state',
        'position_snapshot',
        'realized_pnl',
        'unrealized_pnl',
        'max_drawdown_pct',
        'consecutive_losses',
        'started_at',
        'stopped_at',
    ];

    protected $casts = [
        'params' => 'array',
        'position_snapshot' => 'array',
        'allocated_capital' => 'decimal:8',
        'realized_pnl' => 'decimal:8',
        'unrealized_pnl' => 'decimal:8',
        'max_drawdown_pct' => 'decimal:2',
        'consecutive_losses' => 'integer',
        'leverage' => 'integer',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    public function exchangeAccount(): BelongsTo
    {
        return $this->belongsTo(ExchangeAccount::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(StrategyOrder::class);
    }

    public function riskRules(): HasMany
    {
        return $this->hasMany(RiskRule::class);
    }

    public function circuitBreakerEvents(): HasMany
    {
        return $this->hasMany(CircuitBreakerEvent::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
