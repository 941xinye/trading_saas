<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 租户级缓存持仓（从交易所返回的实时持仓状态镜像）。
 */
class Position extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'exchange_account_id',
        'strategy_instance_id',
        'symbol',
        'side',
        'size',
        'entry_price',
        'mark_price',
        'liquidation_price',
        'leverage',
        'unrealized_pnl',
        'margin',
    ];

    protected $casts = [
        'size' => 'decimal:8',
        'entry_price' => 'decimal:8',
        'mark_price' => 'decimal:8',
        'liquidation_price' => 'decimal:8',
        'leverage' => 'integer',
        'unrealized_pnl' => 'decimal:8',
        'margin' => 'decimal:8',
    ];

    public function exchangeAccount(): BelongsTo
    {
        return $this->belongsTo(ExchangeAccount::class);
    }

    public function strategyInstance(): BelongsTo
    {
        return $this->belongsTo(StrategyInstance::class);
    }
}
