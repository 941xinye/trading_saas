<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 租户级 AI/规则兜底信号。
 */
class Signal extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'symbol',
        'interval',
        'direction',
        'entry_price',
        'exit_price',
        'stop_loss',
        'confidence',
        'source',
        'rationale',
        'raw_payload',
        'triggered_at',
    ];

    protected $casts = [
        'entry_price' => 'decimal:8',
        'exit_price' => 'decimal:8',
        'stop_loss' => 'decimal:8',
        'confidence' => 'decimal:2',
        'raw_payload' => 'array',
        'triggered_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SignalSubscription::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(SignalNotification::class);
    }
}
