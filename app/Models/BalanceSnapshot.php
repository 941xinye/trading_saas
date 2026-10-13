<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 租户级账户余额快照（定时任务每 60s 拉取一次，用于回撤计算与面板展示）。
 */
class BalanceSnapshot extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'exchange_account_id',
        'snapshot_at',
        'total_equity_usdt',
        'total_margin_usdt',
        'available_usdt',
        'unrealized_pnl',
        'assets',
    ];

    protected $casts = [
        'snapshot_at' => 'datetime',
        'total_equity_usdt' => 'decimal:8',
        'total_margin_usdt' => 'decimal:8',
        'available_usdt' => 'decimal:8',
        'unrealized_pnl' => 'decimal:8',
        'assets' => 'array',
    ];

    public function exchangeAccount(): BelongsTo
    {
        return $this->belongsTo(ExchangeAccount::class);
    }
}
