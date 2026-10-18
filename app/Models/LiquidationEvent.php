<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 平台级共享爆仓事件（P5 大户爆仓，无 tenant_id）。
 *
 * 既有 liquidation_events 表此前经 DB 直查访问（FeedRunner/Clusterer），
 * P8 归档补挂 Eloquent 模型（spec 004 / 纯增量，P1-P5 零改动）。
 */
class LiquidationEvent extends Model
{
    protected $fillable = [
        'exchange_code',
        'symbol',
        'side',
        'price',
        'qty',
        'value_usd',
        'event_ts',
        'exchange_event_id',
    ];

    protected $casts = [
        'price' => 'decimal:8',
        'qty' => 'decimal:8',
        'value_usd' => 'decimal:2',
        'event_ts' => 'datetime',
    ];
}
