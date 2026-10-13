<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 租户级策略订单（client_order_id 用于幂等）。
 */
class StrategyOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'strategy_instance_id',
        'exchange_order_id',
        'client_order_id',
        'symbol',
        'side',
        'type',
        'quantity',
        'price',
        'stop_price',
        'leverage',
        'reduce_only',
        'status',
        'filled_qty',
        'avg_fill_price',
        'fee',
        'error_message',
        'placed_at',
        'filled_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'price' => 'decimal:8',
        'stop_price' => 'decimal:8',
        'leverage' => 'integer',
        'reduce_only' => 'boolean',
        'filled_qty' => 'decimal:8',
        'avg_fill_price' => 'decimal:8',
        'fee' => 'decimal:8',
        'placed_at' => 'datetime',
        'filled_at' => 'datetime',
    ];

    public function strategyInstance(): BelongsTo
    {
        return $this->belongsTo(StrategyInstance::class);
    }
}
