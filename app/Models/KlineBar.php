<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 平台级共享行情 K 线（多租户订阅去重，无 tenant_id）。
 */
class KlineBar extends Model
{
    protected $fillable = [
        'exchange_code',
        'symbol',
        'interval',
        'open_time',
        'close_time',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'quote_volume',
        'trades_count',
    ];

    protected $casts = [
        'open_time' => 'datetime',
        'close_time' => 'datetime',
        'open' => 'decimal:8',
        'high' => 'decimal:8',
        'low' => 'decimal:8',
        'close' => 'decimal:8',
        'volume' => 'decimal:8',
        'quote_volume' => 'decimal:8',
        'trades_count' => 'integer',
    ];
}
