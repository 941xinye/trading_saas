<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * 租户级用量计费事件（两阶段：frozen 预冻结 → settled 结算 / reversed 冲正）。
 */
class MeterEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'event_type',
        'quantity',
        'frozen_amount',
        'settled_amount',
        'status',
        'ref_id',
        'occurred_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'frozen_amount' => 'decimal:8',
        'settled_amount' => 'decimal:8',
        'occurred_at' => 'datetime',
    ];
}
