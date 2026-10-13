<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 平台级交易所健康检查记录（沿用 AI-API 健康检查约定）。
 */
class ExchangeHealthCheck extends Model
{
    protected $fillable = [
        'exchange_id',
        'endpoint',
        'latency_ms',
        'status',
        'error',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'latency_ms' => 'integer',
    ];

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }
}
